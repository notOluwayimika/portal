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
 * Record one payment against the student account and allocate it to one invoice,
 * posting the crediting ledger entry — one transaction (payment, allocation, and
 * the credit commit together). The payment belongs to the account, not the
 * invoice; the allocation is the money→invoice link and the ledger's source.
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
            // Concurrency anchor. A trigger's SELECT SUM is not safe on its own — two
            // allocations in separate transactions each miss the other's uncommitted
            // row and both pass (write skew, §12.2). Lock the INVOICE ROW first (no
            // finance_student_accounts needed for a per-invoice rule), so allocations to
            // the same invoice serialise: the loser blocks here, then reads the winner's
            // committed sum and is rejected. The DB trigger is the single-write/tamper
            // backstop; this lock is what makes the guarantee hold under concurrency.
            $locked = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            // Through the model, not DB::table (the boundary lint forbids that escape
            // hatch in app/Finance): PaymentAllocation is School-scoped, and we are
            // inside the invoice's School context, so the scope narrows correctly. The
            // sum reads the raw amount_minor column, not the Money cast.
            $alreadyAllocated = (int) PaymentAllocation::query()
                ->where('invoice_id', $locked->id)
                ->sum('amount_minor');

            // Friendly path (the trigger is the real guarantee). 422 via BusinessRuleException.
            //
            // This rejects an OVERPAYMENT today only as a CONSEQUENCE of allocating the
            // full amount to one invoice — it is NOT a permanent "no overpayment ever"
            // policy. When the wallet slice lands, RecordPayment will allocate up to
            // outstanding and bank the remainder to the wallet; this invariant is
            // untouched and the reject simply stops firing because the Action no longer
            // over-allocates. The ceiling is Σ(allocations) ≤ invoice total, forever.
            if ($alreadyAllocated + $amount->toKobo() > $locked->total->toKobo()) {
                throw new BusinessRuleException(
                    'This payment would allocate more than the invoice is worth. The invoice can accept at most '
                    .($locked->total->toKobo() - $alreadyAllocated).' minor units more.'
                );
            }

            $reference = Sequences::next('finance_payment', (string) $invoice->school_id);

            $payment = Payment::create([
                'school_id' => $invoice->school_id,
                'student_id' => $invoice->student_id,
                'reference' => $reference,
                'amount' => $amount,
                'payer_name' => $payerName,
                'received_by_user_id' => $actor->id,
            ]);

            $allocation = $payment->allocations()->create([
                'school_id' => $invoice->school_id,
                'invoice_id' => $invoice->id,
                'amount' => $amount,
            ]);

            // Credit — a payment reduces the receivable, so the ledger amount is
            // negative. Sourced to the allocation (the settlement link).
            $this->ledger->post(
                $invoice->school_id,
                $invoice->student_id,
                LedgerEntryType::Payment,
                $amount->times(-1),
                'payment_allocation',
                (int) $allocation->getKey(),
                "Payment #{$reference} allocated to invoice #{$invoice->number}",
            );

            return $payment->load('allocations');
        });
    }
}
