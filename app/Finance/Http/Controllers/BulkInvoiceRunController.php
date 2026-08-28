<?php

namespace App\Finance\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Finance\Contracts\BillableEnrollmentProvider;
use App\Finance\Enums\BulkInvoiceRunOutcome;
use App\Finance\Enums\BulkInvoiceRunStatus;
use App\Finance\Http\Requests\BulkInvoiceRunCoordinatesRequest;
use App\Finance\Http\Resources\BulkInvoiceRunResource;
use App\Finance\Jobs\ProcessBulkInvoiceRun;
use App\Finance\Models\BulkInvoiceRun;
use App\Finance\Models\BulkInvoiceRunRow;
use App\Finance\Models\FeeSchedule;
use App\Finance\Services\CohortScholarshipSchemes;
use App\Finance\Services\FeeScheduleLineMapper;
use App\Finance\Services\FeeScheduleLookup;
use App\Finance\Services\InvoiceReadModel;
use App\Models\ClassLevel;
use App\Models\Term;
use App\Support\ActiveSchool;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * U6 commit 4 — THE OPERATOR SURFACE for a bulk invoice run: preview, start, list, read back.
 *
 * IT ADDS NO DOMAIN. Every rule it reports was decided and proven in commit 3 — the cohort read, the
 * unplaceable read, the population count, the schedule lookup, the mapper's five refusals and the
 * job itself. This file resolves, delegates and serialises; the only thing it decides is what a
 * bursar is shown BEFORE they commit.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE PREVIEW EXISTS BECAUSE THE START IS IRREVERSIBLE IN PRACTICE
 *
 * Nothing undoes a bulk run. Each invoice it raised is undone by its own maker-checker void request
 * — one submission and one approval PER CHILD — so a run over a class level of forty that should
 * never have been started is forty two-signature reversals. That asymmetry is the whole argument for
 * {@see preview()}: it creates no run row, dispatches nothing, and answers the three questions whose
 * answers change the decision.
 *
 * IT ALSO SURFACES THE REFUSALS BEFORE THE COMMIT, WHICH IS THE PART THAT IS NOT MERELY POLITE. The
 * job discovers a missing schedule or a mapper refusal AFTER a run row exists — correctly, since a
 * refused run is a record worth keeping — but an operator who can read the refusal first never
 * creates that row. The words are the SAME words: {@see FeeScheduleLineMapper::linesFor()}'s own
 * exception message is passed through verbatim, and the no-schedule sentence is the one
 * {@see ProcessBulkInvoiceRun::process()} writes. A second wording of a refusal is a second thing
 * that can disagree with the job about why a run cannot happen.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * ONE ABILITY, `finance.invoice.generate`, ON ALL FOUR ROUTES — AND NO NEW PERMISSION
 *
 * Bulk raises the same document, from the same Action, under the same rule; a `finance.invoice.
 * generate-bulk` minted now would be held by exactly the roles that already hold `generate` (a
 * grants commit deciding nothing) while adding a case that can drift out of step with it. The
 * reads carry the same ability as the write for the reason the opening-balance maker's five routes
 * do: the person who starts a run is the person who reads it back, and a seat that could read a run
 * list without being able to start one is a seat nobody has.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * ISOLATION IS `BelongsToSchool` ON THE TWO MODELS, and this controller adds no `where('school_id')`
 * of its own — the same reliance the four sibling controllers place on it. What it does NOT rely on
 * is the scope deciding which School's TERM you may name: `Rule::exists(...)->where('school_id')` in
 * {@see BulkInvoiceRunCoordinatesRequest} does that, because a scope on the run cannot see a foreign
 * id written INTO the run.
 */
class BulkInvoiceRunController extends Controller
{
    /**
     * How many rows of ONE outcome bucket the detail payload carries before it is cut.
     *
     * PER BUCKET, NOT PER RUN, and that is the whole reason it is not a single flat cap. The
     * unplaceable list is School-wide and is the ONE actionable list on the screen — those students
     * cannot be billed by any run until someone fixes their term or class level — while `billed` is
     * routinely the largest bucket and the least interesting row by row. A flat cap sorted by id
     * would let a large `billed` bucket push the actionable list off the payload entirely.
     *
     * TRUNCATION IS ANNOUNCED, per bucket, for the reason the opening-balance report announces its
     * own: a cut list that looks complete is how a partial report becomes a false all-clear.
     */
    private const ROWS_PER_BUCKET = 200;

