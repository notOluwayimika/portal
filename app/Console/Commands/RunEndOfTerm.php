<?php

namespace App\Console\Commands;

use App\Jobs\MoveFromTermJob;
use App\Models\Curriculum;
use App\Models\Scopes\SchoolScope;
use App\Models\Term;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

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

        // ── GATE: CCM MOVE FIRST ──────────────────────────────────────────────────────────────────
        $ccm = $this->activeCurricula($term)->where('is_ccm', true)->count();

        if ($ccm > 0) {
            $this->error("{$ccm} CCM curriculum/curricula are still active in this term.");
            $this->line(
                'Run the CCM move for this term first. MoveFromTermJob refuses a CCM source, so '
                .'dispatching now would report success while silently skipping that cohort — leaving '
                .'them in a term nobody moved them out of.'
            );

            return self::FAILURE;
        }

        $curricula = $this->activeCurricula($term)->where('is_ccm', false)->get();

        if ($curricula->isEmpty()) {
            $this->warn('No active non-CCM curricula in this term — nothing to do.');

            return self::SUCCESS;
        }

        $pupils = DB::table('student_curricula')
            ->whereIn('curriculum_id', $curricula->pluck('id'))
            ->whereNotIn('status', ['withdrawn'])
            ->count();

        $this->line("Plan: {$curricula->count()} curriculum/curricula, {$pupils} non-withdrawn enrolment(s).");

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
            $curricula->map(fn (Curriculum $curriculum) => new MoveFromTermJob(
                $curriculum, (int) $operator->id, $schoolId
            ))->all()
        )->name("rollover:end-of-term:school:{$schoolId}:term:{$term->id}")
            ->allowFailures()
            ->dispatch();

        // QUEUED, not completed — see the class docblock.
        $this->info("Queued {$curricula->count()} job(s) as batch {$batch->id}.");
        $this->line('The migration runs as workers drain the queue; this command does not wait for it.');

        return self::SUCCESS;
    }

    /**
     * @return Builder<Curriculum>
     */
    private function activeCurricula(Term $term)
    {
        return Curriculum::withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $term->school_id)
            ->where('term_id', $term->id)
            ->where('status', 'active');
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

        // NO SCHOOL-MEMBERSHIP CHECK HERE, DELIBERATELY. The obvious guard —
        // `$user->school_id !== $schoolId` — is the `school-id-fallback-context` pattern the boundary
        // lint refuses and ADR 0042 is retiring: deriving school context from `users.school_id`.
        // Caught by that lint on the first run of this file, and the right fix is not to work around
        // it but to notice the check does not belong here at all. The causer is ATTRIBUTION ONLY —
        // the jobs receive $schoolId explicitly and never read it from the causer (Constitution 13) —
        // so validating the operator against their own row would reintroduce the fallback to answer a
        // question the rollover does not ask. Authorization for running a rollover is shell access to
        // artisan, not a column on the operator.

        return $user;
    }

    /**
     * A re-run while a previous batch is still draining is HARMLESS — the jobs' own guards no-op the
     * second pass once sources have closed — but it is wasted work, and more importantly it means the
     * operator may be reading a stale picture. Warn rather than refuse: there are legitimate reasons
     * to re-queue (a batch that failed partway), and refusing would block the recovery.
     */
    private function warnIfBatchStillDraining(int $schoolId): void
    {
        $draining = DB::table('job_batches')
            ->where('name', 'like', "rollover:%:school:{$schoolId}:%")
            ->whereNull('finished_at')
            ->whereNull('cancelled_at')
            ->where('pending_jobs', '>', 0)
            ->count();

        if ($draining > 0) {
            $this->warn(
                "{$draining} rollover batch(es) for this school are still draining. Re-queuing is safe "
                .'(the jobs no-op on work already done) but wasteful, and the plan above may not '
                .'reflect what those jobs are about to change.'
            );
        }
    }
}
