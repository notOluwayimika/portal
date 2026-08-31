<?php

namespace App\Finance\Http\Controllers;

use App\Finance\Actions\StartManualInvoiceRun;
use App\Finance\Contracts\BillableEnrollmentProvider;
use App\Finance\Enums\ManualInvoiceRunOutcome;
use App\Finance\Enums\ManualInvoiceRunStatus;
use App\Finance\Http\Requests\StoreManualInvoiceRunRequest;
use App\Finance\Http\Resources\ManualInvoiceRunResource;
use App\Finance\Jobs\ProcessManualInvoiceRun;
use App\Finance\Models\BankAccount;
use App\Finance\Models\ManualInvoiceRun;
use App\Finance\Models\ManualInvoiceRunLine;
use App\Finance\Models\ManualInvoiceRunRow;
use App\Finance\Models\ManualInvoiceRunTarget;
use App\Support\ActiveSchool;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;

/**
 * THE MANUAL INVOICE RUN'S TWO ENDPOINTS: start one, and read what it did.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * THE REPORT IS THE POINT OF THIS CLASS, NOT THE START
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * Brookstone ruled on 30 August 2026 that this feature issues DIRECTLY — no maker-checker, no second
 * signature. There is therefore no second human between a bursar's selection and ninety real charges,
 * and `show()` is the ONLY place a bursar can discover that they ticked 96 and 90 were billed. It is
 * built as the primary deliverable and not as a status page bolted on afterwards, and everything it
 * returns is chosen against one question: could a bursar reading this notice a wrong selection?
 *
 * FOUR THINGS THAT ANSWER IT, and each is here because a number alone would not:
 *
 *   1. `target_count` — counted from `finance_manual_invoice_run_targets`, which is the list the
 *      bursar submitted. It is THEIR number, the only figure on the page they can check against
 *      their own tick list, and it exists from the moment the run is created rather than from the
 *      moment it finishes.
 *   2. `counts` and `reconciliation.balances` — `billed + failed + unplaceable == target_count`,
 *      re-derived HERE from the rows table rather than read off the run's counters. Two independent
 *      sources is the only reason asserting the equality is worth anything: a sum computed from the
 *      same tally it is checking can only restate it.
 *   3. `counts.claimed`, SEPARATELY, and never as a term of that equality. It is the diagnosis — a
 *      claimed row is a student the run cannot account for, and it is exactly the shortfall.
 *   4. THE UNPLACEABLE, AND THE FAILED, BY NAME. Admission number, not a count. Brief §2 requires
 *      the unresolved be reported rather than dropped, and "six of your ninety could not be placed"
 *      is not something a bursar can act on. Six admission numbers is.
 *
 * `reconciliation.balances` IS NULL WHILE THE RUN IS NOT TERMINAL, and that is not squeamishness
 * about a boolean. Mid-run a shortfall is the normal state — rows are written as the list is walked
 * — so reporting `false` there would fire the alarm on every healthy run and teach a bursar to
 * ignore it. The question is not answerable yet, and null is how a wire says so.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * THE SECOND-RUN REFUSAL: THE GUARD IS THE DATABASE, THIS IS WHAT MAKES IT REACHABLE
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `finance_manual_invoice_runs` carries a STORED generated column `active_run_key` =
 * `IF(status IN ('pending','running'), school_id, NULL)` under a UNIQUE index, so a School's second
 * non-terminal run is refused by the engine with 1062. Left alone that reaches a bursar as
 * `bootstrap/app.php`'s generic map for the code — a 409 reading "Duplicate entry detected." —
 * which names nothing and suggests nothing.
 *
 * So the 1062 is CAUGHT and translated into a 422 that names the run already in flight and hands
 * over its uuid, so the operator can go and read what is happening rather than meeting a dead end.
 * Same shape as S11's `assertDestinationsChosen()`: the guard stays at the database, and the request
 * layer is what turns it into something a person can act on.
 *
 * THERE IS DELIBERATELY NO PRE-CHECK BESIDE IT, and that is a measured decision rather than an
 * omission. One was written — a `whereIn(status, [pending, running])` read before the insert — and
 * then removed, because it could not be told apart from the catch by any arm: with the pre-check
 * removed the refusal arm stayed GREEN, and with the CATCH removed it stayed green too. Redundant
 * twins, each sufficient, neither necessary, and neither individually provable. Keeping both would
 * have meant shipping two controls of which no test could see either.
 *
 * THE CATCH IS THE ONE THAT SURVIVED, for two reasons. It covers the race a pre-check cannot — two
 * bursars, two requests, one instant — and a pre-check is by construction a time-of-check /
 * time-of-use read, which is the thing the generated column exists to make unnecessary. The refusal
 * now rests on the database, and the arm that proves it goes red the moment this method stops
 * translating.
 *
 * KEYED ON THE INDEX NAME, not on 1062 alone — see the method.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * WHAT IS DELIBERATELY ABSENT
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * NO INDEX ROUTE. The two endpoints here are the two the brief names. A list of past runs is a
 * screen's requirement and arrives with the screen that needs it; front-loading it would be a
 * payload shaped by imagination rather than by a caller.
 *
 * NO DELETE AND NO CANCEL, for the reason `BulkInvoiceRunController` states and one worse: a run is
 * the record of a billing act, and it is the ONLY thing that accounts for the students a run did not
 * bill. Deleting one destroys the evidence. Nor is re-running a recovery path here — a supplementary
 * invoice has no duplicate backstop at any layer, so a second run over the same list bills everyone
 * again (docs/handoff/tickets/a-supplementary-invoice-has-no-duplicate-backstop.md).
 *
 * NO SPONSORED-STUDENT EXCLUSION, anywhere on this path, and it must never be copied in from the
 * scheduled run. That run excludes sponsored students, the predicate is shared with its preview and
 * pinned by a test, which makes it exactly the thing someone reuses. THIS FEATURE EXISTS PARTLY TO
 * BILL THEM — it is the mechanism that produces the C2C session bills
 * (`scholarship-and-cutover-decisions.md` §4). Reusing the cohort logic would drop the very students
 * the feature was built for.
 *
 * NO NEW ABILITY. Both routes carry `finance.invoice.generate`, the same one the single-student POST
 * and the four bulk-run routes carry: the authority to raise one invoice is the authority to raise
 * forty, and a `…generate-manual` minted here would be granted to precisely the roles that already
 * hold `generate` — deciding nothing, while adding a second case that can drift out of step.
 */