    /**
     * How many runs the list carries. The screen is an operating surface for the current term's
     * billing, not an archive; `orderByDesc('id')` puts the run just started at the top.
     */
    private const RUNS_ON_LIST = 25;

    public function __construct(
        private readonly BillableEnrollmentProvider $enrollments,
        private readonly FeeScheduleLookup $schedules,
        private readonly FeeScheduleLineMapper $mapper,
        private readonly InvoiceReadModel $invoices,
        private readonly CohortScholarshipSchemes $schemes,
    ) {}

    /**
     * WHAT WOULD HAPPEN — read-only, creates nothing, dispatches nothing.
     *
     * Three figures and one refusal:
     *
     *   `schedule`        the version that WOULD be pinned. Resolved through the one lookup the job
     *                     uses, so "there is exactly one candidate" is a property of the read rather
     *                     than an assertion on this screen. DISPLAY, NOT A CHOICE — `active` is the
     *                     only billable status, so a dropdown here would have one option in it.
     *   `cohort_size`     how many billable enrollments sit at these coordinates.
     *   `sponsored`       how many of THAT cohort are on a sponsored scheme, whom an outside body
     *                     pays off platform — the run records them and does not bill them.
     *   `already_billed`  how many of THAT cohort already carry an active scheduled invoice, i.e.
     *                     how many the run would record as `already_billed` and not bill again.
     *   `would_bill`      how many invoices the run would actually raise. Cohort minus the two
     *                     buckets above.
     *   `refusal`         the sentence the run would fail with, in the job's own words, or null.
     *
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * `sponsored` AND `would_bill` EXIST BECAUSE THE SCREEN OVERSTATED THE ACT IT WAS ABOUT TO
     * COMMIT. The 2026-08-28 money drive read "WOULD BE BILLED 5" over a cohort of five holding one
     * sponsored student; the run billed four. On the drive fixture that is off by one. In school#1
     * ninety-one students sit on one sponsored scheme, so the same arithmetic overstates by however
     * many of them a run's cohort contains — a number too small to look broken and too large to be
     * harmless, on the LAST HUMAN CHECK before hundreds of append-only invoices are raised.
     *
     * THE SUBTRACTION IS NOT THE WHOLE FIX. A bursar reading "520 to bill" learns less than one
     * reading "520 to bill · 91 sponsored, billed by hand": the second says the exclusion happened,
     * how big it was, and that it was deliberate, which is what lets them sanity-check the figure
     * instead of trusting it. So the excluded count is REPORTED, not silently netted off.
     *
     * `sponsored` IS SETTLED BEFORE `already_billed`, IN THE JOB'S OWN ORDER. The run tests the
     * scholarship scheme FIRST and writes a `sponsored` row without ever asking the invoice
     * question, so a sponsored student who already carries an invoice is `sponsored` to the run and
     * must be `sponsored` and not `already_billed` here. Counting them in both would make
     * `would_bill` understate by exactly the overlap, which is the same defect pointed the other
     * way.
     *
     * AND `would_bill` IS COMPUTED HERE, NOT ON THE CLIENT. The screen used to subtract
     * `cohort_size - already_billed` in the render, which is a second place that has to learn about
     * every new exclusion the job grows. It is the wire that carries the number now.
     *
     * `already_billed` IS N QUERIES OVER THE COHORT, AND THAT IS THE DELIBERATE CHOICE OF THE TWO.
     * {@see InvoiceReadModel::activeScheduledInvoiceIdForEnrollment()} is THE one PHP expression of
     * "does this episode already carry an active scheduled invoice", shared by the modal's preview,
     * GenerateInvoice's pre-check and the job. The alternative — a single `whereIn` here — would be
     * a fourth copy of that predicate, and the last time two copies of it existed they disagreed:
     * one gained the `kind` filter and the other did not, and a bursar was told to void an invoice
     * that the write it warned about then succeeded against. N is one class level's cohort, this
     * runs on an operator's explicit click, and a slow preview is a cheaper problem than a preview
     * that can be wrong. Making it one query means giving the read model a batch form of the same
     * expression — worth doing, and not worth doing inside a screen commit.
     *
     * THE REFUSAL IS COMPUTED IN THE JOB'S ORDER, so it reports the FIRST thing that would stop the
     * run rather than the most visible one: no active schedule, then the mapper's five (foreign
     * schedule, disagreeing ambient context, non-billable status, no mandatory items, mixed
     * currency), then the cohort's own — a scholarship with no `kind`, or one this School cannot
     * read. A preview naming a second-order refusal would send the operator to fix something that
     * is not yet what is in the way.
     *
     * THE COHORT-LEVEL REFUSAL IS THIRD BECAUSE IT IS THIRD IN THE JOB, and it was missing from
     * this screen entirely until the sponsored count was added — the same omission, one predicate
     * over. Without it the preview offered a start button on a run that would fail before billing
     * anybody, since {@see ProcessBulkInvoiceRun::process()} refuses the WHOLE
     * run when any cohort scholarship is unconfigured rather than billing the students it is sure
     * about.
     */
    public function preview(BulkInvoiceRunCoordinatesRequest $request): JsonResponse
    {
        $schoolId = ActiveSchool::getOrFail()->id;
        $termId = $request->termId();
        $classLevelId = $request->classLevelId();

        $schedule = $this->schedules->activeFor($termId, $classLevelId);

        $refusal = null;
        $mandatoryItems = null;

        if (! $schedule instanceof FeeSchedule) {
            // VERBATIM from ProcessBulkInvoiceRun::process(). Same failure, same sentence.
            $refusal = 'No active fee schedule exists at these coordinates, so there is no price list to bill from.';
        } else {
            try {
                // The mapper is PURE — no write, no queue, no HTTP — so calling it here is a genuine
                // dry run of the thing the job will do, not a re-implementation of its checks. The
                // School is an ARGUMENT, as it is in the job, and the ambient context is this
                // request's own School, so the mapper's second guard is satisfied by equality.
                $mandatoryItems = count($this->mapper->linesFor($schedule, $schoolId));
            } catch (BusinessRuleException $e) {
                $refusal = $e->getMessage();
            }
        }

        // Computed regardless of the refusal: "who is in this cohort" is a fact about the roster and
        // does not depend on whether a price list exists. An operator whose schedule is not yet
        // published still wants to know the cohort they are about to price.
        $cohort = $this->enrollments->listForCohort($schoolId, $termId, $classLevelId);

        // THE JOB'S OWN SOURCE, NOT A SECOND COPY OF THE RULE. {@see CohortScholarshipSchemes} is
        // what ProcessBulkInvoiceRun reads to decide who is sponsored and whether the cohort's
        // scholarships refuse the run; this is the SECOND predicate this preview shares with the
        // job rather than restates, for the reason recorded above `already_billed`. Re-deriving
        // "sponsored" here would be that same disagreement waiting to happen, on the figure an
        // operator uses to sanity-check a run they cannot undo.
        //
        // ITS PERFORMANCE ARGUMENT IS THE ONE ALREADY MADE FOR `already_billed`: this is two
        // queries over one class level's cohort, on an operator's explicit click, and a slow
        // preview is a cheaper problem than a preview that can be wrong.
        $schemes = $this->schemes->forCohort($cohort, $schoolId);

        // ONLY IF NOTHING EARLIER REFUSED — the job settles the schedule and the mapper before it
        // ever reads the cohort, so reporting this one over a missing schedule would name the
        // second thing in the way.
        $refusal ??= $schemes['refusal'];

        $sponsored = 0;
        $alreadyBilled = 0;

        foreach ($cohort as $enrollment) {
            // SPONSORED FIRST, and `continue`, because that is the order the run applies: a
            // sponsored student gets a `sponsored` row and the invoice question is never asked of
            // them. Counting one student in both buckets would make `would_bill` understate.
            if ($this->schemes->isSponsored($enrollment, $schemes['byStudent'])) {
                $sponsored++;

                continue;
            }

            if ($this->invoices->activeScheduledInvoiceIdForEnrollment($enrollment->enrollmentId, $schoolId) !== null) {
                $alreadyBilled++;
            }
        }

        return response()->json([
            'term_id' => $termId,
            'class_level_id' => $classLevelId,
            'term_label' => Term::query()->with('academicSession')->find($termId)?->displayLabel(),
            'class_level_label' => ClassLevel::query()->find($classLevelId)?->name,
            'schedule' => $schedule instanceof FeeSchedule ? [
                'uuid' => $schedule->uuid,
                'label' => $schedule->label,
                'status' => $schedule->status->value,
                // NULL when the mapper refused — there is no line count for a schedule that cannot
                // produce lines, and a 0 here would read as "a schedule with no items" on the four
                // refusals that have nothing to do with items.
                'mandatory_item_count' => $mandatoryItems,
            ] : null,
            'refusal' => $refusal,
            'cohort_size' => count($cohort),
            'sponsored' => $sponsored,
            'already_billed' => $alreadyBilled,
            // THE FIGURE THE CONFIRM BUTTON IS A SENTENCE ABOUT. Derived from the three counts
            // beside it rather than counted a fourth time, so the four cannot disagree.
            'would_bill' => count($cohort) - $sponsored - $alreadyBilled,
        ]);
    }

