<?php

namespace App\Finance\Actions;

use App\Finance\Enums\GatewaySettlementOutcome;
use App\Finance\Enums\GatewayTransactionStatus;
use App\Finance\Enums\LedgerEntryType;
use App\Finance\Exceptions\GatewayClaimLost;
use App\Finance\Models\GatewayTransaction;
use App\Finance\Models\GatewayTransactionEvent;
use App\Finance\Models\Payment;
use App\Finance\Services\GatewayEventRedactor;
use App\Finance\Services\SettlementBankAccount;
use App\Finance\Services\SubledgerPoster;
use App\Support\Money;
use App\Support\Sequences\Sequences;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Turns a provider delivery into, at most, one Payment.
 *
 * ── THE TWO TRANSACTIONS, AND WHY THEY ARE TWO ──
 *
 * T1 writes the delivery and COMMITS. T2 then claims the transaction and writes the payment,
 * atomically. They are deliberately not one transaction, and the reason is what happens when the
 * second one loses:
 *
 *   · One transaction: a delivery that loses the claim, or fails anywhere in the settlement, rolls
 *     back the EVENT ROW WITH IT. The system then holds no record that the provider ever said
 *     anything — the exact case where the record is most wanted, because something went wrong.
 *   · Two transactions: the evidence survives the outcome. Every delivery is on file whether it
 *     settled, lost a race, arrived twice, or arrived for a transaction already settled.
 *
 * EVIDENCE FIRST, EFFECT SECOND. The cost is that a crash between T1 and T2 leaves an event with no
 * payment — which is the recoverable direction, and is precisely what step 7's discrepancy report
 * is for. The opposite failure, a payment with no evidence, is not recoverable by any report.
 *
 * ── THE CLAIM IS A COMPARE-AND-SWAP, NOT A READ-THEN-WRITE ──
 *
 * Paystack retries deliveries, and the return-from-checkout verify can land on the same transaction
 * at the same moment. Two concurrent settlements of one transaction would produce two Payments
 * against one invoice — real money, counted twice.
 *
 * `UNIQUE (payment_id)` does NOT close this on its own: it stops two ROWS naming one payment, and
 * says nothing about one row being claimed twice with two different payments. What closes it is the
 * UPDATE's own `WHERE payment_id IS NULL` and the assertion that it affected exactly one row. The
 * row lock in front serialises the contenders; the affected-row count is what makes the loser KNOW
 * it lost rather than assume it won.
 *
 * That predicate is only sound because `payment_id` is a ONE-WAY DOOR — the write-once arm of
 * `finance_gateway_transactions_update_guard` refuses value → NULL. Without it a replay could
 * unlink and relink, and the compare-and-swap would hand out a second settlement while reading as
 * correct.
 */
final class SettleGatewayTransaction
{
    /**
     * The ONE event this system settles on. Named as a constant so the set is visible as a set.
     */
    public const SETTLING_EVENT = 'charge.success';

    public function __construct(
        private readonly SubledgerPoster $ledger,
        private readonly SettlementBankAccount $settlementAccount,
        private readonly GatewayEventRedactor $redactor,
    ) {}

    /**
     * The whole sequence for one delivery: record it, then decide about it.
     *
     * THE ORDER IS THE POINT and it lives here rather than in the controller, because the reason
     * for it is the reason documented on this class. A caller that recorded the delivery only for
     * outcomes it liked would produce exactly the gap T1/T2 exists to close, and would do so while
     * looking perfectly reasonable at the call site.
     *
     * @param  array<string, mixed>  $body  the full webhook body, as delivered
     */
    public function handle(GatewayTransaction $transaction, string $source, ?string $event, array $body): GatewaySettlementOutcome
    {
        // T1 — unconditional, and committed before T2 opens.
        $this->recordDelivery($transaction, $source, $event, $body);

        if ($event !== self::SETTLING_EVENT) {
            return GatewaySettlementOutcome::NotASettlementEvent;
        }

        try {
            return $this->settle($transaction, is_array($body['data'] ?? null) ? $body['data'] : []);
        } catch (GatewayClaimLost) {
            // The race resolved and this delivery lost; its payment and ledger entry are rolled
            // back and the winner's stand. Indistinguishable, correctly, from arriving second.
            return GatewaySettlementOutcome::AlreadySettled;
        }
    }

