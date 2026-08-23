<?php

namespace App\Services\Rollover;

use App\Models\AcademicSession;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\ClassLevelTermParticipation;
use App\Models\Curriculum;
use App\Models\Scopes\SchoolScope;
use App\Models\Term;
use App\Services\ProgressionGraph;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Given a term or a pair of sessions: what the rollover would migrate, and whether it may run.
 *
 * ── ONE PLAN, TWO CALLERS ────────────────────────────────────────────────────────────────────────
 * `academics:run-end-of-term` / `run-end-of-year` and (slice 2) `RolloverController` both plan
 * through here. Before this class the selection and the gates lived inside the commands, so a UI
 * could only reach them by shelling out to artisan — which B4 forbids — or by re-deriving them,
 * which is how a screen ends up offering a rollover the CLI would refuse.
 *
 * ── IT PLANS; IT DOES NOT DISPATCH ───────────────────────────────────────────────────────────────
 * `Bus::batch` stays in the caller. That is what makes "a preview cannot dispatch" true by
 * CONSTRUCTION rather than by remembering — there is no code path from this class to a queue, so
 * slice 2's `assertNothingBatched` on the preview endpoint is asserting a structural fact rather
 * than an implementation detail that a later edit could quietly reverse.
 *
 * ── THE CYCLE GATE CALLS THE WALK DIRECTLY, AND THAT IS THE WHOLE POINT ──────────────────────────
 * `RunEndOfYear` used to run this gate as `$this->call('academics:validate-progression')` — a
 * command invoking a sibling command, whose result is an EXIT CODE with the ring printed to a
 * console buffer. B4 requires the UI to render the cycle "naming the ring"; an exit code cannot name
 * a ring, and scraping `Artisan::output()` is the same defect as shelling out.
 *
 * `ProgressionGraph::findCycle` already returns the ring as data — `list<string>|null`, level names
 * in walk order with the entry repeated. So nothing needed building: the ring existed and the
 * command was throwing it away. This class calls the walk directly and puts the array in the plan.
 *
 * ── WHAT THIS CLASS IS NOT ───────────────────────────────────────────────────────────────────────
 * `academics:validate-progression` does NOT and must not call this. It validates every school's
 * graph as a standalone config check — no session, no term, no selection — and routing it through a
 * rollover planner would widen the planner to serve a caller that supplies none of its inputs. It
 * stays a thin presenter over ProgressionGraph, which is already one walk with several callers.
 * There are two seams here (the walk, and the plan); collapsing them creates the coupling this
 * separation exists to prevent.
 */
class RolloverPlanner
{
    /**
     * Every non-CCM curriculum active in the closing term.
     *
     * The CCM gate is evaluated over the SAME selection rather than a second query: MoveFromTermJob
     * refuses a CCM source, so dispatching past an active CCM curriculum reports success while
     * silently skipping that cohort — leaving pupils in a term nobody moved them out of.
     */
    public function planEndOfTerm(Term $term): RolloverPlan
    {
        $schoolId = (int) $term->school_id;

        $active = $this->activeCurriculaInTerm($term)->get();

        $ccmBlockers = $active->where('is_ccm', true)->values();
        $curricula = $active->where('is_ccm', false)->values();

        $warnings = [];

        if ($draining = $this->drainingBatchCount($schoolId)) {
            $warnings[] = $this->drainingWarning($draining);
        }

        return new RolloverPlan(
            kind: RolloverBatchName::KIND_END_OF_TERM,
            schoolId: $schoolId,
            batchName: RolloverBatchName::forTerm($schoolId, (int) $term->id),
            curricula: $curricula,
            pupilCount: $this->countNonWithdrawnPupils($curricula),
            // NOT APPLICABLE, said explicitly. The progression graph governs LEVEL advancement,
            // which only end-of-year performs — so this check never runs here. `progressionCycle`
            // alone could not say that: null is also what a CLEAN end-of-year plan carries, and the
            // UI would have had to branch on `kind` to tell "we never looked" from "we looked and
            // it is fine". See RolloverPlan::progressionIsAcyclic.
            progressionCheckRan: false,
            progressionCycle: null,
            ccmBlockers: $ccmBlockers,
            warnings: $warnings,
            blockedBy: $ccmBlockers->isEmpty() ? [] : ['ccm-active'],
        );
    }

