<?php

namespace App\Finance\Jobs;

use App\Exceptions\BusinessRuleException;
use App\Finance\Actions\GenerateInvoice;
use App\Finance\Contracts\BillableEnrollment;
use App\Finance\Contracts\BillableEnrollmentProvider;
use App\Finance\DTOs\InvoiceLineSpec;
use App\Finance\Enums\BulkInvoiceRunOutcome;
use App\Finance\Enums\BulkInvoiceRunStatus;
use App\Finance\Enums\InvoiceKind;
use App\Finance\Models\BulkInvoiceRun;
use App\Finance\Models\BulkInvoiceRunRow;
use App\Finance\Models\FeeSchedule;
use App\Finance\Services\FeeScheduleLineMapper;
use App\Finance\Services\FeeScheduleLookup;
use App\Finance\Services\InvoiceReadModel;
use App\Jobs\Middleware\SchoolAware;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\CauserResolver;
use Throwable;

/**
 * U6 commit 3 — the bulk invoice run, executed off the request.
 *
 * NOTHING DISPATCHES THIS YET. Commit 4 is the screen and the route; the only caller today is the
 * test suite, deliberately.
 *
 * WHY QUEUED. One invoice per student in a class level, each of them a transaction that writes an
 * invoice, its lines, a ledger charge and possibly a credit application
 * ({@see GenerateInvoice}). A whole-school term billing is that
 * multiplied by every class level, on the one day of the term nobody can afford a timeout. Shape
 * follows {@see ProcessOpeningBalanceImport}: `SchoolAware` middleware,
 * `tries = 1`, a long timeout, and the causer set for AUDIT ATTRIBUTION ONLY — never as an
 * execution identity (Constitution 13).
 *
 * THE RUN ROW IS THE JOB RECORD and it is inserted BEFORE dispatch, in `pending`. Same decision as
 * the opening-balance batch: the record of what was asked for is the record of what happened, so
 * there is no second table tracking the queue and no payload field that a retry cannot read.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * ONE SCHEDULE, ONE SET OF LINES, MAPPED ONCE
 *
 * The lines of a term bill do not vary by student — {@see FeeScheduleLineMapper} maps the
 * schedule's MANDATORY items and nothing in the schema records which child takes the bus — so the
 * schedule is resolved once, the lines are mapped once, and every invoice in the run is raised from
 * that same array. Re-resolving per student would let an approval or a supersession landing mid-run
 * split one cohort silently across two price lists.
 *
 * AND A REFUSAL IS THEREFORE PER-RUN, REPORTED ONCE. The mapper's five refusals — foreign schedule,
 * disagreeing ambient context, non-billable status, no mandatory items, mixed currency — are all
 * facts about the SCHEDULE. Discovering them inside the per-student loop would print the same
 * sentence once per child in the cohort and bury the one thing an operator can act on. So the
 * resolve-and-map happens BEFORE the first row is written: if it refuses, the run is `failed` with
 * that reason, zero rows exist, and nothing was billed.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * A FAILURE ON ONE STUDENT DOES NOT ABORT THE RUN
 *
 * Every per-student call is caught. The reason goes on that student's row and the loop continues —
 * a cohort of forty where the ninth has a corrupt episode must bill the other thirty-nine, not
 * thirty-one. The alternative (throw, `tries = 1`, run stuck in `running`) is the shape that leaves
 * a screen polling a run that will never move.
 *
 * A RE-RUN IS SAFE AND IS THE RECOVERY PATH. `UNIQUE(school_id, active_enrollment_key)` on
 * `finance_invoices` refuses a second ACTIVE SCHEDULED invoice per episode, so re-running after a
 * partial failure cannot double-bill anyone. That path is recorded as
 * {@see BulkInvoiceRunOutcome::AlreadyBilled} and NOT as an error: forty students already done must
 * not read as forty failures. It is detected twice over — a pre-check through
 * {@see InvoiceReadModel::activeScheduledInvoiceIdForEnrollment()} (the ONE
 * PHP expression of that predicate, shared with the modal preview and with GenerateInvoice's own
 * guard), and, because a pre-check cannot hold under concurrency, the same question re-asked after
 * a refusal. Neither reads the exception's MESSAGE: matching on message text would make a copy edit
 * in GenerateInvoice silently reclassify already-billed students as failures.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE RECONCILIATION IS TAKEN AT RUN TIME
 *
 * The unplaceable list and the billable population are both read during the run and written onto the
 * run row. Deriving either when a screen opens would describe a roster that has since moved — a
 * student enrolled an hour later would make a completed run look as though it had missed someone,
 * and a student withdrawn an hour later would make an omission disappear. The figure has to be a
 * statement about the School AS IT WAS BILLED.
 *
 * The four outcome counts are re-read from the rows that were actually PERSISTED rather than
 * accumulated in a PHP tally, so a row whose insert failed shows up as a discrepancy in
 * `unaccounted_count` instead of being counted as work that happened.
 */