    /**
     * T1 — record the delivery, on its own transaction, committed before anything is decided.
     *
     * @param  array<string, mixed>  $body
     */
    public function recordDelivery(GatewayTransaction $transaction, string $source, ?string $event, array $body): GatewayTransactionEvent
    {
        [$payload, $stripped] = $this->redactor->strip($body);

        return DB::transaction(fn () => GatewayTransactionEvent::create([
            'school_id' => $transaction->school_id,
            'gateway_transaction_id' => $transaction->getKey(),
            'source' => $source,
            'event' => $event,
            'payload' => $payload,
            'redacted_fields' => $stripped === [] ? null : $stripped,
        ]));
    }

    /**
     * T2 — claim the transaction and write the payment. Atomic, and idempotent under replay.
     *
     * Returns the outcome rather than throwing on a lost claim: losing is a NORMAL result here, not
     * an error. A duplicate delivery is Paystack behaving as documented, and the caller answers 200
     * to it either way — a webhook that 500s on its own retry teaches the provider to retry harder.
     *
     * @param  array<string, mixed>  $body  the provider's `data` object
     */
    public function settle(GatewayTransaction $transaction, array $body): GatewaySettlementOutcome
    {
        $fee = $this->reportedFee($body, $transaction->amount->currency);

        // §7's fifth case: the provider says success but does not say what it took. The net amount
        // is unknowable, so nothing is written and the row stays `pending` for the discrepancy
        // report to find. Answering 200 is correct — the delivery is not malformed and retrying it
        // will not produce a fee. What is missing is a FACT, not a message.
        if ($fee === null) {
            return GatewaySettlementOutcome::FeeNotReported;
        }

        $net = $transaction->amount->minus($fee);

        if ($net->isZero() || $net->isNegative()) {
            return GatewaySettlementOutcome::FeeExceedsAmount;
        }

        return DB::transaction(function () use ($transaction, $body, $fee, $net) {
            $locked = DB::selectOne(
                'SELECT id, payment_id FROM finance_gateway_transactions WHERE id = ? FOR UPDATE',
                [$transaction->getKey()],
            );

            if ($locked === null) {
                return GatewaySettlementOutcome::Unknown;
            }

            if ($locked->payment_id !== null) {
                return GatewaySettlementOutcome::AlreadySettled;
            }

            $payment = $this->writePayment($transaction, $body, $net);

            // THE COMPARE-AND-SWAP. `payment_id IS NULL` repeated here is not redundant with the
            // check above: the lock makes them equivalent today, and this is what still holds if a
            // future caller reaches this method without one. The count is asserted, not assumed.
            $claimed = DB::update(
                'UPDATE finance_gateway_transactions
                    SET payment_id = ?, status = ?, paid_at = ?, provider_reference = ?,
                        fee_minor = ?, fee_currency = ?, updated_at = ?
                  WHERE id = ? AND payment_id IS NULL',
                [
                    $payment->getKey(),
                    GatewayTransactionStatus::Success->value,
                    $this->paidAt($body)?->utc(),
                    $body['id'] ?? null,
                    $fee->toKobo(),
                    $fee->currency,
                    now(),
                    $transaction->getKey(),
                ],
            );

            if ($claimed !== 1) {
                // The loser's path. Throwing rolls back the payment and the ledger entry written
                // moments ago — which is the point: a payment that did not win the claim must not
                // survive, or the invoice is settled twice by rows nothing links together.
                throw new GatewayClaimLost(
                    "Gateway transaction #{$transaction->getKey()} was claimed by another delivery "
                    .'between the lock and the swap; this settlement is rolled back.'
                );
            }

            return GatewaySettlementOutcome::Settled;
        });
    }

