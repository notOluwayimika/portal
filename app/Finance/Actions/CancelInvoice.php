<?php

namespace App\Finance\Actions;

use App\Exceptions\BusinessRuleException;
use App\Finance\Enums\InvoiceStatus;
use App\Finance\Enums\LedgerEntryType;
use App\Finance\Models\Invoice;
use App\Finance\Services\SubledgerPoster;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Cancel an invoice by REVERSAL, never deletion — one transaction (the status
 * change and the reversing ledger entry commit together). The charge is reversed;
 * any prior payment allocation is left untouched (append-only), so a paid-then-
 * cancelled invoice correctly leaves a credit balance on the account rather than
 * silently un-linking the money.
 */
final class CancelInvoice
{
    public function __construct(private readonly SubledgerPoster $ledger) {}

    public function handle(Invoice $invoice, string $reason, User $actor): Invoice
    {
        if ($invoice->isCancelled()) {
            throw new BusinessRuleException('This invoice is already cancelled.');
        }

        return DB::transaction(function () use ($invoice, $reason, $actor) {
            $charge = $invoice->total;

            $invoice->update([
                'status' => InvoiceStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by_user_id' => $actor->id,
                'cancel_reason' => $reason,
            ]);

            $this->ledger->post(
                $invoice->school_id,
                $invoice->student_id,
                LedgerEntryType::Reversal,
                $charge->times(-1),
                'invoice',
                $invoice->id,
                "Reversal of invoice #{$invoice->number}: {$reason}",
            );

            return $invoice->fresh('lines');
        });
    }
}
