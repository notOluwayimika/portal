<?php

namespace App\Finance\Http\Resources;

use App\Finance\Enums\BulkInvoiceRunStatus;
use App\Finance\Models\BulkInvoiceRun;
use App\Finance\Models\FeeSchedule;
use App\Models\ClassLevel;
use App\Models\Term;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One bulk invoice run on the wire (U6 commit 4) — the list row AND the head of the detail, which
 * are the same object read at two depths rather than two shapes. The detail's per-outcome rows are
 * assembled by the controller and merged in beside this; nothing here knows about them.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE ONE RULE THIS FILE EXISTS TO HOLD: **A NULL COUNT REACHES THE WIRE AS `null`.**
 *
 * Every one of the nine counts is nullable on the table and is NULL until {@see
 * ProcessBulkInvoiceRun::reconcile()} writes it. A `pending` run has never been picked up; a
 * `running` run is mid-cohort; a run failed by a per-run condition — no active schedule, a mapper
 * refusal, a death the worker reported — never reached the reconciliation at all, because
 * `writeFailure()` names three columns and none of them is a count.
 *
 * Casting any of those to `(int)` on the way out turns "this run has not said" into "this run says
 * zero", which is the §26 state-collapse defect (five recorded instances) committed in the payload
 * rather than in the screen — and a screen cannot recover a distinction its data no longer carries.
 * So the counts are emitted RAW, and `has_figures` below is the server's own answer to "may these be
 * rendered at all", so no consumer has to re-derive it from nine separate null checks.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * `has_figures` IS NOT `status === 'completed'`, AND THAT IS THE INTERESTING PART.
 *
 * A brief for this commit stated the rule as "a FAILED run shows its failure_reason and NO figures;
 * it has none", and that is true of four of the five routes into `failed` and false of the fifth.
 * The NOBODY-BILLED RULE ({@see ProcessBulkInvoiceRun::reconcile()}) writes all nine counts and THEN
 * sets `failed` in the same update — a run that walked a non-empty cohort and billed nobody is
 * `failed` and fully counted. {@see BulkInvoiceRunStatus} says so in its own words: "a `failed` run
 * must be READ, not assumed: check `cohort_count` and the row counts."
 *
 * Keying the screen off the STATUS would therefore hide the nine figures in the one failure case
 * where they are the entire diagnosis. Keying it off `cohort_count !== null` — the first column
 * `reconcile()` writes and one `writeFailure()` cannot — asks the question that is actually being
 * asked: has this run reported? That covers pending, running and the four count-less failures
 * identically, and admits the fifth.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * `reconciliation` IS THE RUN'S OWN ALARM, NOT A NEW COMPUTATION.
 *
 * The model's docblock names two equalities, each with a persisted-rows side and a walked-list side:
 *
 *     billed + already_billed + failed + sponsored == cohort_count
 *     unplaceable_count                            == unplaceable_listed_count
 *
 * Either can genuinely fail — a per-student row that could not be written is a per-student fault the
 * run survives ({@see ProcessBulkInvoiceRun::attempt()}), and the imbalance is the only thing that
 * says so. There is deliberately no flag column, so a screen that renders nine numbers without
 * stating whether they add up renders the alarm as decoration. It is computed here, from figures
 * already on the wire, so both sides of each equality travel with the verdict and a reader can
 * disagree with it.
 *
 * NO MONEY. A run reports counts; `finance_bulk_invoice_runs` carries no money column at all, by the
 * migration's own decision, so bin/ci-money-lint has nothing to be relevant to on this path.
 *
 * @mixin BulkInvoiceRun
 */
class BulkInvoiceRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // The single question "has this run reported its figures". `cohort_count` is the discriminator
        // rather than `status` — see the class docblock for the failure case that distinction exists
        // for. It is also the first count reconcile() writes, and all nine are written in one
        // statement, so it cannot be non-null while a sibling is null.
        $hasFigures = $this->cohort_count !== null;

        return [
            'uuid' => $this->uuid,
            'status' => $this->status->value,

            // The coordinates, in the words every other finance screen uses for them —
            // Term::displayLabel() and ClassLevel::name. Null only if the relation was not loaded
            // or the row has since gone, which the ids beside them still answer for.
            'term_id' => (int) $this->term_id,
            'class_level_id' => (int) $this->class_level_id,
            'term_label' => $this->whenLoaded('term', fn () => $this->term instanceof Term ? $this->term->displayLabel() : null),
            'class_level_label' => $this->whenLoaded('classLevel', fn () => $this->classLevel instanceof ClassLevel ? $this->classLevel->name : null),

            // WHICH PRICE LIST THIS RUN READ. Written by the job the moment one resolves, including
            // on the refusal path — so a failed run names the schedule it refused, which is the most
            // useful single fact about it. NULL means no active schedule existed at the coordinates.
            //
            // IT IS DISPLAY, NEVER A CHOICE. `active` is the only billable status, so there is
            // exactly one candidate per (term, class level) and there is nothing to pick between.
            'fee_schedule' => $this->whenLoaded('feeSchedule', fn () => $this->feeSchedule instanceof FeeSchedule ? [
                'uuid' => $this->feeSchedule->uuid,
                'label' => $this->feeSchedule->label,
                'status' => $this->feeSchedule->status->value,
            ] : null),

            'started_by_name' => $this->whenLoaded('startedBy', fn () => $this->startedBy instanceof User ? $this->startedBy->name : null),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),

            // PER-RUN ONLY. A student who could not be billed carries their reason on their row and
            // never reaches this column.
            'failure_reason' => $this->failure_reason,

            // The server's answer to "may the figures be rendered", so no consumer re-derives it.
            'has_figures' => $hasFigures,

            // RAW. No (int) cast, no ?? 0 — see the class docblock. When `has_figures` is false
            // every one of these is null and the screen shows no figures at all.
            'counts' => [
                'cohort' => $this->cohort_count,
                'billed' => $this->billed_count,
                'already_billed' => $this->already_billed_count,
                'failed' => $this->failed_count,
                'sponsored' => $this->sponsored_count,
                'unplaceable_listed' => $this->unplaceable_listed_count,
                'unplaceable' => $this->unplaceable_count,
                'billable' => $this->billable_count,
                // SIGNED, and NOT "students missed". Billable students this run did not enumerate
                // because they are priced at other coordinates — on a single-level run in a
                // seven-level school that is roughly six-sevenths of the roster, on EVERY healthy
                // run. The screen words it as scope, never as a miss.
                'outside_coordinates' => $this->outside_coordinates_count,
            ],

            // The two equalities, or null when there is nothing to check. See the class docblock.
            'reconciliation' => $hasFigures ? [
                'cohort_balances' => (int) $this->billed_count + (int) $this->already_billed_count + (int) $this->failed_count + (int) $this->sponsored_count === (int) $this->cohort_count,
                'unplaceable_balances' => (int) $this->unplaceable_count === (int) $this->unplaceable_listed_count,
            ] : null,
        ];
    }
}
