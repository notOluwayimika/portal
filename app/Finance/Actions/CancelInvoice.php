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
 * Void an invoice by REVERSAL, never deletion — one transaction (the status change
 * and the reversing ledger entry commit together). The charge is reversed; any
 * prior payment allocation is left untouched (append-only), so a paid-then-voided
 * invoice correctly leaves a credit balance on the account rather than silently
 * un-linking the money.
 *
 * CONCURRENCY (the guard this slice adds). "Is it already void?" is a read-then-
 * write, and under MySQL's default REPEATABLE READ a plain read inside a
 * transaction sees the snapshot taken at the transaction's first read — so two
 * concurrent voids could BOTH observe 'issued' and BOTH post a reversing entry,
 * double-crediting the student. The re-read below is `lockForUpdate()`, which is a
 * *current* read: it bypasses the snapshot, sees the latest committed row, and
 * blocks on the other transaction's row lock until it commits. The loser then sees
 * VOID and is rejected. Exactly one reversal, proven in
 * tests/Feature/Finance/InvoiceConcurrencyTest.php.
 *
 * This is a PER-ROW guard on the invoice. It is NOT the finance_student_accounts
 * lock anchor (§12.2), which serialises balance-decide-writes during payment
 * allocation — that stays a Phase-6 concern and this slice does not reach it.
 */
final class CancelInvoice
{
    public function __construct(private readonly SubledgerPoster $ledger) {}

    public function handle(Invoice $invoice, string $reason, User $actor): Invoice
    {
        return DB::transaction(function () use ($invoice, $reason, $actor) {
            // Current read + row lock. Must precede the state check, or the check
            // is made against a snapshot that may already be stale.
            $locked = Invoice::query()
                ->whereKey($invoice->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw new BusinessRuleException('This invoice no longer exists.');
            }

            if ($locked->isVoid()) {
                throw new BusinessRuleException('This invoice is already void.');
            }

            $charge = $locked->total;

            $locked->update([
                'status' => InvoiceStatus::Void,
                'cancelled_at' => now(),
                'cancelled_by_user_id' => $actor->id,
                'cancel_reason' => $reason,
            ]);

            $this->ledger->post(
                $locked->school_id,
                $locked->student_id,
                LedgerEntryType::Reversal,
                $charge->times(-1),
                'invoice',
                $locked->id,
                "Reversal of invoice #{$locked->number}: {$reason}",
            );

            return $locked->fresh('lines');
        });
    }
}
