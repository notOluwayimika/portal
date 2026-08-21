<?php

namespace App\Console\Commands;

use App\Jobs\MoveToNextYearJob;
use App\Models\AcademicSession;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\ClassLevelTermParticipation;
use App\Models\Curriculum;
use App\Models\Scopes\SchoolScope;
use App\Models\Term;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/**
 * End-of-year rollover — dispatch one MoveToNextYearJob per FINAL-SLOT curriculum, per class level.
 *
 * Dry run by default, `--commit` to dispatch, and — as with the end-of-term command — `--commit`
 * reports what was QUEUED, not what completed. The migration happens as workers drain the queue.
 *
 * ── THE TARGET SESSION IS AN ARGUMENT, NEVER INFERRED ─────────────────────────────────────────────
 * `academic_sessions` carries no order, no start_date and no next-pointer — only a display name and
 * `is_current`, which marks the session you are IN rather than the one you are moving to. There is
 * nothing to compute "next session" from but a label string. It is validated (exists, same school,
 * not the source session) and never created.
 *
 * ── TWO SYNCHRONOUS GATES, BOTH REFUSING THE WHOLE RUN ────────────────────────────────────────────
 * 1. THE CYCLE GATE. academics:validate-progression walks next_class_level_id per school. The
 *    2026_08_20_130000 trigger already rejects the self-loop A -> A, but a multi-node ring is legal
 *    at every row, and this command is precisely where a ring does its damage: it would dispatch a
 *    fleet in which every job succeeds while the cohorts swap levels and nobody advances. Nothing
 *    downstream can catch that — each job is single-hop and correct in isolation.
 * 2. THE CCM GATE. Same reasoning as the end-of-term command, applied to each level's FINAL slot: a
 *    CCM curriculum there would be silently declined by the job's own guard, leaving that cohort
 *    stranded at the year boundary while the command reported success.
 *
 * Both run before anything queues, because both must refuse the entire run rather than let jobs
 * decline one at a time. Each job re-guards its own source at run time regardless — defence in depth,
 * not a substitute for the gate.
 *
 * ── SELECTION: THE LEVEL'S LAST PARTICIPATING SLOT, WHICH IS NOT "THE LAST TERM" ──────────────────
 * For each class level, the source curricula are those in the term at `MAX(term_order)` of that
 * LEVEL'S participation rows — resolved against the SOURCE session.
 *
 * Three things this must not be confused with, all of which agree on a contiguous 1-2-3 level and
 * diverge on a real one:
 *   • the session's last term — a level running slots 1-2 in a three-term session ends at 2, not 3;
 *   • `count()` of participation rows — a level running slots 1 and 3 has TWO rows and a final slot
 *     of 3, so a count-based answer says 2 and dispatches the wrong term;
 *   • any assumption of contiguity, which Part 1 deliberately does not provide.
 * Dispatching a NON-final-slot curriculum would advance mid-year pupils a whole class level.
 */
class RunEndOfYear extends Command
{
    protected $signature = 'academics:run-end-of-year
        {sourceSession : uuid of the closing session}
        {targetSession : uuid of the session pupils move into}
        {--user= : id of the operator the work is attributed to (required with --commit)}
        {--commit : Dispatch the jobs (default is a dry run)}';

    protected $description = 'Queue the end-of-year migration for every final-slot curriculum in a session.';

