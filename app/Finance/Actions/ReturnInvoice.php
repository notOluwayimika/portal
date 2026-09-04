<?php

namespace App\Finance\Actions;

use App\Exceptions\BusinessRuleException;
use App\Finance\Enums\InvoiceStatus;
use App\Finance\Models\Invoice;
use App\Models\User;
use App\Support\SchoolContext;
use Illuminate\Support\Facades\DB;

/**
 * INTERNAL AUDIT SENDS ONE BILL BACK TO FINANCE — the writer the return axis has never had.
 *
 * `2026_09_04_100000` added `returned_at` / `returned_by_user_id` / `return_reason` and the pairing
 * trigger that refuses a timestamp arriving without its two companions. It shipped the axis and
 * nothing else. This is its writer, and nothing else — no route, no page, no batch, the same
 * discipline `ApproveInvoice` shipped under.
 *
 * `ApproveInvoice` is the sibling and this mirrors it guard for guard. Where it deviates, the
 * deviation is named below rather than left to be noticed.
 *
 * ─── THE THREE COLUMNS ARE ONE WRITE, AND THE TRIGGER AND THIS ARRAY ARE ONE MECHANISM ──────────
 *
 * The sibling's argument is that `reviewed_at` with a NULL actor already MEANS something
 * (grandfathered), so a half-written pairing would be a fabricated audit record. The return axis
 * inherits that argument and adds a second, harder one: the migration installed
 * `finance_invoices_return_pairing_bi` / `_bu`, which SIGNAL 45000 when `returned_at` arrives
 * without both `return_reason` and `returned_by_user_id`. So the single `update()` below is not
 * merely the safe shape — it is the ONLY shape the database accepts. A writer setting the three
 * columns in two statements would be refused on the first one.
 *
 * Read from the other end, that is the point of the trigger: it is the floor under this array for
 * every writer that is not this action, including a DBA at a prompt.
 *
 * ─── NON-EMPTINESS IS THIS ACTION'S JOB, AND THE MIGRATION SAYS SO IN WORDS ─────────────────────
 *
 * `2026_09_04_100000`'s docblock assigns the split explicitly: "Presence is the schema's job;
 * non-emptiness is the action's." It declines an empty-string arm in the trigger because that would
 * be the only STRING comparison in it — and every string comparison in a finance trigger must carry
 * `COLLATE utf8mb4_bin` or `FinanceTriggerCollationTest` reds — to catch a case the action catches
 * first and better, because the action can name the field and the measured length. This is that
 * sentence being made good on, and it lives HERE rather than only in a future FormRequest for the
 * reason there is no endpoint yet: this action is callable off-request, so it is the only gate that
 * exists.
 *
 * `mb_strlen`, NOT `strlen`. The column is `VARCHAR(255)` under `utf8mb4`, which is 255 CHARACTERS;
 * `strlen` counts bytes and would refuse a legal reason containing any non-ASCII character — a
 * naira sign, a name with a diacritic — while reporting a length no operator can reconcile with
 * what they typed.
 *
 * REFUSE, NEVER TRUNCATE. A truncated reason is a sentence that stops mid-word in the one field
 * that tells Finance what to fix.
 *
 * ─── A RELEASED BILL IS NOT RETURNABLE, AND THE REFUSAL CARRIES THE REMEDY ──────────────────────
 *
 * A return WITHHOLDS a bill from a payer who has not seen it. Doing the same to a bill a parent is
 * already looking at is not a return, it is a REVERSAL — and reversal has its own audited path with
 * its own maker-checker pair (`finance_void_requests`, then a credit note). So the refusal names
 * that path rather than merely refusing: an auditor told "no" with no route forward will find one
 * that is not audited.
 *
 * The grandfathered shape is reported separately, exactly as `refuseIfAlreadyReleased` does. A stamp
 * with an actor is a colleague's signature; a stamp without one is the 31 August backfill, and
 * telling an auditor "released by user#null" would be false.
 *
 * ─── CONCURRENCY: BOTH PREDICATES RIDE IN THE COMPARE-AND-SWAP ──────────────────────────────────
 *
 * `whereNull('returned_at')` is the sibling's argument verbatim — the state guards are READS, and
 * between a read and the write another auditor can commit, so the affected-row count is the
 * authority and two auditors produce ONE return and one truthful refusal.
 *
 * `whereNull(Invoice::RELEASE_STAMP_COLUMN)` is the one this action adds, and it is what makes the
 * released-bill guard ATOMIC rather than advisory: an auditor who releases the bill in the window
 * between that read and this write must lose, and the row count is what says so. Without it the
 * guard is a read with nothing behind it, and the two axes could both be stamped on one row — a
 * state `2026_09_04_100000` records as one the system does not produce.
 *
 * On a lost race the fresh row is re-read and BOTH guards run again, so the caller is told which
 * one actually happened. The generic sentence is the last resort, not the first answer: a caller
 * told "nothing was changed" cannot act on it.
 *
 * AND THE `RELEASE_STAMP_COLUMN` PREDICATE IS UNREACHABLE BEHAVIOURALLY, WHICH IS WHY NO ARM REDS ON ITS
 * REMOVAL. Measured: mutation M2a deleted it and left the suite FULLY GREEN. That is not a gap in
 * the arms — the pre-check runs inside `lockForUpdate()` and refuses first, and a second connection
 * would not isolate it either, because the loser's pre-check reads post-commit state and speaks
 * first as well. So there is no behavioural gate to be had here, and inventing an arm for one would
 * assert a state that cannot be constructed.
 *
 * IT IS KEPT BECAUSE IT IS THE INVARIANT THAT SURVIVES A REFACTOR DROPPING THE LOCK — the day it
 * stops being redundant is the day nobody is reading this paragraph. Its PRESENCE is gated by
 * `tests/Arch/ReviewCompareAndSwapCarriesBothPredicatesTest.php`, which carries the full
 * eight-mutation matrix and is explicit that it gates presence and NOT behaviour. Anyone deleting
 * this predicate as redundant should read that file first.
 *
 * ─── THE REASON IS IN THE ACTIVITY PROPERTIES, AND THAT IS A RULING ─────────────────────────────
 *
 * Not a default. The reason is the ENTIRE PAYLOAD of the act: a row saying a bill was returned
 * without saying what was wrong with it records the event and loses the event. And because Phase B
 * lets Finance resubmit a corrected bill — after which a second return OVERWRITES all three
 * columns — this row is the only place the FIRST return's instruction will exist. The columns are
 * current state; the log is history, and here it is the only history.
 *
 * ─── THE PERMISSION GATE IS NOT `Authz`, AND THE EVENT NAME CARRIES A DOT ───────────────────────
 *
 * Both for the sibling's reasons, unchanged. `config('authz.enforce')` is false in every
 * environment, so an `Authz` check records a would-be denial and continues — a control born inert.
 * And `invoice.returned` gives the three-segment key `finance.invoice.returned`, matching the
 * PERMISSION it attests to (`finance.invoice.reject`) rather than its two-segment siblings.
 *
 * @activity-emits finance.invoice.returned
 */
