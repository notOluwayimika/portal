<?php

namespace App\Http\Controllers;

use App\Http\Requests\RolloverEndOfTermRequest;
use App\Http\Requests\RolloverEndOfYearRequest;
use App\Models\Curriculum;
use App\Services\Rollover\RolloverBatchName;
use App\Services\Rollover\RolloverDispatcher;
use App\Services\Rollover\RolloverPlan;
use App\Services\Rollover\RolloverPlanner;
use App\Support\ActiveSchool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        $batch = $this->dispatcher->dispatchEndOfYear($plan, $request->targetSession(), $request->user());

        return response()->json($this->queued($plan, $batch->id));
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
            ->where('name', 'like', RolloverBatchName::likeForSchool($schoolId))
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return response()->json([
            'data' => $rows->map(fn ($row) => [
                'id' => $row->id,
                'name' => $row->name,
                'total_jobs' => (int) $row->total_jobs,
                'pending_jobs' => (int) $row->pending_jobs,
                'failed_jobs' => (int) $row->failed_jobs,
                // DERIVED, and the words matter: a batch with pending jobs is DRAINING, never "done".
                'done_jobs' => (int) $row->total_jobs - (int) $row->pending_jobs,
                'is_draining' => $row->finished_at === null && $row->cancelled_at === null,
                'finished_at' => $row->finished_at,
                'cancelled_at' => $row->cancelled_at,
            ])->values(),
        ]);
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
        ];
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