    /**
     * START. Inserts the run in `pending` and dispatches — the order commit 3 requires, since the
     * run row IS the job record and the job resolves it by id.
     *
     * A SECOND RUN AT THE SAME COORDINATES IS PERMITTED, and that is a decision rather than a gap.
     * Re-running is the documented recovery path after a partial failure: `UNIQUE(school_id,
     * active_enrollment_key)` on `finance_invoices` refuses a second active scheduled invoice per
     * episode, so nobody can be double-billed, and the students already done come back as
     * `already_billed` rather than as failures. A uniqueness guard here would block the recovery
     * path to prevent something the engine already prevents.
     *
     * `started_by_user_id` is a LOOKUP written for ATTRIBUTION — the job reads it to set the activity
     * causer and passes it to GenerateInvoice. It is never an execution identity (Constitution 13);
     * the run's School is the argument on the job and `SchoolAware` is what establishes context.
     */
    public function store(BulkInvoiceRunCoordinatesRequest $request): JsonResponse
    {
        $run = BulkInvoiceRun::create([
            'term_id' => $request->termId(),
            'class_level_id' => $request->classLevelId(),
            'status' => BulkInvoiceRunStatus::Pending,
            'started_by_user_id' => $request->user()?->id,
        ]);

        ProcessBulkInvoiceRun::dispatch($run->id, (int) $run->school_id);

        // Re-read: on the `sync` queue the job has already run to completion by the time dispatch()
        // returns, so the row this responds with is the finished one rather than the `pending` one
        // that was inserted a line earlier. On a real worker it is still `pending`, and the screen
        // polls. Either way the response describes the row as it stands.
        return response()->json(
            new BulkInvoiceRunResource($run->fresh()?->load(['term.academicSession', 'classLevel', 'feeSchedule', 'startedBy'])),
            201,
        );
    }