final class ReturnInvoice
{
    /** The width of `finance_invoices.return_reason`, in CHARACTERS (VARCHAR(255), utf8mb4). */
    private const REASON_MAX = 255;

    /**
     * Return $invoice to Finance with $reason, attributed to $actor.
     *
     * @return Invoice the returned row, refreshed
     *
     * @throws BusinessRuleException when there is no active School context, the invoice belongs to
     *                               another School, the caller may not reject, the reason is empty
     *                               or over the column's width, the invoice is void, it has already
     *                               been released, or it has already been returned
     */
    public function handle(Invoice $invoice, User $actor, string $reason): Invoice
    {
        // Rule 13, and FIRST as in the sibling: the record is handed in directly, so nothing
        // filtered it and `require()` refuses outright when the caller established no context.
        SchoolContext::assertOwns($invoice, 'invoice', 'returned to Finance');

        if (! $actor->can('finance.invoice.reject')) {
            throw new BusinessRuleException(
                'You do not hold finance.invoice.reject and cannot return a bill to Finance.'
            );
        }

        // TRIMMED ONCE, HERE, AND THE TRIMMED VALUE IS WHAT IS STORED. Validating one string and
        // storing another is how a guard passes on input the column never receives.
        $reason = trim($reason);

        if ($reason === '') {
            throw new BusinessRuleException(
                'A return must say what Finance should correct; the reason cannot be empty.'
            );
        }

        if (mb_strlen($reason) > self::REASON_MAX) {
            throw new BusinessRuleException(
                'The return reason is '.mb_strlen($reason).' characters; the limit is '
                .self::REASON_MAX.'. Shorten it rather than letting it be cut off mid-sentence.'
            );
        }

        return DB::transaction(function () use ($invoice, $actor, $reason): Invoice {
            // The state guards read a LOCKED row and not the handed-in model, for the sibling's
            // stated reason: a controller resolves the invoice by route binding and a void — or a
            // release — can commit between that resolution and this line.
            $locked = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            // A void bill has been reversed in the ledger. There is nothing for Finance to correct
            // and the remedy has already been applied.
            if ($locked->status === InvoiceStatus::Void) {
                throw new BusinessRuleException(
                    'Invoice '.$locked->uuid.' is void; there is nothing for Finance to correct.'
                );
            }

            $this->refuseIfAlreadyReleased($locked);
            $this->refuseIfAlreadyReturned($locked);

            // THE COMPARE-AND-SWAP. Three columns in one array — the shape the pairing trigger
            // requires — and BOTH predicates in the same statement, so neither guard above is
            // merely advisory.
            $returned = Invoice::query()
                ->whereKey($locked->getKey())
                ->whereNull('returned_at')
                ->whereNull(Invoice::RELEASE_STAMP_COLUMN)
                ->update([
                    'returned_at' => now(),
                    'returned_by_user_id' => $actor->getKey(),
                    'return_reason' => $reason,
                ]);

            if ($returned !== 1) {
                // Lost the race. Re-read and run BOTH guards, so the sentence names whichever act
                // actually won rather than guessing which.
                $fresh = $locked->fresh();
                $this->refuseIfAlreadyReleased($fresh);
                $this->refuseIfAlreadyReturned($fresh);

                throw new BusinessRuleException(
                    'Invoice '.$locked->uuid.' could not be returned; nothing was changed.'
                );
            }

            $invoice->refresh();

            // Inside the transaction, for the sibling's reason: a return recorded without its
            // trail, and a trail describing a return that rolled back, are both worse than the
            // failure.
            activity('finance')
                ->performedOn($invoice)
                ->causedBy($actor)
                ->event('invoice.returned')
                ->withProperties([
                    'invoice_uuid' => $invoice->uuid,
                    'student_id' => $invoice->student_id,
                    'returned_at' => $invoice->returned_at?->toIso8601String(),
                    // THE PAYLOAD OF THE ACT, not a convenience. See the docblock: a second return
                    // overwrites the column, and this row is then the only place the first
                    // return's instruction exists.
                    'return_reason' => $invoice->return_reason,
                ])
                ->log('Invoice returned to Finance by Internal Audit');

            return $invoice;
        });
    }