    public function handle(): int
    {
        $source = $this->session($this->argument('sourceSession'));
        $target = $this->session($this->argument('targetSession'));

        if ($source === null || $target === null) {
            $this->error('Source and target sessions must both exist (by uuid).');

            return self::FAILURE;
        }

        if ((int) $source->school_id !== (int) $target->school_id) {
            $this->error('The two sessions belong to different schools.');

            return self::FAILURE;
        }

        if ((int) $source->id === (int) $target->id) {
            $this->error('The target session is the source session — pupils cannot roll into the year they are leaving.');

            return self::FAILURE;
        }

        $schoolId = (int) $source->school_id;
        $this->line("School {$schoolId}: {$source->name} -> {$target->name}");

        // ── GATE 1: THE PROGRESSION GRAPH MUST BE A DAG ───────────────────────────────────────────
        if ($this->call('academics:validate-progression', ['--school' => $schoolId]) !== self::SUCCESS) {
            $this->error('Refusing to queue: the progression graph is not acyclic (reported above).');

            return self::FAILURE;
        }

        $selection = $this->selectFinalSlotCurricula($schoolId, $source);

        // ── GATE 2: CCM MOVE FIRST, ON THE FINAL SLOTS ────────────────────────────────────────────
        $ccm = $selection->where('is_ccm', true);

        if ($ccm->isNotEmpty()) {
            $this->error("{$ccm->count()} CCM curriculum/curricula sit in a final slot for this session.");
            $this->line(
                'Run the CCM move for those terms first. MoveToNextYearJob refuses a CCM source, so '
                .'queuing now would report success while that cohort was silently skipped.'
            );

            return self::FAILURE;
        }

        $curricula = $selection->where('is_ccm', false)->values();

        if ($curricula->isEmpty()) {
            $this->warn('No active non-CCM final-slot curricula in this session — nothing to do.');

            return self::SUCCESS;
        }

        $pupils = DB::table('student_curricula')
            ->whereIn('curriculum_id', $curricula->pluck('id'))
            ->whereNotIn('status', ['withdrawn'])
            ->count();

        $this->line("Plan: {$curricula->count()} final-slot curriculum/curricula, {$pupils} non-withdrawn enrolment(s).");

        if (! $this->option('commit')) {
            $this->warn('DRY RUN — nothing dispatched. Pass --commit to queue.');

            return self::SUCCESS;
        }

        $operator = $this->resolveOperator($schoolId);

        if ($operator === null) {
            return self::FAILURE;
        }

        $this->warnIfBatchStillDraining($schoolId);

        $batch = Bus::batch(
            $curricula->map(fn (Curriculum $curriculum) => new MoveToNextYearJob(
                $curriculum, $target, (int) $operator->id, $schoolId
            ))->all()
        )->name("rollover:end-of-year:school:{$schoolId}:session:{$source->id}")
            ->allowFailures()
            ->dispatch();

        $this->info("Queued {$curricula->count()} job(s) as batch {$batch->id}.");
        $this->line('The migration runs as workers drain the queue; this command does not wait for it.');

        return self::SUCCESS;
    }

    private function session(string $uuid): ?AcademicSession
    {
        return AcademicSession::withoutGlobalScope(SchoolScope::class)->where('uuid', $uuid)->first();
    }

    /**
     * One pass per class level: find its last participating slot, resolve the source session's Term
     * at that order, and take the active curricula for that term across the level's arms.
     *
     * @return Collection<int, Curriculum>
     */
    private function selectFinalSlotCurricula(int $schoolId, AcademicSession $source): Collection
    {
        $selected = collect();

        $levels = ClassLevel::withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $schoolId)
            ->get();

        foreach ($levels as $level) {
            // MAX(term_order) of the LEVEL's participation — not the session's last term, and not a
            // count of rows. See the class docblock; the three answers agree only on a contiguous
            // level.
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
                $this->warn("Class level [{$level->name}] has a final slot of {$finalSlot}, but {$source->name} has no term at that order — skipped.");

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

    private function resolveOperator(int $schoolId): ?User
    {
        $userId = $this->option('user');

        if (! $userId) {
            $this->error('--user is required with --commit: the jobs attribute their audit trail to an operator.');

            return null;
        }

        $user = User::find($userId);

        if ($user === null) {
            $this->error("No user with id {$userId}.");

            return null;
        }

        // NO SCHOOL-MEMBERSHIP CHECK — see RunEndOfTerm::resolveOperator. `$user->school_id` is the
        // `school-id-fallback-context` pattern the boundary lint refuses and ADR 0042 is retiring, and
        // the causer here is attribution only: the jobs receive $schoolId explicitly and never derive
        // it from the operator.

        return $user;
    }

    /** See RunEndOfTerm::warnIfBatchStillDraining — warn, never refuse; a failed batch needs re-queuing. */
    private function warnIfBatchStillDraining(int $schoolId): void
    {
        $draining = DB::table('job_batches')
            ->where('name', 'like', "rollover:%:school:{$schoolId}:%")
            ->whereNull('finished_at')
            ->whereNull('cancelled_at')
            ->where('pending_jobs', '>', 0)
            ->count();

        if ($draining > 0) {
            $this->warn("{$draining} rollover batch(es) for this school are still draining — re-queuing is safe but wasteful.");
        }
    }
}
