<?php

namespace App\Finance\Enums;

use App\Finance\Actions\GenerateInvoice;
use App\Finance\Jobs\ProcessBulkInvoiceRun;
use App\Finance\Jobs\ProcessManualInvoiceRun;
use App\Finance\Models\ManualInvoiceRun;

/**
 * What a MANUAL invoice run made of ONE enrollment.
 *
 * FOUR VALUES, AND EVERY ONE OF THEM HAS A PRODUCER — which is the whole test a value has to pass.
 * {@see BulkInvoiceRunOutcome} has five; the one that is missing here was considered and refused
 * rather than forgotten, and the reasons are in
 * 2026_08_30_100000_create_finance_manual_invoice_run_tables.php: `already_billed` classifies a
 * refusal that the supplementary path never produces, and `sponsored` would exclude exactly the
 * students this feature was built to bill (so `sponsored` is absent as a SKIP, while a sponsored
 * student is billed like anyone else and lands in `Billed`).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * `Claimed` IS AN OUTCOME VALUE AND NOT A NULLABLE `claimed_at`, AND THE CHOICE WAS BETWEEN THOSE TWO
 *
 * The claim has to be visible in the row the instant it is inserted, because the insert is the whole
 * defence — {@see ProcessManualInvoiceRun} writes this row BEFORE it calls
 * {@see GenerateInvoice}, so that `UNIQUE(school_id, run_id, enrollment_id)` refuses a re-execution's
 * second attempt while there is still no invoice to undo. Two shapes could carry that state:
 *
 *   A — a nullable `claimed_at` timestamp, with `outcome` relaxed to NULL until the work finishes.
 *   B — a `claimed` value on the NOT NULL `outcome` column. **This is what was built.**
 *
 * B, for two reasons and neither is taste:
 *
 *   1. A NULLABLE `outcome` MAKES A CLAIM INDISTINGUISHABLE FROM A LOST WRITE. `outcome` is NOT NULL
 *      and its domain is enforced by a database trigger; relaxing it to admit NULL means the trigger
 *      must `COALESCE` a NULL through as legal, and from then on "the job claimed this row and died"
 *      and "something wrote a row with no outcome at all" are the same row. The state that has to be
 *      loudest is the one that would become quietest.
 *
 *   2. `created_at` ALREADY IS `claimed_at`. The row is INSERTED at the moment of the claim and never
 *      inserted at any other moment, so a separate timestamp column would be a second copy of a fact
 *      the table already holds — and a second copy is a thing that can disagree. How long a claim has
 *      been stuck is `now() - created_at`, exactly.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * A STUCK `Claimed` ROW IS A REAL, PERMANENT STATE — AND IT IS THE IMPROVEMENT, NOT THE DEFECT
 *
 * If the process dies between the claim and the outcome write, this row stays `claimed` forever:
 * that enrollment is not billed, and with `tries = 1` nothing retries it. There is no sweeper.
 * Anyone meeting one is meeting something that will not fix itself, so the comparison belongs here
 * rather than in whatever a reader reconstructs:
 *
 *   WHAT IT REPLACES. {@see ProcessBulkInvoiceRun} bills first (`:446`) and records after (`:593`),
 *   so the same death there leaves an INVOICE WITH NO ROW — money posted to a family's balance that
 *   the run's own counts do not know about, that nothing anywhere reports, and that a re-execution
 *   turns into a second charge. On the scheduled path the generated-column unique index catches that
 *   second charge; on the supplementary path nothing does, at any layer.
 *
 *   WHAT IT IS INSTEAD. A row with no invoice. Nobody is charged. The enrollment is NAMED. And the
 *   cohort equality goes red, because this value is deliberately not a term in it.
 *
 * A visible unknown in place of a silent double charge.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE COHORT EQUALITY, AND WHY `Claimed` IS NOT IN IT
 *
 *     billed + failed + unplaceable == target_count
 *
 * `target_count` is the size of the list the run walked — and, because a target is keyed on the
 * STUDENT, it is what the bursar ticked rather than what survived resolution. The three counts are
 * counted from the rows it persisted ({@see ManualInvoiceRun}).
 *
 * `Unplaceable` IS A TERM AND `Claimed` IS NOT, and the line between them is whether anything is
 * UNKNOWN. An unplaceable student is a finished, reported, correct outcome — the run looked, found
 * no current billable enrollment, and said so — so leaving it off the left would fire the alarm on a
 * healthy run, which is how an alarm gets learned-around and then ignored. A `claimed` row is the
 * opposite: the run does not know what happened to it. So it is absent from the left-hand side and
 * the equality goes short by exactly the number of stuck claims, with `claimed_count` recorded
 * beside the equality as the diagnosis. **Putting `claimed_count` on the left balances the sum on
 * precisely the runs the sum exists to catch.**
 *
 * THE DOMAIN IS ENFORCED BY A TRIGGER, NOT A `CHECK` — see {@see ManualInvoiceRunStatus}. The
 * trigger's value list is a literal, so a fourth case added here without its own migration is
 * refused by the database at insert time.
 */
enum ManualInvoiceRunOutcome: string
{
    /**
     * THE ROW EXISTS AND THE INVOICE DOES NOT, YET. Written before {@see GenerateInvoice} is called,
     * so the unique index refuses a second attempt at this enrollment before any money moves.
     * `invoice_id` and `reason` are both NULL and stay NULL until the work resolves.
     *
     * TERMINAL BY ACCIDENT, NEVER BY DESIGN: a row still holding this value after the run finished is
     * a claim whose process died, and it is the one state on this enum that means "unknown".
     */
    case Claimed = 'claimed';

    /** A supplementary invoice was raised for this enrollment by this run. `invoice_id` names it. */
    case Billed = 'billed';

    /**
     * Billing this one enrollment threw, and the run carried on. `reason` carries the message;
     * `invoice_id` is NULL because no invoice exists to name.
     */
    case Failed = 'failed';

    /**
     * THE BURSAR TICKED THIS STUDENT AND THEY RESOLVE TO NO CURRENT BILLABLE ENROLLMENT. The target
     * row carries `enrollment_id = NULL`; so does this row. Nothing was attempted, nothing failed,
     * and no invoice exists — `invoice_id` and `reason` are both NULL.
     *
     * THE NAME IS {@see BulkInvoiceRunOutcome::Unplaceable}'s, DELIBERATELY REUSED. The scheduled
     * run means "billable, but its term or class level is null, so no fee schedule can be keyed to
     * it"; this one means "selected, but there is no episode to bill". Both are "the run saw this
     * person and could not place them", both are reported rather than dropped, and a second word for
     * one idea is how two screens come to disagree about the same fact.
     *
     * IT IS WRITTEN IN ONE INSERT AND NEVER PASSES THROUGH {@see self::Claimed}. The claim exists to
     * bracket a call that moves money; there is no such call here, so there is no window to protect
     * and no second write that could strand the row. A target with no enrollment therefore cannot
     * become a stuck claim.
     */
    case Unplaceable = 'unplaceable';
}
