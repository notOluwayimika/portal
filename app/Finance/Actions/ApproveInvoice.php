<?php

namespace App\Finance\Actions;

use App\Exceptions\BusinessRuleException;
use App\Finance\Enums\InvoiceStatus;
use App\Finance\Models\Invoice;
use App\Models\User;
use App\Support\SchoolContext;
use Illuminate\Support\Facades\DB;

/**
 * INTERNAL AUDIT RELEASES ONE BILL TO ITS PAYER — the writer the release axis has never had.
 *
 * `2026_08_31_100000` added `reviewed_at` / `reviewed_by_user_id` and backfilled the existing book;
 * `InvoiceReadModel` withholds an unreleased bill from the parent feed. Between those two there was
 * nothing: no way for an auditor to release a bill except direct SQL, which records no actor and
 * cannot be refused. This is that writer, and nothing else — no route, no page, no batch.
 *
 * ─── THE TWO COLUMNS ARE WRITTEN TOGETHER OR NOT AT ALL ─────────────────────────────────────────
 *
 * `reviewed_at` set with a NULL `reviewed_by_user_id` ALREADY MEANS SOMETHING: the migration's
 * docblock defines it as "grandfathered: released because it predates the control", and stamps the
 * entire pre-control book that way precisely because "naming a user who did not review them would
 * be a fabricated audit record — the one thing an audit column must never contain".
 *
 * So a LIVE approval that landed in that shape would be indistinguishable, forever, from a row
 * nobody reviewed. There is no later reader who could tell them apart and no third column to ask.
 * That is why the pairing is one `update()` with both keys in the same array rather than two
 * assignments — it cannot half-succeed — and why a test asserts the pairing directly rather than
 * only asserting the stamp.
 *
 * ─── CONTEXT IS THE CALLER'S, AND THAT IS WHY THE GUARD CAN FIRE ───────────────────────────────
 *
 * This took the sibling shape — `handle(Invoice, User)` — after the first version took
 * `(int $schoolId, string $uuid, User)` and opened with `ActiveSchool::runFor($schoolId, …)`.
 * That version ESTABLISHED its own context, so `SchoolContext::require()` could never fail and the
 * Rule 13 refusal was unreachable: the action could not be caught not having a context, because it
 * always made one. `tests/Feature/Finance/SchoolContextGuardTest` is the harness that proved it —
 * its "NO active School context" case is structurally impossible against an action like that.
 *
 * So the caller establishes context (the `tenant` middleware on request, `ActiveSchool::runFor`
 * off it) and this action refuses when there is none. Same as every other governance action here.
 *
 * ─── THE PERMISSION GATE IS NOT `Authz` ─────────────────────────────────────────────────────────
 *
 * Deliberately not `Authz::abilityCheck`. `config('authz.enforce')` is false in every environment,
 * so an Authz check RECORDS a would-be denial and continues (App\Support\Authz::gate) — a control
 * born inert, which is the defect three tickets from 2026-09-01/02 are about. This refuses today,
 * whatever that flag is set to.
 *
 * ─── CONCURRENCY: THE STAMP IS A COMPARE-AND-SWAP, NOT A READ-THEN-WRITE ────────────────────────
 *
 * The `already released` guard below is a READ, and between it and the write another auditor can
 * commit. So the write itself carries the same predicate — `WHERE reviewed_at IS NULL` — and its
 * AFFECTED-ROW COUNT is the authority: exactly one row means this call is the one that released it;
 * zero means someone else won and this caller is told so honestly. Two auditors clicking together
 * therefore produce ONE attestation and one truthful refusal, never a silent overwrite of a
 * colleague's signature. Same shape as the migration's own backfill, which is conditional on the
 * column for the same reason.
 *
 * The pre-check is kept in front of it anyway: it is what produces a sentence naming WHO released
 * the bill and WHEN, which the row count alone cannot — and it now runs against a row read
 * `lockForUpdate()` inside the transaction rather than against the model the caller handed in.
 *
 * ─── EVERY REFUSAL CARRIES A MESSAGE ────────────────────────────────────────────────────────────
 *
 * `BusinessRuleException` with a sentence, never a bare abort. A bare `abort(403)` reaches the
 * client as `{"message": ""}` — there is no HttpException renderable in bootstrap/app.php — and the
 * panels read it with `??`, which does not substitute for an empty string, so the refusal renders
 * as nothing at all. Measured on 2026-09-01.
 *
 * ─── THE EVENT NAME CARRIES A DOT, AGAINST THE HOUSE SHAPE ─────────────────────────────────────
 *
 * Every other finance event is one snake_case segment — `settlement_account_changed`,
 * `bank_account_created` — giving a two-segment key. This one is `invoice.approved`, so the key is
 * `finance.invoice.approved`: three segments, matching the PERMISSION it attests to
 * (`finance.invoice.approve`) rather than matching its siblings. Severity and sensitivity both
 * resolve "{log_name}.{event}" as a string, so an exact key matches and the wildcard forms are
 * unaffected.
 *
 * @activity-emits finance.invoice.approved
 */
