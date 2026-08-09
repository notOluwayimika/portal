<?php

namespace App\Finance\Actions;

use App\Exceptions\BusinessRuleException;
use App\Finance\Enums\CreditNoteStatus;
use App\Finance\Enums\InvoiceStatus;
use App\Finance\Enums\LedgerEntryType;
use App\Finance\Models\CreditNote;
use App\Finance\Models\Invoice;
use App\Finance\Services\SubledgerPoster;
use App\Models\User;
use App\Support\SchoolContext;
use App\Support\SchoolDay;
use Illuminate\Support\Facades\DB;

/**
 * Ph3 checker side — APPROVE a pending credit note. This is where C1's issuance logic
 * now lives: the compensating ledger credit is posted, the balance moves, and the
 * over-credit ceiling is enforced — all in ONE transaction under the invoice-row lock,
 * so money and the `approved` status flip commit together or not at all.
 *
 * Maker ≠ checker is enforced in THREE places: the Policy (HTTP edge, 403), the friendly
 * guard below (for non-HTTP callers), and — the real guarantee — the DB CHECK constraint
 * `submitted_by <> decided_by`, which fires when transitionTo writes decided_by. The
 * Policy alone is not the control; the CHECK holds for raw writes, jobs and tinker.
 *
 * Money moves ONLY here. Submit posted nothing; a pending credit was never in the ledger,
 * the balance, or the ceiling, so approval is the first and only time the credit is real.
 */
final class ApproveCreditNote
{
    public function __construct(private readonly SubledgerPoster $ledger) {}

    public function handle(CreditNote $creditNote, User $checker): CreditNote
    {
        // Rule 13: no context, no financial governance act (App\Support\SchoolContext).
        SchoolContext::assertOwns($creditNote, 'credit note', 'approved');

        if (! $creditNote->isPending()) {
            throw new BusinessRuleException('Only a pending credit note can be approved.');
        }

        // Friendly maker ≠ checker (string-compared, fail-safe on type mismatch — the same
        // shape as the Policy). The DB CHECK is the authoritative backstop.
        if ((string) $creditNote->submitted_by === (string) $checker->id) {
            throw new BusinessRuleException('A credit note cannot be approved by its submitter (maker ≠ checker).');
        }

        return DB::transaction(function () use ($creditNote, $checker) {
            // Lock the INVOICE row first (C1's #94 convention): concurrent approvals of two
            // proposals against the same invoice serialise here, so the loser reads the
            // winner's committed sum and is rejected by the ceiling.
            $invoice = Invoice::query()->whereKey($creditNote->invoice_id)->lockForUpdate()->firstOrFail();

            // Re-check the SUBJECT is still in the state that made the action legal — the general
            // maker-checker rule (docs/handoff/maker-checker-two-instance-diff.md). A credit note
            // approves against an ISSUED invoice; if the invoice was VOIDED after this note was
            // submitted, its charge is already reversed and forgiving more would conjure credit
            // from a dead document. The submit-time state is stale by approval — only this read,
            // under the lock the void's approval also takes, is authoritative. The note stays
            // `submitted` (a human rejects it with "invoice voided"); we never auto-decide it.
            if ($invoice->status !== InvoiceStatus::Issued) {
                throw new BusinessRuleException(
                    'This invoice is no longer issued (it was voided); its credit note cannot be approved.'
                );
            }

            // Σ of ALREADY-approved credits for this invoice (pending proposals do not count).
            // The row being approved is still `submitted` at this point, so it is excluded and
            // added explicitly. The DB UPDATE-ceiling trigger is the real guarantee; this is
            // the friendly 422.
            $approved = (int) CreditNote::query()
                ->where('invoice_id', $invoice->id)
                ->where('status', CreditNoteStatus::Approved->value)
                ->sum('amount_minor');

            if ($approved + $creditNote->amount->toKobo() > $invoice->total->toKobo()) {
                throw new BusinessRuleException(
                    'Approving this credit note would exceed the invoice total. At most '
                    .($invoice->total->toKobo() - $approved).' minor units can still be approved.'
                );
            }

            // Status flip (records decided_by = checker; the DB CHECK enforces ≠ maker).
            $creditNote->transitionTo(CreditNoteStatus::Approved, (int) $checker->id);

            // The compensating credit — negative amount, sourced to the credit note itself
            // (no allocation). SubledgerPoster's atomic increment (W1+W2) moves the balance;
            // any resulting negative balance is the wallet credit W3 carries forward.
            $this->ledger->post(
                $invoice->school_id,
                $invoice->student_id,
                LedgerEntryType::CreditNote,
                $creditNote->amount->times(-1),
                'credit_note',
                (int) $creditNote->getKey(),
                "Credit note #{$creditNote->displayNumber()} against invoice #{$invoice->number}",
                // TODAY, and this deliberately DIFFERS from ApproveVoidRequest, which back-dates
                // its reversal to the original charge. Flagged in this branch's report for the
                // project lead to overturn; the reasoning is CreditNoteKind's own docblock.
                //
                // A void asserts the charge never should have existed. A credit note asserts the
                // opposite: the charge was correct, and a NEW decision is being taken now to
                // forgive part of it. Both kinds this enum carries are present-tense judgements —
                // CreditNote is "goodwill, correction, an over-charge acknowledged … the money is
                // forgiven", WriteOff is "the receivable is JUDGED uncollectable". A receivable
                // becomes uncollectable at the moment someone judges it so; back-dating that to
                // the invoice's period would assert it was never collectable, restating a period
                // that was correct when it closed.
                //
                // The two therefore land in different periods on purpose. If Brookstone's
                // accounting policy says a credit note belongs to the period it corrects, this one
                // line changes — but it should change because the policy says so, not because it
                // matched its neighbour.
                SchoolDay::today(),
            );

            return $creditNote->refresh();
        });
    }
}