    /**
     * The School's recent runs. Isolation is `BelongsToSchool` on the model — the same reliance the
     * four sibling controllers place on it, and the reason there is no explicit `where` to forget.
     */
    public function index(): JsonResponse
    {
        $runs = BulkInvoiceRun::query()
            ->with(['term.academicSession', 'classLevel', 'feeSchedule', 'startedBy'])
            ->orderByDesc('id')
            ->limit(self::RUNS_ON_LIST)
            ->get();

        return response()->json(['data' => BulkInvoiceRunResource::collection($runs)]);
    }

    /**
     * ONE RUN, WITH ITS ROWS BUCKETED BY OUTCOME — and this is also the poll: the screen re-fetches
     * it while `status` is `pending` or `running`, and stops at the two terminal states.
     *
     * A FOREIGN-SCHOOL RUN IS A 404, NOT A 403, and the difference is the binding rather than a
     * decision taken here. `BulkInvoiceRun` carries `BelongsToSchool`, so `SchoolScope` filters the
     * implicit route-model lookup by the active School and another School's uuid simply resolves to
     * no model. That is the right answer as well as the automatic one: 403 would confirm that a run
     * with that uuid exists somewhere.
     *
     * THE BUCKET TOTALS ARE COUNTED FROM THE ROWS, NOT READ OFF THE RUN. They are two different
     * facts and the screen states them separately: the run's `counts` are what the RUN reported when
     * it finished, and these are what is in `finance_bulk_invoice_run_rows` right now. On a healthy
     * finished run they agree; while a run is still going the counts are null and these are what has
     * been written so far; and where they disagree on a finished run, that disagreement IS the run's
     * own alarm (see the Resource's `reconciliation`).
     */
    public function show(BulkInvoiceRun $run): JsonResponse
    {
        $run->load(['term.academicSession', 'classLevel', 'feeSchedule', 'startedBy']);

        $totals = BulkInvoiceRunRow::query()
            ->where('run_id', $run->id)
            ->selectRaw('outcome, COUNT(*) AS total')
            ->groupBy('outcome')
            ->pluck('total', 'outcome');

        $buckets = [];

        foreach (BulkInvoiceRunOutcome::cases() as $outcome) {
            $rows = BulkInvoiceRunRow::query()
                ->where('run_id', $run->id)
                ->where('outcome', $outcome->value)
                ->orderBy('id')
                ->limit(self::ROWS_PER_BUCKET + 1)
                ->get();

            $buckets[$outcome->value] = [
                'total' => (int) ($totals[$outcome->value] ?? 0),
                'truncated' => $rows->count() > self::ROWS_PER_BUCKET,
                'rows' => $this->serializeRows($rows->take(self::ROWS_PER_BUCKET)->all()),
            ];
        }

        return response()->json(
            (new BulkInvoiceRunResource($run))->toArray(request()) + ['buckets' => $buckets],
        );
    }

