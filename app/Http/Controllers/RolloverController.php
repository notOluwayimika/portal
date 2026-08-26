<?php

namespace App\Http\Controllers;

use App\Http\Requests\RolloverEndOfTermRequest;
use App\Http\Requests\RolloverEndOfYearRequest;
use App\Jobs\MoveFromCcmJob;
use App\Models\Curriculum;
use App\Services\Rollover\CcmFoldBatchName;
use App\Services\Rollover\PlacementGroup;
use App\Services\Rollover\RolloverBatchName;
use App\Services\Rollover\RolloverDispatcher;
use App\Services\Rollover\RolloverPlan;
use App\Services\Rollover\RolloverPlanner;
use App\Support\ActiveSchool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/**
 * The rollover operator surface — preview, commit, and batch progress.
 *
 * ── IT NEVER SHELLS OUT TO ARTISAN ───────────────────────────────────────────────────────────────
 * B4 forbids it and the reason is concrete rather than stylistic: a command's result is an exit code
 * with its detail printed to a console buffer, and this screen has to NAME the ring in a progression
 * cycle and LIST the offending CCM curricula. `Artisan::call` + `Artisan::output()` would mean
 * scraping terminal text to render a form error. RolloverPlanner returns both as data; the CLI and
 * this controller are two presentations of one plan.
 *
 * ── THE PREVIEW IS ADVISORY. DISPATCH RE-PLANS ───────────────────────────────────────────────────
 * This is the difference between the CLI and a screen, and the whole reason the plan/dispatch split
 * exists. The CLI plans and dispatches in one call, so nothing can change in between. The UI cannot:
 * preview -> the operator reads it -> the operator confirms, maybe minutes later -> dispatch.
 *
 * If dispatch trusted the previewed plan, a cycle introduced in the progression config after the
 * preview would be migrated straight past the gate that exists to catch it — and a rollover is the
 * one action where "what I was shown" and "what ran" diverging is unrecoverable, because it has
 * already moved every pupil in the school.
 *
 * So the commit endpoints re-run the planner and re-check `isRunnable()` against a plan computed
 * NOW, and dispatch the fresh one. The previewed plan is never carried across the request boundary
 * — there is no plan id, no signed payload, nothing to replay. That is deliberate: a token the
 * client returns is a token the server has to decide whether to trust, and re-planning is both
 * cheaper and unconditionally correct.
 *
 * ── QUEUED, NOT DONE ─────────────────────────────────────────────────────────────────────────────
 * `--commit` dispatches a batch and returns; the migration happens as workers drain the queue. Every
 * response says so, and the progress endpoint reports queued/done/failed rather than a boolean. A
 * registrar who reads "done" and switches the current session mid-drain is the failure this wording
 * exists to prevent.
 *
 * ── ATTRIBUTION ──────────────────────────────────────────────────────────────────────────────────
 * The operator is the authenticated user. Unlike the CLI there is no `--user` to resolve, and unlike
 * the reassignment controllers there is no impersonation subtlety to unpick here — but the jobs
 * still receive `$schoolId` explicitly and never read it from the causer (Constitution 13).
 */
class RolloverController extends Controller
{
    public function __construct(
        private readonly RolloverPlanner $planner,
        private readonly RolloverDispatcher $dispatcher,
    ) {}

    public function previewEndOfTerm(RolloverEndOfTermRequest $request): JsonResponse
    {
        return response()->json(
            $this->present($this->planner->planEndOfTerm($request->term()))
        );
    }

    public function commitEndOfTerm(RolloverEndOfTermRequest $request): JsonResponse
    {
        // RE-PLANNED HERE, not carried from the preview. See the class docblock.
        $plan = $this->planner->planEndOfTerm($request->term());

        if ($refusal = $this->refuse($plan)) {
            return $refusal;
        }

        $batch = $this->dispatcher->dispatchEndOfTerm($plan, $request->user());

        return response()->json($this->queued($plan, $batch->id));
    }

    public function previewEndOfYear(RolloverEndOfYearRequest $request): JsonResponse
    {
        return response()->json(
            $this->present($this->planner->planEndOfYear($request->sourceSession(), $request->targetSession()))
        );
    }