    /**
     * Refuse a bill that has already been released to its payer, and say what to do instead.
     *
     * The two shapes are reported differently ON PURPOSE, exactly as `ApproveInvoice` does: a stamp
     * with an actor is a colleague's signature, a stamp without one is `2026_08_31_100000`'s
     * grandfathering, and naming "user#null" would be false.
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
            'Invoice '.$invoice->uuid.' was already released to its payer'
            .($reviewer === null
                ? ' before Internal Audit review existed (grandfathered by 2026_08_31_100000)'
                : ' by user#'.$reviewer)
            .' and cannot be returned; void it and issue a credit note instead.'
        );
    }

    /**
     * Refuse a bill already out with Finance, naming WHO returned it and WHEN.
     *
     * The sibling's lost-update argument, sharpened: overwriting would replace one auditor's stated
     * reason with another's and leave no trace of the first, and the reason is the entire content of
     * the act.
     *
     * @throws BusinessRuleException
     */
    private function refuseIfAlreadyReturned(?Invoice $invoice): void
    {
        if ($invoice === null || $invoice->returned_at === null) {
            return;
        }

        throw new BusinessRuleException(
            'Invoice '.$invoice->uuid.' was already returned to Finance by user#'
            .$invoice->returned_by_user_id.' on '.$invoice->returned_at->toDateString()
            .' and is awaiting correction.'
        );
    }
}