    /**
     * The fee the provider reports it took, in minor units.
     *
     * MEASURED, NEVER RECOMPUTED. The fee formula is known exactly (1.5% + ₦100, flat waived under
     * ₦2,500, measured on the gross) and confirmed against live sandbox charges — and it is still
     * not what belongs here. A recomputed fee is our ARITHMETIC; `data.fees` is what Paystack
     * actually deducted. When those disagree, the disagreement is the finding step 7 exists to
     * surface, and recomputing would erase it by construction.
     *
     * NULL means "not reported", never zero. A zero fee is a claim that the provider took nothing.
     */
    private function reportedFee(array $body, string $currency): ?Money
    {
        if (! array_key_exists('fees', $body) || ! is_numeric($body['fees'])) {
            return null;
        }

        return Money::fromKobo((int) $body['fees'], $currency);
    }

    private function writePayment(GatewayTransaction $transaction, array $body, Money $net): Payment
    {
        $schoolId = (int) $transaction->school_id;
        $invoice = $transaction->invoice()->firstOrFail();
        $studentId = (int) $invoice->student_id;
        $receivedAt = $this->receivedAt($body);

        $payment = Payment::create([
            'school_id' => $schoolId,
            'student_id' => $studentId,
            'reference' => Sequences::next('finance_payment', (string) $schoolId),
            'amount' => $net,
            'payer_name' => $this->payerName($body),
            // NULL, and correctly so: `received_by_user_id` is attribution for a HUMAN who took the
            // money, and no human took this. A gateway payment is collected by the provider. Naming
            // the parent here would assert a member of staff received it.
            'received_by_user_id' => null,
            'received_at' => $receivedAt,
            'origin' => Payment::ORIGIN_GATEWAY,
            'bank_account_id' => $this->settlementAccount->forSchool($schoolId),
        ]);

        $this->ledger->post(
            $schoolId,
            $studentId,
            LedgerEntryType::Payment,
            $net->times(-1),
            'payment',
            (int) $payment->getKey(),
            "Payment #{$payment->reference} received via gateway",
            $receivedAt,
        );

        return $payment;
    }

    /**
     * The DATE the school received this money, in the school's own timezone.
     *
     * `paid_at` arrives from Paystack as UTC. Nigeria is UTC+1 with no daylight saving, so every
     * payment made between 23:00 and midnight Lagos time carries a UTC date of the PREVIOUS DAY.
     * Taking the date off the raw string files those payments a day early — into a term, a month or
     * a reporting period they did not happen in, on the append-only table that cannot be corrected
     * by an UPDATE.
     *
     * The conversion is explicit rather than relying on the app timezone, so this stays correct if
     * `config('app.timezone')` is ever changed for an unrelated reason.
     */
    private function receivedAt(array $body): string
    {
        return $this->paidAt($body)?->setTimezone('Africa/Lagos')->toDateString()
            ?? now()->setTimezone('Africa/Lagos')->toDateString();
    }

    private function paidAt(array $body): ?Carbon
    {
        $raw = $body['paid_at'] ?? $body['paidAt'] ?? null;

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Who the provider says paid. Falls back to a stated absence rather than to an empty string —
     * `payer_name` shows on the receipt, and a blank there reads as a rendering fault.
     */
    private function payerName(array $body): string
    {
        $customer = $body['customer'] ?? [];

        $name = trim(implode(' ', array_filter([
            is_array($customer) ? ($customer['first_name'] ?? null) : null,
            is_array($customer) ? ($customer['last_name'] ?? null) : null,
        ], is_string(...))));

        if ($name !== '') {
            return $name;
        }

        $email = is_array($customer) ? ($customer['email'] ?? null) : null;

        return is_string($email) && $email !== '' ? $email : 'Online payment';
    }
}
