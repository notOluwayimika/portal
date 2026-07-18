<?php

namespace App\Finance\Actions;

use App\Exceptions\BusinessRuleException;
use App\Finance\Enums\LedgerEntryType;
use App\Finance\Models\Invoice;
use App\Finance\Models\Payment;
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

        if ($invoice->isCancelled()) {
            throw new BusinessRuleException('Cannot record a payment against a cancelled invoice.');
        }

        return DB::transaction(function () use ($invoice, $amount, $payerName, $actor) {
            $reference = Sequences::next('fee_payment', (string) $invoice->school_id);

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