class ProcessBulkInvoiceRun implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(
        public int $runId,
        public readonly int $schoolId,
    ) {}

    public function middleware(): array
    {
        return [new SchoolAware];
    }

    public function handle(
        BillableEnrollmentProvider $enrollments,
        FeeScheduleLookup $schedules,
        FeeScheduleLineMapper $mapper,
        GenerateInvoice $generate,
        InvoiceReadModel $invoices,
    ): void {
        // The declared schoolId is the sole School context (SchoolAware -> ActiveSchool::runFor), so
        // the mapper's ambient guard is satisfied by EQUALITY with the School it is handed rather
        // than by whatever context the worker happened to inherit from the previous job.
        $run = BulkInvoiceRun::find($this->runId);

        if (! $run instanceof BulkInvoiceRun) {
            Log::error('ProcessBulkInvoiceRun: run not found', ['id' => $this->runId, 'school_id' => $this->schoolId]);

            return;
        }

        // Audit attribution only — never an execution identity (Constitution 13).
        $causer = $run->started_by_user_id === null ? null : User::find($run->started_by_user_id);
        if ($causer instanceof User) {
            app(CauserResolver::class)->setCauser($causer);
        }

        try {
            $this->process($run, $enrollments, $schedules, $mapper, $generate, $invoices);
        } catch (Throwable $e) {
            // A fact about US, not about their cohort. `tries = 1`, so a rethrow would leave the run
            // sitting in `running` with nothing said and a screen polling it forever.
            Log::error('ProcessBulkInvoiceRun: failed', [
                'run_id' => $run->id, 'school_id' => $this->schoolId, 'error' => $e->getMessage(),
            ]);

            $this->fail($run, 'The run did not finish. This is a fault in the portal, not in the fee schedule: '
                .$e->getMessage());
        } finally {
            app(CauserResolver::class)->setCauser(null);
        }
    }

    private function process(
        BulkInvoiceRun $run,
        BillableEnrollmentProvider $enrollments,
        FeeScheduleLookup $schedules,
        FeeScheduleLineMapper $mapper,
        GenerateInvoice $generate,
        InvoiceReadModel $invoices,
    ): void {
        $run->update(['status' => BulkInvoiceRunStatus::Running, 'started_at' => now()]);

        // ── Per-run conditions, both settled BEFORE the first row is written ──────────────────
        $schedule = $schedules->activeFor($run->term_id, $run->class_level_id);

        if (! $schedule instanceof FeeSchedule) {
            $this->fail($run, 'No active fee schedule exists at these coordinates, so there is no price list to bill from.');

            return;
        }

        // PINNED THE MOMENT ONE IS RESOLVED, including on the refusal path below. The column records
        // WHICH price list this run read, not which one it succeeded with — and a refused schedule is
        // the single most useful thing to know about a failed run.
        $run->update(['fee_schedule_id' => $schedule->id]);

        try {
            // The School is an ARGUMENT here, not read from ambient context — see the mapper. Under
            // SchoolAware the two agree, which is exactly the state its second guard demands.
            $lines = $mapper->linesFor($schedule, $this->schoolId);
        } catch (BusinessRuleException $e) {
            $this->fail($run, $e->getMessage());

            return;
        }

        // ── Per-student work ─────────────────────────────────────────────────────────────────
        // Unplaceable first, and recorded rather than merely counted: these are the students no
        // cohort query anyone runs will ever return, which is precisely why they have to be written
        // down at the moment they were observed.
        $unplaceable = $enrollments->listUnplaceableForSchool($this->schoolId);

        foreach ($unplaceable as $enrollment) {
            $this->record($run, $enrollment, BulkInvoiceRunOutcome::Unplaceable);
        }

        $cohort = $enrollments->listForCohort($this->schoolId, $run->term_id, $run->class_level_id);

        foreach ($cohort as $enrollment) {
            $this->bill($run, $enrollment, $lines, $generate, $invoices);
        }

        $this->reconcile($run, $enrollments->countBillableForSchool($this->schoolId));
    }

    /**
     * One student. Every failure mode ends in a recorded row and a `return`; none of them propagates.
     *
     * @param  list<InvoiceLineSpec>  $lines
     */
    private function bill(
        BulkInvoiceRun $run,
        BillableEnrollment $enrollment,
        array $lines,
        GenerateInvoice $generate,
        InvoiceReadModel $invoices,
    ): void {
        $existing = $invoices->activeScheduledInvoiceIdForEnrollment($enrollment->enrollmentId, $this->schoolId);

        if ($existing !== null) {
            $this->record($run, $enrollment, BulkInvoiceRunOutcome::AlreadyBilled, invoiceId: $existing);

            return;
        }

        try {
            $invoice = $generate->handle($enrollment->enrollmentUuid, $lines, InvoiceKind::Scheduled, $run->started_by_user_id);

            $this->record($run, $enrollment, BulkInvoiceRunOutcome::Billed, invoiceId: $invoice->id);
        } catch (Throwable $e) {
            // The pre-check above cannot hold under concurrency — both racers read a snapshot with
            // no invoice in it — so a refusal is re-interrogated rather than assumed to be an error.
            // Asked of the SAME predicate, never of the exception's message.
            $raced = $invoices->activeScheduledInvoiceIdForEnrollment($enrollment->enrollmentId, $this->schoolId);

            if ($raced !== null) {
                $this->record($run, $enrollment, BulkInvoiceRunOutcome::AlreadyBilled, invoiceId: $raced);

                return;
            }

            $this->record($run, $enrollment, BulkInvoiceRunOutcome::Failed, reason: $e->getMessage());
        }
    }

    /**
     * One row, one enrollment. `school_id` is stated explicitly rather than left to
     * BelongsToSchool's creating-fill: the run's School is the job's declared argument, and a row
     * that took it from ambient context would be trusting the very thing SchoolAware exists to set.
     */
    private function record(
        BulkInvoiceRun $run,
        BillableEnrollment $enrollment,
        BulkInvoiceRunOutcome $outcome,
        ?int $invoiceId = null,
        ?string $reason = null,
    ): void {
        BulkInvoiceRunRow::create([
            'school_id' => $this->schoolId,
            'run_id' => $run->id,
            'enrollment_id' => $enrollment->enrollmentId,
            'enrollment_uuid' => $enrollment->enrollmentUuid,
            'student_id' => $enrollment->studentId,
            'outcome' => $outcome,
            'invoice_id' => $invoiceId,
            'reason' => $reason,
        ]);
    }

    /**
     * Close the run: count what was persisted, subtract it from the School's billable population,
     * and say what is left over.
     *
     * COUNTED FROM THE ROWS, NOT FROM A TALLY. An in-memory counter says what this job BELIEVES it
     * did; `finance_bulk_invoice_run_rows` says what is there. When they differ the difference is
     * the interesting fact, and only one of the two can show it.
     */
    private function reconcile(BulkInvoiceRun $run, int $billable): void
    {
        $counts = BulkInvoiceRunRow::query()
            ->where('run_id', $run->id)
            ->selectRaw('outcome, COUNT(*) AS total')
            ->groupBy('outcome')
            ->pluck('total', 'outcome');

        $of = fn (BulkInvoiceRunOutcome $outcome): int => (int) ($counts[$outcome->value] ?? 0);

        $recorded = $of(BulkInvoiceRunOutcome::Billed)
            + $of(BulkInvoiceRunOutcome::AlreadyBilled)
            + $of(BulkInvoiceRunOutcome::Failed)
            + $of(BulkInvoiceRunOutcome::Unplaceable);

        $run->update([
            'status' => BulkInvoiceRunStatus::Completed,
            'finished_at' => now(),
            'billed_count' => $of(BulkInvoiceRunOutcome::Billed),
            'already_billed_count' => $of(BulkInvoiceRunOutcome::AlreadyBilled),
            'failed_count' => $of(BulkInvoiceRunOutcome::Failed),
            'unplaceable_count' => $of(BulkInvoiceRunOutcome::Unplaceable),
            'billable_count' => $billable,
            // Signed: it cannot go below zero while the cohort and the unplaceable list are subsets
            // of the population, so a negative would mean that stopped being true.
            'unaccounted_count' => $billable - $recorded,
        ]);
    }

    /**
     * A PER-RUN failure. `failure_reason` is the run's own; a student who could not be billed keeps
     * their reason on their row and never reaches here.
     */
    private function fail(BulkInvoiceRun $run, string $reason): void
    {
        $run->update([
            'status' => BulkInvoiceRunStatus::Failed,
            'finished_at' => now(),
            'failure_reason' => $reason,
        ]);
    }
}