    /**
     * Every non-CCM curriculum sitting in a class level's FINAL participating slot of the closing
     * session.
     *
     * Both gates are evaluated and BOTH are reported — the caller is not told to fix one, re-run,
     * and discover the other. A registrar making two trips through a rollover they cannot start is
     * the experience this replaces.
     */
    public function planEndOfYear(AcademicSession $source, AcademicSession $target): RolloverPlan
    {
        $schoolId = (int) $source->school_id;

        // THE WALK, CALLED DIRECTLY. See the class docblock — this is the array the command used to
        // reduce to an exit code, and the reason the UI could never name the ring.
        $cycle = ProgressionGraph::findCycle($schoolId);

        $warnings = [];
        $selection = $this->selectFinalSlotCurricula($schoolId, $source, $warnings);

        $ccmBlockers = $selection->where('is_ccm', true)->values();
        $curricula = $selection->where('is_ccm', false)->values();

        if ($draining = $this->drainingBatchCount($schoolId)) {
            $warnings[] = $this->drainingWarning($draining);
        }

        $blockedBy = [];

        if ($cycle !== null) {
            $blockedBy[] = 'progression-cycle';
        }

        if ($ccmBlockers->isNotEmpty()) {
            $blockedBy[] = 'ccm-active';
        }

        return new RolloverPlan(
            kind: RolloverBatchName::KIND_END_OF_YEAR,
            schoolId: $schoolId,
            batchName: RolloverBatchName::forSession($schoolId, (int) $source->id),
            curricula: $curricula,
            pupilCount: $this->countNonWithdrawnPupils($curricula),
            // The check ALWAYS runs for end-of-year, whatever it finds.
            progressionCheckRan: true,
            progressionCycle: $cycle,
            ccmBlockers: $ccmBlockers,
            warnings: $warnings,
            blockedBy: $blockedBy,
        );
    }

    /**
     * @return Builder<Curriculum>
     */
    private function activeCurriculaInTerm(Term $term)
    {
        return Curriculum::withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $term->school_id)
            ->where('term_id', $term->id)
            ->where('status', 'active');
    }

    /**
     * One pass per class level: find its last participating slot, resolve the source session's Term
     * at that order, and take the active curricula for that term across the level's arms.
     *
     * MAX(term_order) of the LEVEL's participation — not the session's last term, and not a count of
     * rows. Those three answers agree only on a contiguous level.
     *
     * @param  list<string>  $warnings  by reference: a level whose final slot has no term in this
     *                                  session is skipped, and the operator must be told which —
     *                                  silently migrating fewer levels than expected is the failure
     *                                  mode here, and it is invisible in a count.
     *
     * @param-out list<string> $warnings
     *
     * @return Collection<int, Curriculum>
     */
    private function selectFinalSlotCurricula(int $schoolId, AcademicSession $source, array &$warnings): Collection
    {
        $selected = collect();

        $levels = ClassLevel::withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $schoolId)
            ->get();

        foreach ($levels as $level) {
            $finalSlot = ClassLevelTermParticipation::withoutGlobalScope(SchoolScope::class)
                ->where('school_id', $schoolId)
                ->where('class_level_id', $level->id)
                ->max('term_order');

            if ($finalSlot === null) {
                continue;
            }

            $term = Term::withoutGlobalScope(SchoolScope::class)
                ->where('academic_session_id', $source->id)
                ->where('order', $finalSlot)
                ->first();

            if ($term === null) {
                $warnings[] = "Class level [{$level->name}] has a final slot of {$finalSlot}, but {$source->name} has no term at that order — skipped.";

                continue;
            }

            $armIds = ClassLevelArm::withoutGlobalScope(SchoolScope::class)
                ->where('class_level_id', $level->id)
                ->pluck('id');

            if ($armIds->isEmpty()) {
                continue;
            }

            $selected = $selected->merge(
                Curriculum::withoutGlobalScope(SchoolScope::class)
                    ->where('school_id', $schoolId)
                    ->where('term_id', $term->id)
                    ->whereIn('class_level_arm_id', $armIds)
                    ->where('status', 'active')
                    ->get()
            );
        }

        return $selected->values();
    }

    /**
     * @param  Collection<int, Curriculum>  $curricula
     */
    private function countNonWithdrawnPupils(Collection $curricula): int
    {
        if ($curricula->isEmpty()) {
            return 0;
        }

        return DB::table('student_curricula')
            ->whereIn('curriculum_id', $curricula->pluck('id'))
            ->whereNotIn('status', ['withdrawn'])
            ->count();
    }

    /**
     * A re-run while a previous batch is draining is HARMLESS — the jobs' own guards no-op the second
     * pass once sources have closed — but it is wasted work, and the plan the operator is reading may
     * not reflect what those jobs are about to change. A warning, never a block: re-queuing a batch
     * that failed partway is a legitimate recovery, and refusing would block it.
     */
    private function drainingBatchCount(int $schoolId): int
    {
        return DB::table('job_batches')
            ->where('name', 'like', RolloverBatchName::likeForSchool($schoolId))
            ->whereNull('finished_at')
            ->whereNull('cancelled_at')
            ->where('pending_jobs', '>', 0)
            ->count();
    }

    private function drainingWarning(int $draining): string
    {
        return "{$draining} rollover batch(es) for this school are still draining. Re-queuing is safe "
            .'(the jobs no-op on work already done) but wasteful, and the plan above may not '
            .'reflect what those jobs are about to change.';
    }
}
