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

    public function handle(Invoice $invoice, Money $amount, string $payerName, User $actor): Payment
    {
        if ($amount->isZero() || $amount->isNegative()) {
            throw new BusinessRuleException('A payment amount must be positive.');
        }

        if ($invoice->isVoid()) {
            throw new BusinessRuleException('Cannot record a payment against a void invoice.');
        }

        return DB::transaction(function () use ($invoice, $amount, $payerName, $actor) {
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

            $reference = Sequences::next('finance_payment', (string) $invoice->school_id);

            // The payment records the FULL cash received (belongs to the account).
            $payment = Payment::create([
                'school_id' => $invoice->school_id,
                'student_id' => $invoice->student_id,
                'reference' => $reference,
                'amount' => $amount,
                'payer_name' => $payerName,
                'received_by_user_id' => $actor->id,
            ]);

            if ($allocateKobo > 0) {
                $payment->allocations()->create([
                    'school_id' => $invoice->school_id,
                    'invoice_id' => $locked->id,
                    'amount' => Money::fromKobo($allocateKobo, $amount->currency),
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
            );

            return $payment->load('allocations');
        });
    }
}