class ManualInvoiceRunController extends Controller
{
    /**
     * How many rows of any one outcome the report will name. The same 200 the bulk run's report uses,
     * with the same `truncated` flag beside it — a client that renders the flag says "and N more"
     * rather than quietly showing a short list.
     */
    private const ROWS_PER_BUCKET = 200;

    public function __construct(
        private readonly BillableEnrollmentProvider $enrollments,
        private readonly StartManualInvoiceRun $start,
    ) {}

    /**
     * Create the run, its lines and one target per selected student, then dispatch.
     *
     * THE DISPATCH IS AFTER THE ACTION RETURNS, so it is after the transaction commits. On the `sync`
     * queue an in-transaction dispatch would run the whole billing job inside an uncommitted
     * transaction; on a real queue a worker can beat the commit to the row.
     */
    public function store(StoreManualInvoiceRunRequest $request): JsonResponse
    {
        $schoolId = ActiveSchool::getOrFail()->id;

        try {
            $run = $this->start->handle(
                $schoolId,
                $request->selectedStudentIds(),
                $request->runLines(),
                $request->user()?->id,
            );
        } catch (QueryException $e) {
            $this->translateActiveRunCollision($e);

            throw $e;
        }

        ProcessManualInvoiceRun::dispatch($run->id, $schoolId);

        return response()->json(
            new ManualInvoiceRunResource($run->fresh()?->load('startedBy')),
            201,
        );
    }

    /**
     * THE RUN REPORT. Isolation is the route-model binding: `ManualInvoiceRun` is School-scoped, so
     * another School's uuid is a 404, and with the model in `rbac.fail_closed_models` a read with no
     * School context is a 409 rather than a silent unscoped answer.
     */
    public function show(ManualInvoiceRun $run): JsonResponse
    {
        $run->load('startedBy');

        /*
         * THE BURSAR'S OWN NUMBER, from the targets table. NOT `runs.target_count`: that column is
         * written by the job's reconciliation at the end of the walk, so it is null while the run is
         * in flight and it is the job's own tally rather than an independent one. This count exists
         * from the moment the run is created and is what the selection actually contained.
         */
        $targetCount = ManualInvoiceRunTarget::query()->where('run_id', $run->id)->count();

        $totals = ManualInvoiceRunRow::query()
            ->where('run_id', $run->id)
            ->selectRaw('outcome, COUNT(*) AS total')
            ->groupBy('outcome')
            ->pluck('total', 'outcome');

        $of = static fn (ManualInvoiceRunOutcome $outcome): int => (int) ($totals[$outcome->value] ?? 0);

        $billed = $of(ManualInvoiceRunOutcome::Billed);
        $failed = $of(ManualInvoiceRunOutcome::Failed);
        $unplaceable = $of(ManualInvoiceRunOutcome::Unplaceable);
        $claimed = $of(ManualInvoiceRunOutcome::Claimed);

        $accountedFor = $billed + $failed + $unplaceable;

        $buckets = [];

        foreach (ManualInvoiceRunOutcome::cases() as $outcome) {
            $rows = ManualInvoiceRunRow::query()
                ->where('run_id', $run->id)
                ->where('outcome', $outcome->value)
                ->with('invoice')
                ->orderBy('id')
                ->limit(self::ROWS_PER_BUCKET + 1)
                ->get();

            $buckets[$outcome->value] = [
                'total' => $of($outcome),
                'truncated' => $rows->count() > self::ROWS_PER_BUCKET,
                'rows' => $this->serializeRows($rows->take(self::ROWS_PER_BUCKET)->all()),
            ];
        }

        return response()->json(
            (new ManualInvoiceRunResource($run))->toArray(request()) + [
                'target_count' => $targetCount,

                'counts' => [
                    'billed' => $billed,
                    'failed' => $failed,
                    'unplaceable' => $unplaceable,

                    // SEPARATELY, and never a term of the equality below. See the class docblock.
                    'claimed' => $claimed,
                ],

                'reconciliation' => [
                    'accounted_for' => $accountedFor,

                    // Null while the run can still write rows — the question is not answerable yet.
                    'balances' => $run->status->isTerminal() ? $accountedFor === $targetCount : null,

                    /*
                     * The job's counters against the tables they describe. A disagreement means the
                     * reconciliation wrote a number the rows do not support, which is invisible to
                     * every check that reads only one of the two.
                     */
                    'recorded_matches_rows' => $run->target_count === null ? null : (
                        (int) $run->target_count === $targetCount
                        && (int) $run->billed_count === $billed
                        && (int) $run->failed_count === $failed
                        && (int) $run->unplaceable_count === $unplaceable
                        && (int) $run->claimed_count === $claimed
                    ),
                ],

                // What every student on the list was charged. One set of lines for the whole run.
                'lines' => $this->serializeLines($run),
            ] + ['buckets' => $buckets],
        );
    }