    public function commitEndOfYear(RolloverEndOfYearRequest $request): JsonResponse
    {
        $plan = $this->planner->planEndOfYear($request->sourceSession(), $request->targetSession());

        if ($refusal = $this->refuse($plan)) {
            return $refusal;
        }

        if ($refusal = $this->refuseUnacknowledgedDestinations($plan, $request->acknowledgedUnconfigured())) {
            return $refusal;
        }

        $batch = $this->dispatcher->dispatchEndOfYear($plan, $request->targetSession(), $request->user());

        return response()->json($this->queued($plan, $batch->id));
    }

    /**
     * FOLD the CCM curricula that are blocking this term's rollover.
     *
     * ── IT LIVES AT THE GATE BECAUSE THAT IS WHERE THE BLOCK IS FELT ────────────────────────────
     * The ccm-active gate names N classes and says they "must be moved first" — and until now the
     * only thing that moves them was an endpoint no screen called. That is a dead end for exactly
     * the operators who will meet it: the ones who configured a CCM slot rather than hand-creating
     * the curriculum, and who therefore have never touched the API or a console. Resolution belongs
     * where the refusal is read.
     *
     * ── THE BLOCKERS ARE RE-DERIVED, NEVER ACCEPTED FROM THE CLIENT ─────────────────────────────
     * Same discipline as the rollover commit: the plan is recomputed here, and the batch is built
     * from ITS ccmBlockers. A client-supplied list of curricula would be a second definition of
     * "what is blocking", and a stale one would fold a curriculum the gate is no longer naming.
     *
     * ── allowFailures, DELIBERATELY ─────────────────────────────────────────────────────────────
     * A fold can refuse — the silent-drop guard aborts when a scored CCM component has no non-CCM
     * counterpart — and that refusal is deterministic config, not a transient error. At a school
     * with marking schemes some folds will abort while others succeed, and the successful ones
     * SHOULD land: partial progress is real progress, and any remaining active CCM curriculum still
     * holds the gate up, which is correct. Halting the batch on the first refusal would throw away
     * folds that were fine.
     */
    public function foldCcm(RolloverEndOfTermRequest $request): JsonResponse
    {
        $term = $request->term();
        $plan = $this->planner->planEndOfTerm($term);

        if ($plan->ccmBlockers->isEmpty()) {
            return response()->json([
                'message' => 'Nothing to fold — no active CCM classes are blocking this rollover.',
                'plan' => $this->present($plan),
            ], 422);
        }

        $batch = Bus::batch(
            $plan->ccmBlockers->map(fn (Curriculum $c) => new MoveFromCcmJob(
                $c, (int) $request->user()->id, (int) $c->school_id,
            ))->all(),
        )->name(CcmFoldBatchName::forTerm((int) $term->school_id, (int) $term->id))
            ->allowFailures()
            ->dispatch();

        return response()->json([
            // QUEUED, never "folded". The batch has to drain before the gate can clear, and an
            // operator who reads "done" will confirm a rollover against folds still in flight.
            'message' => "Queued {$plan->ccmBlockers->count()} fold(s). The rollover stays blocked "
                .'until they finish — re-preview once the batch has drained.',
            'batch_id' => $batch->id,
            'batch_name' => CcmFoldBatchName::forTerm((int) $term->school_id, (int) $term->id),
            'queued_jobs' => $plan->ccmBlockers->count(),
        ], 202);
    }

