<?php

namespace App\Finance\Actions;

use App\Exceptions\BusinessRuleException;
use App\Finance\Enums\InvoiceStatus;
use App\Finance\Enums\LedgerEntryType;
use App\Finance\Enums\VoidRequestStatus;
use App\Finance\Models\Invoice;
use App\Finance\Models\LedgerTransaction;
use App\Finance\Models\VoidRequest;
use App\Finance\Services\SubledgerPoster;
use App\Finance\Services\VoidEligibility;
use App\Models\User;
use App\Support\SchoolContext;
use Illuminate\Support\Facades\DB;

/**
 * Ph3b checker side — APPROVE a pending void request. This is the whole money path, and the
 * ordering is load-bearing (docs/finance/concurrency.md: lock before the read the decision
 * depends on):
 *
 *   1. lockForUpdate on the INVOICE ROW first — a *current* read that serialises concurrent
 *      approvals (a DB trigger's SELECT reads the snapshot and cannot substitute).
 *   2. Already void? → refuse. The invoice transition is ONE-WAY; a second approval finds it
 *      void and stops here, so exactly one reversal is ever posted (there is no ledger-level
 *      source-uniqueness — this invoice one-way transition IS the duplicate guard).
 *   3. Authoritative precondition re-check — a payment can land between submit and approve;
 *      the friendly check at submit is advisory, this one decides.
 *   4. Transition the request → approved (decided_by = checker; the DB CHECK enforces ≠ maker).
 *   5. Flip the invoice active → void, then post the reversing ledger entry — the reversal is
 *      the FULL total, which is the right number precisely because (3) guarantees nothing has
 *      settled against it. Flipping to 'void' also releases the F7 slot NOW (not before).
 *
 * Maker ≠ checker holds three ways: the Policy (403), the friendly guard below, and the DB
 * CHECK (submitted_by <> decided_by) when transitionTo writes decided_by.
 */
final class ApproveVoidRequest
{
    public function __construct(private readonly SubledgerPoster $ledger) {}

    public function handle(VoidRequest $request, User $checker): VoidRequest
    {
        // Rule 13: no context, no financial governance act (App\Support\SchoolContext).
        SchoolContext::assertOwns($request, 'void request', 'approved');

        if (! $request->isPending()) {
            throw new BusinessRuleException('Only a pending void request can be approved.');
        }

        if ((string) $request->submitted_by === (string) $checker->id) {
            throw new BusinessRuleException('A void request cannot be approved by its submitter (maker ≠ checker).');
        }

        return DB::transaction(function () use ($request, $checker) {
            // 1 ── Lock the invoice row FIRST.
            $invoice = Invoice::query()->whereKey($request->invoice_id)->lockForUpdate()->firstOrFail();

            // 2 ── One-way: a second approval finds it already void and refuses.
            if ($invoice->isVoid()) {
                throw new BusinessRuleException('This invoice is already void.');
            }

            // 3 ── Authoritative precondition re-check (a payment can arrive after submit).
            $blocker = VoidEligibility::blocker($invoice);
            if ($blocker !== null) {
                throw new BusinessRuleException($blocker);
            }

            // 4 ── Record the decision (DB CHECK enforces checker ≠ maker here).
            $request->transitionTo(VoidRequestStatus::Approved, (int) $checker->id);

            // 5 ── Flip the invoice → void (releases the F7 slot now) + post the reversal.
            $charge = $invoice->total;
            $invoice->update([
                'status' => InvoiceStatus::Void,
                'cancelled_at' => now(),
                'cancelled_by_user_id' => $checker->id,
                'cancel_reason' => $request->reason,
            ]);

            $this->ledger->post(
                $invoice->school_id,
                $invoice->student_id,
                LedgerEntryType::Reversal,
                $charge->times(-1),
                'invoice',
                $invoice->id,
                "Reversal of invoice #{$invoice->number}: {$request->reason}",
                // THE ORIGINAL CHARGE'S EFFECTIVE DATE, not today — a deliberate accounting
                // decision, flagged in this branch's report for the project lead to overturn.
                //
                // A void says the invoice SHOULD NEVER HAVE EXISTED. VoidEligibility guarantees no
                // payment was ever allocated to it, so nothing about it is real: the honest record
                // is a period in which the charge and its reversal both appear and net to zero.
                // Dating the reversal today would instead leave the original period overstated
                // forever and understate this one by the same amount — two wrong periods to
                // describe one invoice that never should have been raised.
                //
                // Read from the charge itself rather than recomputed, because the charge is the
                // only authority on which period it landed in.
                $this->originalChargeEffectiveAt($invoice),
            );

            return $request->refresh();
        });
    }

    /**
     * The effective date of the charge this void reverses.
     *
     * Falls back to the invoice's creation date only for a charge posted before effective_at
     * existed. That fallback is unreachable today — the column is NOT NULL and the table was empty
     * when it was added — and it is here so a missing row degrades to the invoice's own date rather
     * than to today, which would be the one answer that is certainly wrong.
     */
    private function originalChargeEffectiveAt(Invoice $invoice): string
    {
        $charge = LedgerTransaction::query()
            ->where('source_type', 'invoice')
            ->where('source_id', $invoice->id)
            ->where('type', LedgerEntryType::Charge)
            ->orderBy('id')
            ->first();

        return $charge?->effective_at?->toDateString()
            ?? $invoice->created_at->toDateString();
    }
}
