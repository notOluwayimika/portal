<?php

namespace App\Finance\Actions;

use App\Exceptions\BusinessRuleException;
use App\Finance\Enums\LedgerEntryType;
use App\Finance\Models\Invoice;
use App\Finance\Models\Payment;
use App\Finance\Models\PaymentAllocation;
use App\Finance\Services\SubledgerPoster;
use App\Models\User;
use App\Support\Money;
use App\Support\Sequences\Sequences;
use Illuminate\Support\Facades\DB;

/**
 * Record one payment against the student account and settle it against one invoice,
 * posting the crediting ledger entry — one transaction (payment, allocation and
 * ledger credit together). The payment belongs to the ACCOUNT, not the invoice; the
 * allocation is the money→invoice link.
 *
 * OVERPAYMENT IS BANKED, NOT REJECTED (wallet W2). The allocation to the invoice is
 * capped at the invoice's OUTSTANDING (total − Σ prior allocations); any excess stays
 * unallocated and surfaces as available credit on the account. The ledger credit is
 * the FULL cash received, so the account balance (maintained by SubledgerPoster::post)
 * goes negative by exactly the banked remainder. The #94 over-allocation ceiling —
 * Σ(allocations) ≤ invoice total — is untouched and still enforced by the trigger;
 * capping simply means the Action never approaches it.
 *
 * This Action does NOT touch finance_student_accounts. The balance is maintained by
 * the single ledger writer (post), so RecordPayment takes NO account lock and #94's
 * invoice-row lock is left exactly as it was. The account-first lock ordering is a
 * W3 concern (where applying credit is a genuine read-modify-write of the balance);
 * it is documented in docs/finance/concurrency.md, not enforced here.
 */
final class RecordPayment
{
    public function __construct(private readonly SubledgerPoster $ledger) {}

    /**
     * @param  string  $receivedAt  The business date the money was RECEIVED (Y-m-d). REQUIRED and
     *                              without a default: a payment handed over on Friday and keyed on Monday belongs to Friday,
     *                              and finance_payments is append-only so the date can never be corrected afterwards. The
     *                              FormRequest requires it at the edge; this refusal is the backstop for every non-HTTP caller.
     * @param  string|null  $receivedAtReason  Why the date is not today. Required when it is not,
     *                                         because a back-dated receipt with no explanation is the first thing an auditor asks about.
     */
    public function handle(Invoice $invoice, Money $amount, string $payerName, User $actor, string $receivedAt, ?string $receivedAtReason = null): Payment
    {
        if ($amount->isZero() || $amount->isNegative()) {
            throw new BusinessRuleException('A payment amount must be positive.');
        }

        if ($invoice->isVoid()) {
            throw new BusinessRuleException('Cannot record a payment against a void invoice.');
        }

        // A payment must be in the invoice's currency (mirrors SubmitCreditNote's check). Without this a
        // "USD" payment against an NGN invoice banks silently as an unallocated advance (no allocation row
        // when outstanding is 0), corrupting the account balance — the allocation trigger only fires when an
        // allocation row is written. Refuse at the edge → 422; SubledgerPoster is the backstop.
        if ($amount->currency !== $invoice->total->currency) {
            throw new BusinessRuleException("A payment must be in the invoice's currency ({$invoice->total->currency}).");
        }

        return DB::transaction(function () use ($invoice, $amount, $payerName, $actor, $receivedAt, $receivedAtReason) {
            // Concurrency anchor (#94, UNCHANGED). Lock the INVOICE ROW first so
            // allocations to the same invoice serialise: a competing allocation blocks
            // here, then reads the winner's committed sum for the outstanding cap below.
            $locked = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            // Through the model, not DB::table (the boundary lint forbids that escape
            // hatch in app/Finance). PaymentAllocation is School-scoped and we are in the
            // invoice's School context. Outstanding is ≥ 0 by the #94 invariant.
            $alreadyAllocated = (int) PaymentAllocation::query()
                ->where('invoice_id', $locked->id)
                ->sum('amount_minor');

            // Cap the allocation at outstanding. The overpaid remainder is left
            // unallocated and banks as credit (via the full ledger credit below); when
            // the invoice is already fully allocated (outstanding 0), the whole payment
            // banks and NO allocation row is written — an unallocated advance payment,
            // which the schema already expresses (Payment carries no invoice FK).
            $outstandingKobo = max(0, $locked->total->toKobo() - $alreadyAllocated);
            $allocateKobo = min($amount->toKobo(), $outstandingKobo);

            // NO SEED CLOSURE, and that omission is load-bearing — do not "harden" this to match
            // HasAdmissionNumber:55 / HasStaffNumber:54. Seeding it would adopt MAX(reference), which
            // after an opening-balance import is a migrated row in the reserved band, sending every
            // portal receipt for that school above Payment::MIGRATED_REFERENCE_FLOOR forever.
            $reference = Sequences::next('finance_payment', (string) $invoice->school_id);

            // The payment records the FULL cash received (belongs to the account).
            $payment = Payment::create([
                'school_id' => $invoice->school_id,
                'student_id' => $invoice->student_id,
                'reference' => $reference,
                'amount' => $amount,
                'payer_name' => $payerName,
                'received_by_user_id' => $actor->id,
                'received_at' => $receivedAt,
                'received_at_reason' => $receivedAtReason,
            ]);

            if ($allocateKobo > 0) {
                $payment->allocations()->create([
                    'school_id' => $invoice->school_id,
                    'invoice_id' => $locked->id,
                    'amount' => Money::fromKobo($allocateKobo, $amount->currency),
                    // THE rule this Action implements: the payment names an invoice and is
                    // allocated against it, capped at outstanding. Nothing here is a choice a
                    // human made, so the override is false with no reason.
                    'allocation_rule' => PaymentAllocation::RULE_PAYMENT_AGAINST_NAMED_INVOICE,
                    'allocation_overridden' => false,
                    'allocation_override_reason' => null,
                ]);
            }

            // Credit — the FULL payment reduces the receivable, so the ledger amount is
            // negative. Sourced to the PAYMENT (the cash event), not the allocation: the
            // credit is the money arriving and may exceed any single allocation, so the
            // payment is its only coherent source. post() also moves the account balance.
            $this->ledger->post(
                $invoice->school_id,
                $invoice->student_id,
                LedgerEntryType::Payment,
                $amount->times(-1),
                'payment',
                (int) $payment->getKey(),
                "Payment #{$reference} recorded against invoice #{$invoice->number}"
                .($allocateKobo < $amount->toKobo()
                    ? ' ('.($amount->toKobo() - $allocateKobo).' minor units banked as credit)'
                    : ''),
                // EFFECTIVE = THE DAY THE MONEY ARRIVED, not the day it was keyed. The ledger
                // credit and the payment row must agree about which period the cash belongs to,
                // or a back-dated receipt lands the payment in one month and its ledger effect in
                // another and neither month reconciles.
                $receivedAt,
            );

            return $payment->load('allocations');
        });
    }
}