    /**
     * Rollover batches for the active school, newest first.
     *
     * Reads `job_batches` through RolloverBatchName's own matcher rather than a hand-written LIKE —
     * a pattern that drifts from the writer shows "no batches running", which is indistinguishable
     * from a finished rollover while jobs are mid-flight.
     */
    public function batches(Request $request): JsonResponse
    {
        // ->id: getOrFail() returns the School model, and casting a model to int is not the id.
        $schoolId = (int) ActiveSchool::getOrFail()->id;

        $rows = DB::table('job_batches')
            ->where(function ($q) use ($schoolId) {
                $q->where('name', 'like', RolloverBatchName::likeForSchool($schoolId))
                    // FOLD batches appear in the same panel, because they are dispatched from the
                    // same screen to clear the gate that stops the rollover — an operator who
                    // clicks Fold and sees nothing drain has no way to know whether to wait.
                    ->orWhere('name', 'like', CcmFoldBatchName::likeForSchool($schoolId));
            })
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return response()->json([
            'data' => $rows->map(fn ($row) => [
                'id' => $row->id,
                'name' => $row->name,
                'kind' => CcmFoldBatchName::isFold($row->name) ? 'ccm-fold' : 'rollover',
                'total_jobs' => (int) $row->total_jobs,
                'pending_jobs' => (int) $row->pending_jobs,
                'failed_jobs' => (int) $row->failed_jobs,
                // DERIVED, and the words matter: a batch with pending jobs is DRAINING, never "done".
                // `done_jobs` counts SUCCESSES only — see settledState() for why pending is the
                // wrong subtrahend to read as progress once a job has failed.
                'done_jobs' => (int) $row->total_jobs - (int) $row->pending_jobs,
                'is_draining' => $this->settledState($row, $this->outstandingFailures($row)) === null,
                // 'finished' — every job succeeded. 'stopped' — every job has RESOLVED but some
                // failed, so no worker will touch this batch again without `queue:retry`.
                // 'cancelled' — killed. Null while genuinely still draining.
                'settled_state' => $this->settledState($row, $this->outstandingFailures($row)),
                'finished_at' => $row->finished_at,
                'cancelled_at' => $row->cancelled_at,
                // ── WHY IT FAILED, NOT JUST THAT IT DID ────────────────────────────────────────
                // A fold aborts on a DETERMINISTIC, config-shaped refusal: a CCM component carrying
                // marks with no non-CCM counterpart never succeeds on retry. "Job failed" would
                // unblock the operator from "there is no fold button" only to re-block them with
                // "it failed and I cannot tell you why" — the same dead end one layer in. The
                // guard's message names the curriculum and the component, which is an action the
                // operator can take, so it has to survive the queue and reach the panel.
                //
                // Note $tries = 3: a deterministic refusal is attempted three times before it lands
                // in failed_jobs, so what the panel must render is the REASON, not three errors.
                'failure_reasons' => $this->failureReasons($row),
            ])->values(),
        ]);
    }

    /**
     * Has this batch stopped moving, and how — read off the COUNTS, never off `finished_at` alone.
     *
     * ── THE SIGNAL THE FAILING CASE NEVER EMITS ──────────────────────────────────────────────────
     * `finished_at` is set in exactly ONE place: `Illuminate\Bus\Batch::recordSuccessfulJob`, and
     * only when `pendingJobs` reaches zero. `DatabaseBatchRepository::incrementFailedJobs` writes
     * `'pending_jobs' => $batch->pending_jobs` — an EXPLICIT no-op, deliberate rather than an
     * omission — so a permanently-failed job never decrements pending, `finished_at` stays null,
     * and a batch holding ANY failure is never "finished" as far as the framework is concerned.
     *
     * Read literally, `finished_at === null` therefore means "draining" FOREVER for such a batch:
     * the panel invites the operator to keep waiting on something no worker will touch again. A
     * drive against the real queue observed exactly that — three attempts exhausted,
     * `pending_jobs=1 failed_jobs=1 finished_at=null`, rendered as "Draining — do not change the
     * current session yet", permanently.
     *
     * ── WHY THIS IS NOT A CCM DEFECT ─────────────────────────────────────────────────────────────
     * Fold batches and ROLLOVER batches share this panel and this method. Nothing above is specific
     * to folds; a failed `MoveFromTermJob` batch reads identically. CCM is merely the first surface
     * where a batch failure is a DESIGNED, reachable outcome rather than an accident, which is why
     * it is the first to expose it. Fixing it here hardens the rollover surface retroactively.
     *
     * ── THE DERIVATION, AND WHY IT IS NOT THE `failed_jobs` COUNTER ──────────────────────────────
     * Pending starts at total and is decremented ONLY by successes, so `pending = total - successes`.
     * A job is outstanding when it has failed and has not since been resolved. Then:
     *
     *     in-flight = total - successes - outstanding = pending - outstanding
     *     terminal   <=>   in-flight === 0   <=>   pending === outstanding
     *
     * `$outstanding` MUST NOT come from `job_batches.failed_jobs`. That column is MONOTONE:
     * `decrementPendingJobs` prunes the uuid out of `failed_job_ids` on a retry-success but writes
     * `'failed_jobs' => $batch->failed_jobs` — unchanged. It counts failures EVER RECORDED, not
     * failures currently outstanding, and keying on it made this method wrong on both sides of a
     * retry: it called a genuinely finished batch "stopped" (the counter still reads 1 after the
     * retry succeeded), and it withdrew the draining warning while a worker was mid-flight. The
     * pre-change `finished_at === null` reading was CORRECT in that second window, so the first
     * version of this fix swapped which half of the retry window lied. Caught in cold review.
     *
     * See {@see outstandingFailures()} for how outstanding is counted, including the in-flight case.
     *
     * The clean path is SUBSUMED rather than special-cased: zero failures drives pending to zero and
     * `0 === 0` settles at the same instant `finished_at` is written. One rule, both paths.
     *
     * NOT "FINISHED" WHEN IT HOLDS FAILURES, and the word is chosen: those jobs are still pending in
     * the framework's own sense — awaiting a `queue:retry` a human may issue. 'stopped' says the
     * batch will not move on its own without saying it is complete, which it is not.
     *
     * ORDER IS LOAD-BEARING: `DatabaseBatchRepository::cancel()` writes BOTH `cancelled_at` and
     * `finished_at`, so the cancelled check must come first or every cancelled batch reads finished.
     *
     * @param  int  $outstanding  failures still unresolved — {@see outstandingFailures()}
     * @return 'finished'|'stopped'|'cancelled'|null null while genuinely still draining
     */
    private function settledState(object $row, int $outstanding): ?string
    {
        if ($row->cancelled_at !== null) {
            return 'cancelled';
        }

        if ($row->finished_at !== null) {
            return 'finished';
        }

        $pending = (int) $row->pending_jobs;

        // `<=` rather than `===` as a floor: outstanding should never EXCEED pending, and if some
        // accounting drift made it, "settled" is the safe reading — the alternative is the
        // perpetual-draining bug this method exists to remove.
        if ((int) $row->total_jobs > 0 && $outstanding > 0 && $pending <= $outstanding) {
            return 'stopped';
        }

        return null;
    }