    /**
     * Rows for the screen, with the student resolved THROUGH THE ACL PORT.
     *
     * Finance holds `student_id` and owns no name, no admission number and no student uuid — those
     * are Academics facts and must never be re-joined from a Finance query (arch rule 3). So the
     * display comes from {@see BillableEnrollmentProvider::displayFor()}, in one batched call, the
     * same way the accounts index resolves its rows.
     *
     * THE UUID IS WHAT MAKES THE U7 SLICE POSSIBLE. A `billed` row links to that student's existing
     * statement screen, which already lists their invoices; the statement route is keyed on the
     * student uuid, which Finance cannot mint and the port returns.
     *
     * `student` IS NULL FOR TWO DIFFERENT REASONS AND THE SCREEN SAYS SO. Either the episode has no
     * `student_id` at all — schema-legal, and one of the two shapes the reconciliation exists to
     * surface — or the id no longer resolves through the School-scoped port. The row still renders,
     * carrying its enrollment uuid, because a row that vanishes because its student cannot be named
     * is the silent-omission defect.
     *
     * @param  list<BulkInvoiceRunRow>  $rows
     * @return list<array<string, mixed>>
     */
    private function serializeRows(array $rows): array
    {
        $studentIds = array_values(array_unique(array_filter(
            array_map(fn (BulkInvoiceRunRow $row) => $row->student_id, $rows),
            fn (?int $id) => $id !== null,
        )));

        $display = $studentIds === [] ? [] : $this->enrollments->displayFor($studentIds);

        return array_map(fn (BulkInvoiceRunRow $row) => [
            'uuid' => $row->uuid,
            'enrollment_uuid' => $row->enrollment_uuid,
            'outcome' => $row->outcome->value,
            // Non-null ONLY on `failed`. The run-level `failure_reason` is a different column and a
            // different fact; neither is ever rendered in the other's place.
            'reason' => $row->reason,
            'student' => $row->student_id === null ? null : ($display[$row->student_id] ?? null),
        ], $rows);
    }
}
