<?php

namespace App\Console\Commands;

use App\Console\Concerns\ResolvesRolloverOperator;
use App\Jobs\MoveToNextYearJob;
use App\Models\AcademicSession;
use App\Models\Curriculum;
use App\Models\Scopes\SchoolScope;
use App\Services\Rollover\RolloverPlanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

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
    use ResolvesRolloverOperator;

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

        // ── PLAN ONCE. BOTH GATES COME BACK AS DATA ───────────────────────────────────────────────
        // The cycle gate used to be `$this->call('academics:validate-progression')` — a command
        // invoking a sibling command, whose result is an EXIT CODE with the ring printed to a
        // console buffer. That is why slice 2's UI could not name the ring: there was nothing to
        // name. RolloverPlanner calls ProgressionGraph::findCycle directly and the ring arrives as
        // an array, which this command formats for a terminal and the controller will format for a
        // screen — one walk, two presentations.
        $plan = app(RolloverPlanner::class)->planEndOfYear($source, $target);

        // ── GATE 1: THE PROGRESSION GRAPH MUST BE A DAG ───────────────────────────────────────────
        // KEYED ON `blockedBy`, NOT ON THE RAW FIELD. The first version of this asked
        // `$plan->progressionCycle !== null` while gate 2 asked `blockedBy` — two ways of asking
        // "is this blocked", and a mutation caught it: neutering the planner's blockedBy population
        // for the cycle left every command test GREEN, because the command was not reading the field
        // the planner had stopped populating.
        //
        // That is the exact drift the DTO exists to prevent. Slice 2's UI reads `blockedBy` /
        // `isRunnable()`, so the bug would have been invisible from the CLI and live on the screen.
        // Both gates now read one field; the raw ring is used only to SAY which ring.
        if (in_array('progression-cycle', $plan->blockedBy, true)) {
            $this->error("school {$schoolId}: next_class_level_id contains a CYCLE — ".implode(' -> ', $plan->progressionCycle));
            $this->error(
                'Refusing to queue. Every job in a ring would succeed individually while the cohorts '
                .'simply swap levels and nobody advances. Break the cycle by clearing or repointing one '
                .'next_class_level_id, then re-run this check.'
            );

            return self::FAILURE;
        }

        // ── GATE 2: CCM MOVE FIRST, ON THE FINAL SLOTS ────────────────────────────────────────────
        if (in_array('ccm-active', $plan->blockedBy, true)) {
            $this->error("{$plan->ccmBlockers->count()} CCM curriculum/curricula sit in a final slot for this session.");
            $this->line(
                'Run the CCM move for those terms first. MoveToNextYearJob refuses a CCM source, so '
                .'queuing now would report success while that cohort was silently skipped.'
            );

            return self::FAILURE;
        }

        // Emitted AFTER the gates, as before: a level skipped for want of a term is context for a
        // plan that is going to run, not noise in front of a refusal.
        foreach ($plan->warnings as $warning) {
            $this->warn($warning);
        }

        if ($plan->isEmpty()) {
            $this->warn('No active non-CCM final-slot curricula in this session — nothing to do.');

            return self::SUCCESS;
        }

        $this->line("Plan: {$plan->curricula->count()} final-slot curriculum/curricula, {$plan->pupilCount} non-withdrawn enrolment(s).");

        if (! $this->option('commit')) {
            $this->warn('DRY RUN — nothing dispatched. Pass --commit to queue.');

            return self::SUCCESS;
        }

        $operator = $this->resolveOperator($schoolId);

        if ($operator === null) {
            return self::FAILURE;
        }

        // DISPATCH CONSUMES THE PLAN — see RunEndOfTerm for why the shape matters in slice 2.
        $batch = Bus::batch(
            $plan->curricula->map(fn (Curriculum $curriculum) => new MoveToNextYearJob(
                $curriculum, $target, (int) $operator->id, $schoolId
            ))->all()
        )->name($plan->batchName)
            ->allowFailures()
            ->dispatch();

        $this->info("Queued {$plan->curricula->count()} job(s) as batch {$batch->id}.");
        $this->line('The migration runs as workers drain the queue; this command does not wait for it.');

        return self::SUCCESS;
    }

    private function session(string $uuid): ?AcademicSession
    {
        return AcademicSession::withoutGlobalScope(SchoolScope::class)->where('uuid', $uuid)->first();
    }
}
