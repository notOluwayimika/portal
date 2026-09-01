<?php

namespace App\Finance\Actions;

use App\Exceptions\BusinessRuleException;
use App\Finance\Enums\GatewaySettlementOutcome;
use App\Finance\Enums\GatewayTransactionStatus;
use App\Finance\Exceptions\GatewayClaimLost;
use App\Finance\Exceptions\PaystackUnavailable;
use App\Finance\Models\GatewayTransaction;
use App\Finance\Models\GatewayTransactionEvent;
use App\Finance\Models\Invoice;
use App\Finance\Models\Payment;
use App\Finance\Services\GatewayEventRedactor;
use App\Finance\Services\PaystackClient;
use App\Finance\Services\SettlementBankAccount;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turns a provider delivery into, at most, one Payment.
 *
 * ── WHERE THE MONEY FACTS COME FROM: `verify()`, NEVER THE DELIVERY ──
 *
 * A webhook is a NOTIFICATION. Its signature proves the sender holds the Paystack secret and proves
 * nothing about whether its contents match Paystack's ledger — anyone holding that secret could sign
 * a body claiming a `charge.success` that never happened, and nobody can make Paystack's own API
 * return a transaction that does not exist. So the webhook supplies the TRIGGER and the REFERENCE;
 * every amount, fee, status and instant that reaches a money column comes from
 * {@see settleFromProvider}, which asks the provider directly.
 *
 * This is stated at the top because step 4 shipped without it — `handle()` passed the webhook body
 * straight to `settle()` while three docblocks and a decision document said it must not, and the
 * gap went unnoticed for exactly as long as nobody re-read the premise. See
 * `docs/handoff/tickets/webhook-records-a-payment-without-calling-verify.md`.
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

    /**
     * The school's timezone, named once. `paid_at` arrives UTC and Nigeria is UTC+1 with no DST, so
     * every payment between 23:00 and midnight local carries a UTC date of the PREVIOUS DAY.
     * Explicit rather than relying on `config('app.timezone')`, so this stays correct if that is
     * ever changed for an unrelated reason.
     */
    public const SCHOOL_TIMEZONE = 'Africa/Lagos';

    public function __construct(
        private readonly PaystackClient $paystack,
        private readonly RecordPayment $payments,
        private readonly SettlementBankAccount $settlementAccount,
        private readonly GatewayEventRedactor $redactor,
    ) {}

    /**
     * The whole sequence for ONE WEBHOOK DELIVERY: record it, then ask the provider what is true.
     *
     * THE ORDER IS THE POINT and it lives here rather than in the controller, because the reason
     * for it is the reason documented on this class. A caller that recorded the delivery only for
     * outcomes it liked would produce exactly the gap T1/T2 exists to close, and would do so while
     * looking perfectly reasonable at the call site.
     *
     * WHAT THIS METHOD DOES NOT DO IS SETTLE FROM `$body`. The webhook is the trigger, not the
     * evidence: it tells this system WHEN to look and which reference to look at, and nothing else
     * in it is trusted. {@see settleFromProvider} is the only path to the money, and it reads
     * Paystack's own answer. The distinction matters because a signature proves possession of the
     * secret and says nothing about whether the contents are true.
     *
     * @param  array<string, mixed>  $body  the full webhook body, as delivered — recorded as
     *                                      evidence of what arrived, never read for its amounts
     */
    public function handle(GatewayTransaction $transaction, string $source, ?string $event, array $body): GatewaySettlementOutcome
    {
        // T1 — unconditional, and committed before T2 opens.
        $this->recordDelivery($transaction, $source, $event, $body);

        if ($event !== self::SETTLING_EVENT) {
            return GatewaySettlementOutcome::NotASettlementEvent;
        }

        // THE WEBHOOK SAYS "LOOK AGAIN"; IT DOES NOT SAY WHAT HAPPENED. The body is NOT settled
        // from — see settleFromProvider, which is the only path to the money.
        return $this->settleFromProvider($transaction);
    }

    /**
     * THE ONLY PATH THAT WRITES MONEY, and it settles from the provider's own answer.
     *
     * ── WHY THIS METHOD EXISTS AT ALL ──
     *
     * It did not, and that was the defect. Step 4 shipped calling `settle()` with the WEBHOOK body,
     * which three docblocks and a decision document forbid in as many words: a signature proves a
     * body came from the holder of the secret and says nothing about whether its contents match
     * Paystack's ledger. Anyone holding the secret could sign a body claiming a `charge.success`
     * that never happened; nobody can make Paystack's own API return a transaction that does not
     * exist. That gap is the entire difference between trusting the wire and trusting the provider,
     * and closing it is what `verify()` is for.
     *
     * ── AND WHY IT IS ONE METHOD RATHER THAN ONE PER CALLER ──
     *
     * Verify-on-return (§6 step 6) is the second caller and arrives with the same job: given a
     * transaction, ask the authority and settle from the answer. Written twice, the two would agree
     * on the day they were written and drift the first time either changed — the shape this
     * codebase has now hit three times in one day (the release predicate, the fee calculator, and
     * this). So step 6 calls THIS, and does not grow its own copy.
     *
     * ── THE UNREACHABLE-PROVIDER BRANCH IS NOT NEW POLICY ──
     *
     * It was decided on 2026-08-29, before the handler existed:
     * `docs/handoff/decisions/webhook-arrives-but-verify-is-unreachable.md`. Acknowledge, persist,
     * leave `pending`, let a later delivery or the discrepancy report recover it. What is built here
     * is that decision executing for the first time — the case was unreachable while there was no
     * verify call to fail.
     */
    public function settleFromProvider(GatewayTransaction $transaction): GatewaySettlementOutcome
    {
        try {
            $answer = $this->paystack->verifyWithPayload($transaction->reference);
        } catch (PaystackUnavailable $e) {
            // NOT a failure of the payment — a failure to find out. Recorded as its own outcome
            // precisely so it can never be read as "the charge did not succeed".
            Log::warning('paystack.verify.unavailable', [
                'transaction' => $transaction->getKey(),
                'reason' => $e->getMessage(),
            ]);

            return GatewaySettlementOutcome::VerifyUnavailable;
        }

        // The authority's answer is evidence in its own right and is kept whether or not it settles
        // anything — `event` is null because a verify response is not an event. Recorded BEFORE the
        // success test, so a provider that disagrees with the webhook leaves the disagreement on
        // file rather than only in a log line.
        $this->recordDelivery($transaction, GatewayTransactionEvent::SOURCE_VERIFY, null, $answer['body']);

        if (! $answer['transaction']->isSuccessful()) {
            Log::error('paystack.verify.not_successful', [
                'transaction' => $transaction->getKey(),
                'status' => $answer['transaction']->status,
            ]);

            return GatewaySettlementOutcome::NotSuccessfulAtProvider;
        }

        try {
            return $this->settle($transaction, is_array($answer['body']['data'] ?? null) ? $answer['body']['data'] : []);
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
     * @param  array<string, mixed>  $body  the provider's `data` object, AS RETURNED BY `verify()`.
     *                                      Never a webhook payload — see {@see settleFromProvider},
     *                                      which is the only caller that should ever build it.
     */
    public function settle(GatewayTransaction $transaction, array $body): GatewaySettlementOutcome
    {
        // WHAT THE PROVIDER SAYS IT CHARGED MUST BE WHAT WE ASKED FOR.
        //
        // Everything below takes the gross from OUR row and only the fee from the delivery, so a
        // provider reporting a different amount or currency would never be noticed — the numbers
        // would simply not meet. Worse, `reportedFee()` builds the fee in the LOCAL currency, which
        // disarms the one guard that would otherwise have caught it: `Money::minus` throws on a
        // currency mismatch, and forcing them equal means it never can.
        //
        // So the comparison is explicit, before any of it. A mismatch is not booked.
        if (! $this->matchesCharge($transaction, $body)) {
            Log::error('paystack.webhook.amount_mismatch', [
                'transaction' => $transaction->getKey(),
                'expected_minor' => $transaction->amount->toKobo(),
                'expected_currency' => $transaction->amount->currency,
            ]);

            return GatewaySettlementOutcome::AmountMismatch;
        }

        $fee = $this->reportedFee($body, $transaction->amount->currency);

        // §7's fifth case: the provider says success but does not say what it took. The net amount
        // is unknowable, so nothing is written and the row stays `pending` for the discrepancy
        // report to find. Answering 200 is correct — the delivery is not malformed and retrying it
        // will not produce a fee. What is missing is a FACT, not a message.
        if ($fee === null) {
            return GatewaySettlementOutcome::FeeNotReported;
        }

        // ── RELEASE WITHDRAWN AFTER THE CHARGE: THIS PATH DOES NOTHING, DELIBERATELY ──
        //
        // `finance_invoices.reviewed_at` gates whether an invoice is released to the payer, and that
        // check belongs at INITIATION. Release is a school-side act, so it can move between a parent
        // starting to pay and the delivery arriving.
        //
        // Refusing here would NOT un-take the money. It would only detach the evidence from the
        // invoice the parent actually chose, leaving an orphaned charge and a human reconciliation —
        // strictly worse than recording the payment and raising an alert. Same reasoning as §11
        // decision 4. Detection belongs in step 7's report, which compares the invoice's release and
        // void state at `created_at` against `paid_at`:
        // docs/handoff/tickets/discrepancy-report-fifth-class-release-withdrawn.md
        //
        // Written here rather than left implicit because the NEXT reader's question is "why is there
        // no payability check on the money path", and an unanswered why gets answered by someone
        // adding the refusal.

        // THE SUBTRACTION IS THE FEE RULING, NOT ARITHMETIC — and it is NOT policy-independent.
        //
        // Dev 1 settled it (docs/handoff/payments-decisions-30-august.md §2, 2026-08-30): the PARENT
        // bears the fee. The parent is charged bill + fee, the school receives the full bill, and
        // "the amount charged at the gateway is not the amount recorded against the invoice". The
        // fee portion was the parent paying Paystack, never paying the school, so the invoice is
        // credited `amount − fees`.
        //
        // UNDER A SCHOOL-ABSORBS RULING THIS LINE WOULD BE `$transaction->amount` INSTEAD. There the
        // parent is charged the bill exactly and has paid it in full; the fee is the school's own
        // cost, not a shortfall on the payer's account. Subtracting it would leave a parent who paid
        // ₦100,000 against a ₦100,000 invoice owing ₦1,600 — permanently, on an append-only table.
        //
        // The two regimes agree on what the school NETS and disagree on what the payment CREDITS,
        // which is why "the school receives bill − fee" is not an argument for subtracting here. No
        // configuration knob exists because the ruling is settled, not because the policies agree.
        // If the ruling ever moves, this line moves with it — and so does §11 decision 4, where the
        // credit banked against a cancelled invoice is the RECORDED PAYMENT AMOUNT.
        // THE RANGE GUARD WAS ONE-SIDED, WHICH IS HOW IT READ AS COVERED. `FeeExceedsAmount` below
        // catches a fee too LARGE; nothing caught a fee below zero. `Money::fromKobo` takes any int,
        // so `fees: -50000` yields a net GREATER than the gross: the invoice is credited more than
        // the payer was ever charged, allocated up to outstanding, and the remainder banked as
        // account credit — on `finance_payments`, which is append-only and cannot be corrected in
        // place. Money invented, not merely misfiled.
        //
        // Reachable only if Paystack itself reports it: `$body` is the provider's own verify answer,
        // not a webhook payload. That is an argument about likelihood, not about consequence, and
        // the fix is one line beside a refusal already built for the sibling input.
        if ($fee->isNegative()) {
            Log::error('paystack.webhook.fee_is_negative', ['transaction' => $transaction->getKey()]);

            return GatewaySettlementOutcome::FeeIsNegative;
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

            try {
                $payment = $this->writePayment($transaction, $body, $net);
            } catch (BusinessRuleException $e) {
                // The invoice is void, or its currency does not match the charge. Both are real
                // refusals and RecordPayment is right to make them — but the payer has ALREADY been
                // charged, so this is money in the account with no payment recorded against it.
                //
                // It must not escape as a 500: Paystack would retry on a schedule, each attempt
                // failing identically, and the retry log would read like a provider problem. It
                // must not be silent either. Logged at ERROR, left `pending`, surfaced by step 7.
                Log::error('paystack.webhook.could_not_book', [
                    'transaction' => $transaction->getKey(),
                    'reason' => $e->getMessage(),
                ]);

                return GatewaySettlementOutcome::CouldNotBook;
            }

            $claimed = $this->claim($transaction, $payment, $body, $fee);

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
     * THE COMPARE-AND-SWAP, extracted so it can be exercised DIRECTLY.
     *
     * `payment_id IS NULL` here is not redundant with the locked read in {@see settle()}: the lock
     * makes them equivalent today, and this is what still holds if a future caller reaches this
     * method without one. The affected-row count is returned rather than assumed, and the caller
     * asserts it.
     *
     * IT IS A SEPARATE METHOD BECAUSE OF HOW ITS TEST FAILED. The arm that claimed to be this
     * predicate's mutation guard issued its own hand-written UPDATE, so deleting the clause from
     * here changed nothing that test executed — it asserted a property of MySQL, not of this code,
     * under a comment asserting the opposite. While the predicate lived inline behind the early
     * return, no test could reach it at all: the lock makes the losing path unreachable in a single
     * process, which is exactly what makes it defence-in-depth and exactly what made it untestable.
     *
     * Extracting it resolves that. A test can now call this against an already-claimed row and
     * assert it returns 0, so removing `AND payment_id IS NULL` reds a test that runs, rather than a
     * mutation recorded in a markdown table that does not.
     *
     * @return int rows affected: 1 = claimed, 0 = another delivery got there first
     */
    private function claim(GatewayTransaction $transaction, Payment $payment, array $body, Money $fee): int
    {
        return DB::update(
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
    }

    /**
     * Whether the delivery describes the charge this transaction initiated.
     *
     * Both halves are compared, and a MISSING field fails rather than passes: an absent amount is
     * not a matching amount, and treating it as one would put the check back where it started.
     *
     * The amounts are compared as integers in minor units — never as floats, and never after any
     * arithmetic — so this is exact.
     */
    private function matchesCharge(GatewayTransaction $transaction, array $body): bool
    {
        $amount = $body['amount'] ?? null;
        $currency = $body['currency'] ?? null;

        if (! is_numeric($amount) || ! is_string($currency)) {
            return false;
        }

        return (int) $amount === $transaction->amount->toKobo()
            && $currency === $transaction->amount->currency;
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

    /**
     * Writes the payment BY DELEGATING TO {@see RecordPayment}, the existing named-invoice path.
     *
     * ── WHY DELEGATION AND NOT A SECOND WRITER ──
     *
     * The first version of this method built the Payment itself and posted the ledger credit, and
     * wrote NO PaymentAllocation. That was a defect, not a policy: ADR 0048 makes
     * `GenerateInvoice::applyCreditForward` the sole allocator of UNNAMED money, and gateway money
     * is not unnamed — the parent chose an invoice at initiation and
     * `finance_gateway_transactions.invoice_id` records which. Banking it as account credit
     * misclassified the input and then applied the right rule to the wrong category.
     *
     * What that produced, in the order it would have arrived: the parent pays ₦80,000 against
     * invoice B and the portal immediately shows ₦80,000 of credit ABOVE invoice B still
     * outstanding — an incoherent screen manufactured by the act of paying, seconds after they
     * paid, on the page they are still looking at. It does not self-heal, because
     * `applyCreditForward` runs only at invoice generation. And when it eventually does heal it
     * settles the OLDEST invoice, which need not be the one they chose: the receipt says B and the
     * ledger says A. A parent deliberately paying the newest invoice because the oldest is disputed
     * gets the opposite outcome, silently.
     *
     * `RecordPayment` already implements the correct rule — lock the invoice row, cap the
     * allocation at outstanding, write it under `RULE_PAYMENT_AGAINST_NAMED_INVOICE` — and it was
     * written for this caller: its `$origin` and `$externalReference` parameters name Paystack
     * explicitly. Reusing it also picks up three refusals this path was silently missing: a payment
     * against a VOID invoice, a currency that does not match the invoice, and the external
     * reference that is the only way back from a row here to the provider's record of it.
     *
     * IT ALSO REMOVES A CONCURRENCY CLAIM I WOULD OTHERWISE HAVE HAD TO MAKE. `docs/finance/
     * concurrency.md` documents the invoice-lock invariant across a fixed set of call sites; a
     * second hand-rolled writer would have become another one, silent about why it took no lock.
     * Delegating means there is no new site — the #94 anchor is taken inside `RecordPayment`.
     */
    private function writePayment(GatewayTransaction $transaction, array $body, Money $net): Payment
    {
        /** @var Invoice $invoice */
        $invoice = $transaction->invoice()->firstOrFail();
        $receivedAt = $this->receivedAt($body);

        return $this->payments->handle(
            $invoice,
            $net,
            $this->payerName($body),
            // NULL, and correctly so: `received_by_user_id` is attribution for a HUMAN who took the
            // money, and no human took this. Naming the parent would assert a member of staff
            // received it.
            null,
            $receivedAt,
            $this->settlementAccount->forSchool((int) $transaction->school_id),
            $this->receivedAtReason($receivedAt),
            Payment::ORIGIN_GATEWAY,
            // The provider's own handle, into the column whose meaning is "the source system's
            // identifier for this money". Without it there is no way back from this row to
            // Paystack's record of it.
            (string) $transaction->reference,
        );
    }

    /**
     * Why this payment is dated before today, when it is.
     *
     * A delivery retried across midnight — or a settlement webhook that arrives the next morning —
     * files a payment on the charge's date, which is correct and is also BACK-DATED. A back-dated
     * receipt with no explanation is the first thing an auditor asks about, so the reason is
     * written rather than left NULL. NULL when the date is today, so the common case says nothing
     * rather than saying something empty.
     */
    private function receivedAtReason(string $receivedAt): ?string
    {
        if ($receivedAt === now()->setTimezone(self::SCHOOL_TIMEZONE)->toDateString()) {
            return null;
        }

        return 'Paid online on '.$receivedAt.'; the provider notification was processed later.';
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
        return $this->paidAt($body)?->setTimezone(self::SCHOOL_TIMEZONE)->toDateString()
            ?? now()->setTimezone(self::SCHOOL_TIMEZONE)->toDateString();
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