    /**
     * THE ONE-ACTIVE-RUN REFUSAL, made legible. See the class docblock for why there is only one of
     * these and not a pre-check beside it.
     *
     * Keyed on the index NAME as well as the code, because more than one unique index is reachable
     * from this write — the targets table's `(school_id, run_id, student_id)` and the lines table's
     * `(school_id, run_id, sort_order)` both answer 1062 — and only one of them means "a run is
     * already in flight". A bare code match would report a duplicate line ordering as an in-flight
     * run: a wrong diagnosis dressed as a helpful one. Anything else is re-thrown untouched.
     */
    private function translateActiveRunCollision(QueryException $e): void
    {
        if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
            return;
        }

        if (! str_contains((string) ($e->errorInfo[2] ?? ''), 'finance_manual_invoice_runs_active_run_unique')) {
            return;
        }

        $inFlight = ManualInvoiceRun::query()
            ->whereIn('status', [
                ManualInvoiceRunStatus::Pending->value,
                ManualInvoiceRunStatus::Running->value,
            ])
            ->orderByDesc('id')
            ->first();

        $this->refuseAsInFlight($inFlight instanceof ManualInvoiceRun ? $inFlight->uuid : null);
    }

    private function refuseAsInFlight(?string $uuid): never
    {
        throw ValidationException::withMessages([
            'run' => [
                $uuid === null
                    ? 'A manual invoice run is already under way for this school. Wait for it to finish, '
                        .'then read its report before starting another — a second run over the same list '
                        .'bills everyone on it again.'
                    : 'A manual invoice run is already under way for this school ('.$uuid.'). Wait for it '
                        .'to finish, then read its report before starting another — a second run over the '
                        .'same list bills everyone on it again.',
            ],
        ]);
    }

    /**
     * @param  list<ManualInvoiceRunRow>  $rows
     * @return list<array<string, mixed>>
     */
    private function serializeRows(array $rows): array
    {
        $studentIds = array_values(array_unique(array_map(
            static fn (ManualInvoiceRunRow $row) => (int) $row->student_id,
            $rows,
        )));

        /*
         * NAMED, NOT COUNTED. `displayFor()` carries the admission number, which is what a bursar
         * checks a selection against. A student who cannot be displayed at all — trashed, so outside
         * the live directory — still produces a row here with a null `student`, because dropping the
         * row would be the omission this whole report exists to make visible.
         */
        $display = $studentIds === [] ? [] : $this->enrollments->displayFor($studentIds);

        return array_map(static fn (ManualInvoiceRunRow $row) => [
            'uuid' => $row->uuid,
            'outcome' => $row->outcome->value,
            'student' => $display[(int) $row->student_id] ?? null,
            'enrollment_uuid' => $row->enrollment_uuid,
            'invoice_uuid' => $row->invoice?->uuid,
            'reason' => $row->reason,
        ], $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serializeLines(ManualInvoiceRun $run): array
    {
        $lines = ManualInvoiceRunLine::query()
            ->where('run_id', $run->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $accounts = BankAccount::query()
            ->whereIn('id', $lines->pluck('bank_account_id')->all())
            ->get(['id', 'uuid', 'label'])
            ->keyBy('id')
            ->map(static fn (BankAccount $account) => [
                'uuid' => (string) $account->uuid,
                'label' => $account->label,
            ])
            ->all();

        return $lines
            ->map(static fn (ManualInvoiceRunLine $line) => [
                'description' => $line->description,

                // The wire shape Money owns — `{amount_minor, currency}`, never a float and never a
                // bare integer whose unit a reader has to infer.
                'amount' => $line->amount->toArray(),

                // The destination S11 made mandatory, addressed the way the rest of the API
                // addresses an account. A line cannot exist without one: the column is NOT NULL.
                'bank_account' => $accounts[(int) $line->bank_account_id] ?? null,

                'sort_order' => (int) $line->sort_order,
            ])
            ->values()
            ->all();
    }
}