final class ApproveInvoice
{
    /**
     * Release $invoice to its payer, attributed to $actor.
     *
     * @return Invoice the released row, refreshed
     *
     * @throws BusinessRuleException when there is no active School context, the invoice belongs to
     *                               another School, the caller may not approve, the invoice is
     *                               void, or it has already been released
     */
    public function handle(Invoice $invoice, User $actor): Invoice
    {
        // Rule 13: no context, no financial governance act (App\Support\SchoolContext). FIRST,
        // as in every sibling — and it is a real guard here rather than a formality: the record is
        // handed in directly, so nothing filtered it, and `require()` refuses outright when the
        // caller established no context at all.
        SchoolContext::assertOwns($invoice, 'invoice', 'released to its payer');

        if (! $actor->can('finance.invoice.approve')) {
            throw new BusinessRuleException(
                'You do not hold finance.invoice.approve and cannot release a bill to its payer.'
            );
        }

        return DB::transaction(function () use ($invoice, $actor): Invoice {
            // THE STATE GUARDS READ A LOCKED ROW, NOT THE HANDED-IN MODEL — the pattern
            // ApproveVoidRequest (step 1) and ApproveOpeningBalanceBatch (`$locked`) already use,
            // and the reason the latter states outright: it reads the submitter "off the LOCKED
            // row".
            //
            // The argument is the CALLER's snapshot. A controller resolves the invoice by route
            // binding and a void can commit between that resolution and this line, so a guard
            // testing `$invoice->status` would be answering a question about a row as it was, not
            // as it is. Taking the shape of the siblings is what surfaced this: while the action
            // resolved by uuid itself the reads were incidentally fresh, and the staleness was
            // invisible.
            $locked = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            // InvoiceStatus has exactly two cases, Issued and Void — read, not assumed from the
            // name. A void bill has been reversed in the ledger; releasing it would show a payer a
            // charge the school has already withdrawn.
            if ($locked->status === InvoiceStatus::Void) {
                throw new BusinessRuleException(
                    'Invoice '.$locked->uuid.' is void and cannot be released to its payer.'
                );
            }

            $this->refuseIfAlreadyReleased($locked);

            // THE COMPARE-AND-SWAP. Both columns in one array, and the predicate that makes the
            // write conditional in the same statement. Kept even behind the lock: the lock closes
            // the window inside this transaction, the predicate is what makes the write itself
            // unable to overwrite an attestation regardless of how it is reached.
            $released = Invoice::query()
                ->whereKey($locked->getKey())
                ->whereNull(Invoice::RELEASE_STAMP_COLUMN)
                ->update([
                    Invoice::RELEASE_STAMP_COLUMN => now(),
                    'reviewed_by_user_id' => $actor->getKey(),
                ]);

            if ($released !== 1) {
                // Lost the race between the read above and this write. Re-read so the sentence
                // names the auditor who actually won rather than guessing.
                $this->refuseIfAlreadyReleased($locked->fresh());

                throw new BusinessRuleException(
                    'Invoice '.$locked->uuid.' could not be released; nothing was changed.'
                );
            }

            $invoice->refresh();

            // Inside the transaction, for the reason SetSettlementBankAccount gives: a release
            // recorded without its trail, and a trail describing a release that rolled back, are
            // both worse than the failure.
            activity('finance')
                ->performedOn($invoice)
                ->causedBy($actor)
                ->event('invoice.approved')
                ->withProperties([
                    'invoice_uuid' => $invoice->uuid,
                    'student_id' => $invoice->student_id,
                    // The attestation's own timestamp, so the row answers "when was this released"
                    // without joining back to a table that may later be archived.
                    'released_at' => $invoice->{Invoice::RELEASE_STAMP_COLUMN}?->toIso8601String(),
                ])
                ->log('Invoice released to payer by Internal Audit');

            return $invoice;
        });
    }

    /**
     * Refuse a bill that already carries a release stamp, naming WHICH release it carries.
     *
     * Silently overwriting would replace one auditor's attestation with another's and leave no
     * trace of the first — the audit-record equivalent of a lost update.
     *
     * The two shapes are reported differently ON PURPOSE. A stamp with an actor is a colleague's
     * signature; a stamp WITHOUT one is the migration's grandfathering, which means the bill
     * predates the control and was never anybody's to review. Telling an auditor "already approved
     * by user#null" would be false.
     *
     * @throws BusinessRuleException
     */
    private function refuseIfAlreadyReleased(?Invoice $invoice): void
    {
        if ($invoice === null || $invoice->{Invoice::RELEASE_STAMP_COLUMN} === null) {
            return;
        }

        $reviewer = $invoice->reviewed_by_user_id;

        throw new BusinessRuleException(
            $reviewer === null
                ? 'Invoice '.$invoice->uuid.' was released before Internal Audit review existed '
                    .'(grandfathered by 2026_08_31_100000) and cannot be released again.'
                : 'Invoice '.$invoice->uuid.' was already released by user#'.$reviewer.'.'
        );
    }
}
