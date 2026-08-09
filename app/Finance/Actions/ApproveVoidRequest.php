<?php

namespace App\Finance\Actions;

use App\Exceptions\BusinessRuleException;
use App\Finance\Enums\InvoiceStatus;
use App\Finance\Enums\LedgerEntryType;
use App\Finance\Enums\VoidRequestStatus;
use App\Finance\Models\Invoice;
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
            );

            return $request->refresh();
        });
    }
}
