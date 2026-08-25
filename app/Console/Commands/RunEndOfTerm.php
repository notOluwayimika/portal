<?php

namespace App\Console\Commands;

use App\Console\Concerns\ResolvesRolloverOperator;
use App\Jobs\MoveFromTermJob;
use App\Models\Curriculum;
use App\Models\Scopes\SchoolScope;
use App\Models\Term;
use App\Services\Rollover\RolloverPlanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

/**
 * End-of-term rollover — dispatch one MoveFromTermJob per active non-CCM curriculum in a term.
 *
 * DRY RUN BY DEFAULT, `--commit` to dispatch, following academics:backfill-promotion-links. A
 * rollover touches every pupil in a school, so an operator gets to read the plan — how many
 * curricula, how many pupils, what is excluded and why — before anything fires.
 *
 * ── WHAT `--commit` ACTUALLY REPORTS: QUEUED, NOT DONE ────────────────────────────────────────────
 * The jobs are dispatched onto a Bus batch, so the command returns as soon as they are QUEUED. The
 * migration itself happens later, as workers drain the queue. Every line of output therefore says
 * "queued", never "rolled over" — an operator who reads a green command as a finished rollover might
 * reasonably go on to flip the session's is_current, while the batch is still draining behind them.
 *
 * ── THE PRECONDITION IS SYNCHRONOUS, AND THAT IS THE POINT ────────────────────────────────────────
 * The CCM check runs HERE, at command time, before anything is queued, because it must refuse the
 * WHOLE run rather than let individual jobs decline one at a time. MoveFromTermJob already guards its
 * own source (it refuses a CCM curriculum), so without this check the run would look green while a
 * CCM cohort was silently skipped — stranded in a term nobody moved them out of. The job's guard
 * remains as defence in depth; this is the gate.
 *
 * It deliberately does NOT chain-dispatch the CCM moves itself. That is a separate operation an
 * operator should run and verify, not a side effect of asking for the end of term.
 *
 * IDEMPOTENCY IS INHERITED, NOT IMPLEMENTED HERE. Each dispatched job self-guards and no-ops on work
 * already done (MoveFromTermJob closes its source, and a closed source aborts the guard), so
 * re-running this command re-dispatches harmlessly. The idempotent unit is the JOB, not the command.
 */
class RunEndOfTerm extends Command
{
    use ResolvesRolloverOperator;

    protected $signature = 'academics:run-end-of-term
        {term : uuid of the closing term}
        {--user= : id of the operator the work is attributed to (required with --commit)}
        {--commit : Dispatch the jobs (default is a dry run)}';

    protected $description = 'Queue the end-of-term migration for every active non-CCM curriculum in a term.';

    public function handle(): int
    {
        $term = Term::withoutGlobalScope(SchoolScope::class)
            ->where('uuid', $this->argument('term'))
            ->first();

        if ($term === null) {
            $this->error('No term with that uuid.');

            return self::FAILURE;
        }

        $schoolId = (int) $term->school_id;
        $this->line("Term: {$term->name} (school {$schoolId})");

        // ── PLAN ONCE, THEN SHOW OR DISPATCH ──────────────────────────────────────────────────────
        // The selection and the CCM gate moved to RolloverPlanner so slice 2's UI reaches the same
        // decision without shelling out to artisan. This command keeps its output and its exit
        // codes; only where the answer comes from changed.
        $plan = app(RolloverPlanner::class)->planEndOfTerm($term);

        // ── GATE: CCM MOVE FIRST ──────────────────────────────────────────────────────────────────
        if (in_array('ccm-active', $plan->blockedBy, true)) {
            $this->error("{$plan->ccmBlockers->count()} CCM curriculum/curricula are still active in this term.");
            $this->line(
                'Run the CCM move for this term first. MoveFromTermJob refuses a CCM source, so '
                .'dispatching now would report success while silently skipping that cohort — leaving '
                .'them in a term nobody moved them out of.'
            );

            return self::FAILURE;
        }

        if ($plan->isEmpty()) {
            $this->warn('No active non-CCM curricula in this term — nothing to do.');

            return self::SUCCESS;
        }

        $this->line("Plan: {$plan->curricula->count()} curriculum/curricula, {$plan->pupilCount} non-withdrawn enrolment(s).");

        if (! $this->option('commit')) {
            $this->warn('DRY RUN — nothing dispatched. Pass --commit to queue.');

            return self::SUCCESS;
        }

        $operator = $this->resolveOperator($schoolId);

        if ($operator === null) {
            return self::FAILURE;
        }

        foreach ($plan->warnings as $warning) {
            $this->warn($warning);
        }

        // DISPATCH CONSUMES THE PLAN — it does not re-plan. Irrelevant here (the CLI plans and
        // dispatches in one call) and load-bearing in slice 2, where an operator reads a preview and
        // confirms later: re-planning at confirm time would run something other than what was shown.
        // The shape is settled here so slice 2 cannot be forced into the unsafe one.
        $batch = Bus::batch(
            $plan->curricula->map(fn (Curriculum $curriculum) => new MoveFromTermJob(
                $curriculum, (int) $operator->id, $schoolId
            ))->all()
        )->name($plan->batchName)
            ->allowFailures()
            ->dispatch();

        // QUEUED, not completed — see the class docblock.
        $this->info("Queued {$plan->curricula->count()} job(s) as batch {$batch->id}.");
        $this->line('The migration runs as workers drain the queue; this command does not wait for it.');

        return self::SUCCESS;
    }
}