    /**
     * Failures that are still outstanding — NOT `job_batches.failed_jobs`, and not merely the length
     * of `failed_job_ids` either.
     *
     * Two prunings happen at different times and only one of them is in the batch row:
     *
     *   - a retry that SUCCEEDS removes the uuid from `failed_job_ids` (`decrementPendingJobs`), so
     *     the id list is live where the counter is not;
     *   - but `queue:retry` deletes the `failed_jobs` ROW first (`RetryCommand::handle` pushes the
     *     job, then `$this->laravel['queue.failer']->forget($id)`) and the uuid STAYS in
     *     `failed_job_ids` until that retry resolves.
     *
     * So between `queue:retry` and the job finishing, the id is listed while its row is gone — and a
     * count of the ids alone would still call that batch stopped while a worker is executing it,
     * withdrawing "do not change the current session yet" at exactly the wrong moment. Counting only
     * ids that STILL HAVE a `failed_jobs` row makes an in-flight retry read as draining, which is
     * what it is.
     */
    private function outstandingFailures(object $row): int
    {
        $ids = json_decode($row->failed_job_ids ?? '[]', true) ?: [];

        if ($ids === []) {
            return 0;
        }

        return DB::table('failed_jobs')->whereIn('uuid', $ids)->count();
    }

    /**
     * The guard messages behind a batch's failures, de-duplicated.
     *
     * `job_batches.failed_job_ids` holds the uuids; `failed_jobs.exception` holds the throwable as a
     * string whose FIRST LINE is the class and message. Only that line is surfaced — a stack trace
     * on an operator screen is noise that hides the sentence they need.
     *
     * @return list<string>
     */
    private function failureReasons(object $row): array
    {
        if ((int) $row->failed_jobs === 0) {
            return [];
        }

        $ids = json_decode($row->failed_job_ids ?? '[]', true) ?: [];

        if ($ids === []) {
            return [];
        }

        return DB::table('failed_jobs')
            ->whereIn('uuid', $ids)
            ->pluck('exception')
            ->map(function (?string $exception) {
                // ── THE DESIGNED REFUSAL ARRIVES CLEAN; THIS IS THE FALLBACK ────────────────────
                // A fold's own refusal is an App\Exceptions\CcmFoldRefused, whose __toString() is
                // the message — so what Laravel persisted is already the sentence and nothing below
                // alters it. That is deliberate: repairing a stringified throwable HERE means a
                // path-stripping regex over a message that may itself contain " in ", which is a
                // heuristic that passes against a fixture and fails against reality.
                //
                // What remains is defence for an UNEXPECTED throwable — a deadlock, a timeout —
                // which still stringifies the PHP way. For those the operator gets the first line
                // rather than forty lines of trace, and yes, it still carries a path: that is a
                // debugging aid on a path nobody designed, not the refusal this surface is for.
                $first = trim(strtok((string) $exception, "\n"));

                return (string) preg_replace('/^[\w\\\\]+Exception:\s*/', '', $first);
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * A blocked plan is a 422 naming WHICH gate and carrying what the screen needs to explain it.
     *
     * Not a bare boolean: the cycle renders as the named ring with a link to the progression config,
     * and the CCM block renders as the offending classes with a link to the CCM move. An operator
     * told only "blocked" has to go and find out which of two rules they broke.
     */
    private function refuse(RolloverPlan $plan): ?JsonResponse
    {
        if ($plan->isRunnable() && ! $plan->isEmpty()) {
            return null;
        }

        if ($plan->isEmpty() && $plan->isRunnable()) {
            return response()->json([
                'message' => 'Nothing to migrate — no active non-CCM curricula were selected.',
                'plan' => $this->present($plan),
            ], 422);
        }

        return response()->json([
            'message' => 'This rollover cannot run yet.',
            'plan' => $this->present($plan),
        ], 422);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(RolloverPlan $plan): array
    {
        return [
            'kind' => $plan->kind,
            'is_runnable' => $plan->isRunnable(),
            'is_empty' => $plan->isEmpty(),
            'blocked_by' => $plan->blockedBy,
            'batch_name' => $plan->batchName,
            'pupil_count' => $plan->pupilCount,
            'curricula' => $plan->curricula->map(fn (Curriculum $c) => [
                'id' => $c->uuid,
                'label' => $this->describe($c),
            ])->values(),
            // ── THE THREE-STATE CYCLE RESULT ────────────────────────────────────────────────────
            // `progression_cycle: null` alone would mean BOTH "acyclic" and "we never ran the
            // check" — an end-of-term rollover does not consult the progression graph at all. The
            // client must be able to render "not applicable" without knowing which rollover kinds
            // evaluate which gates, so the applicability flag travels with the result.
            'progression_check_ran' => $plan->progressionCheckRan,
            'progression_is_acyclic' => $plan->progressionIsAcyclic(),
            'progression_cycle' => $plan->progressionCycle,
            'ccm_blockers' => $plan->ccmBlockers->map(fn (Curriculum $c) => [
                'id' => $c->uuid,
                'label' => $this->describe($c),
            ])->values(),
            // Which selected classes have nowhere to go, and why. Named rather than counted: "3
            // classes will not move" is not actionable on a screen listing twelve.
            'no_next_slot' => $plan->noNextSlot,
            'warnings' => $plan->warnings,
            // ── WHERE EVERY PUPIL LANDS ─────────────────────────────────────────────────────────
            // Empty for end-of-term, which keeps its class level and clones its subjects.
            'placement' => [
                'advancers' => $this->groups($plan->placement->advancers),
                'repeaters' => $this->groups($plan->placement->repeaters),
                'unplaceable' => $plan->placement->unplaceable->values(),
                // The leaving cohort. Sent so the panel's total can RECONCILE against pupil_count —
                // terminal pupils are counted by the plan and advanced by nobody, so omitting them
                // left the confirm's headline sitting above a table that summed to less.
                'graduating' => $plan->placement->graduating->values(),
                'accounted_pupils' => $plan->placement->accountedPupils(),
                'unconfigured_count' => $plan->placement->unconfiguredCount(),
                // ── THE ACKNOWLEDGMENT TOKEN, TO BE ECHOED BACK OPAQUELY ────────────────────────
                // The client MUST send this array back verbatim on commit. It must never rebuild it
                // from the rendered rows: the commit compares it against a freshly planned set built
                // by the same identity function, and a client-side reconstruction is a SECOND
                // implementation of that identity. Two implementations of an identity drift exactly
                // as two key-arrays would — and a drift here fails in the UNSAFE direction, because
                // a genuinely new unconfigured destination would be read as a match and placed.
                'unconfigured_keys' => $plan->placement->unconfiguredKeys(),
            ],
        ];
    }

    /**
     * REFUSE IF A DESTINATION BECAME UNCONFIGURED SINCE THE OPERATOR LOOKED. Runs BEFORE dispatch.
     *
     * ── WHY THIS EXISTS AT ALL: THE ACKNOWLEDGMENT USED TO BE DECORATIVE ────────────────────────
     * The commit re-plans, but `refuse()` gates only on isRunnable() and isEmpty(), and isRunnable()
     * derives from blockedBy — which holds exactly `progression-cycle` and `ccm-active`. An
     * unconfigured destination is neither. Worse, the commit contract was two session ids, so the
     * server received NO acknowledged state at all and could not have compared anything: divergence
     * was reported by queued(), AFTER dispatchEndOfYear had already run. A confirmation the server
     * never receives is theatre, and theatre is worse than nothing, because it manufactures the
     * confidence that stops anyone looking.
     *
     * ── A SUBSET CHECK, NOT A COUNT, AND THAT IS THE WHOLE POINT ────────────────────────────────
     * A count masks a SWAP: configure one destination and delete another between preview and
     * confirm, and the number is unchanged while a destination the operator never saw is about to
     * take pupils subject-less. What an operator accepts is not "there were N" — it is "I accept
     * THESE destinations being empty". So the fresh set must be a SUBSET of what was acknowledged.
     *
     * ── ASYMMETRIC, DELIBERATELY ────────────────────────────────────────────────────────────────
     * Removals proceed: a destination configured since the preview means the operator accepted MORE
     * risk than they now incur. Only ADDITIONS refuse. A symmetric check would refuse someone for
     * fixing the very thing they were warned about, which trains people to stop fixing it.
     *
     * ── SERVER-ENFORCED HERE AND NOWHERE ELSE ───────────────────────────────────────────────────
     * Not a whole-plan hash: that would also refuse benign divergence — a pupil count that moved
     * because someone enrolled a child — and the principle is to server-enforce divergence
     * precisely where post-write reporting is too late because the divergence is UNRECOVERABLE,
     * and to leave benign divergences to the client. A pupil landing subject-less cannot be undone
     * by the application; a changed count can simply be re-read.
     *
     * @param  list<string>  $acknowledged
     */
    private function refuseUnacknowledgedDestinations(RolloverPlan $plan, array $acknowledged): ?JsonResponse
    {
        // Both sides come from RolloverPlacement::unconfiguredKeys(), which is fed by the single
        // NextYearPlacement::destinationKey(). One identity function, never two — a second one
        // (notably a client rebuilding these from the rendered rows) would drift, and a drift here
        // fails UNSAFE: a genuinely new destination read as a match, and placed.
        $unacknowledged = array_values(array_diff($plan->placement->unconfiguredKeys(), $acknowledged));

        if ($unacknowledged === []) {
            return null;
        }

        return response()->json([
            'message' => count($unacknowledged) === 1
                ? 'A destination has become unconfigured since you previewed this rollover. Preview again before committing — pupils placed there would have no subjects.'
                : count($unacknowledged).' destinations have become unconfigured since you previewed this rollover. Preview again before committing — pupils placed there would have no subjects.',
            'unacknowledged_destinations' => $unacknowledged,
            'plan' => $this->present($plan),
        ], 422);
    }

    /**
     * @param  Collection<int, PlacementGroup>  $groups
     */
    private function groups(Collection $groups): array
    {
        return $groups->map(fn (PlacementGroup $g) => [
            'source' => $g->sourceLabel,
            'destination' => $g->destinationLabel,
            // A null id is not an error — it is "the rollover would create this", which means the
            // destination has no subjects and the pupils placed there would land with none.
            'destination_curriculum_id' => $g->destinationCurriculumId,
            'destination_key' => $g->destinationKey,
            'destination_is_unconfigured' => $g->destinationIsUnconfigured(),
            'pupil_count' => $g->pupilCount(),
            'pupils' => $g->pupils,
        ])->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function queued(RolloverPlan $plan, string $batchId): array
    {
        return [
            // QUEUED, never "done" or "complete". The migration runs as workers drain.
            'message' => "Queued {$plan->curricula->count()} job(s). The rollover is not finished "
                .'until this batch drains — do not change the current session yet.',
            'batch_id' => $batchId,
            'batch_name' => $plan->batchName,
            'queued_jobs' => $plan->curricula->count(),
            'plan' => $this->present($plan),
        ];
    }

    private function describe(Curriculum $curriculum): string
    {
        $arm = $curriculum->classLevelArm;

        if ($arm === null) {
            return '—';
        }

        return implode(' ', array_filter([
            $arm->classLevel?->name,
            $arm->arm?->label,
            $arm->stream?->name,
        ]));
    }
}
