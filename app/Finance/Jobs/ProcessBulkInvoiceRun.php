<?php

namespace App\Finance\Jobs;

use App\Enums\ScholarshipKind;
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
use App\Models\Scholarship;
use App\Models\User;
use App\Support\ActiveSchool;
use Closure;
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
 * A THIRD PER-RUN CONDITION: THE COHORT'S SCHOLARSHIPS MUST SAY WHICH SCHEME THEY ARE.
 *
 * `scholarships.kind` ({@see ScholarshipKind}) is nullable with no default, because nothing in the
 * data that existed before it said which scheme any scholarship was. A cohort holding an
 * unconfigured scholarship therefore FAILS THE WHOLE RUN before its first row, naming the
 * scholarship — it does not bill the students it is sure about and skip the rest.
 *
 * WHY THE WHOLE RUN. The two schemes diverge in opposite directions: a `discount` holder is billed
 * the standard schedule and a `sponsored` holder must not be billed at all. An unconfigured
 * scholarship is the state in which the run cannot tell those apart, and the fall-through — bill
 * them the standard schedule — is INDISTINGUISHABLE FROM A CORRECT RUN on every screen. Nobody
 * discovers it until a sponsored parent opens a full-price invoice, by which point the invoice
 * exists, is append-only, and needs a credit note to unwind. A refusal costs an operator five
 * minutes; the fall-through costs the school its credibility with a sponsor.
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
 * Every per-student unit of work — the invoice AND the row that records what happened to it — runs
 * inside {@see self::attempt()}. A cohort of forty where the ninth has a corrupt episode must bill
 * the other thirty-nine, not thirty-one.
 *
 * THAT SENTENCE USED TO READ "every per-student call is caught" AND IT WAS FALSE WHEN WRITTEN. Two
 * `record()` sites — the unplaceable loop and the already-billed branch — sat outside every `try`,
 * so a duplicate in either list produced 1062 and killed the run before the cohort loop ran. See
 * {@see self::attempt()} for the measurement and for the ruling on what an unrecordable row means.
 *
 * THE TWO FAILURES ARE NOT RECORDED THE SAME WAY, because one of them cannot be:
 *
 *   - the INVOICE could not be raised  → a `failed` row carrying the reason;
 *   - the ROW could not be written     → a log line, and the run's own cohort equality
 *                                        (billed + already + failed + sponsored == cohort_count)
 *                                        stops balancing, which is the alarm.
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

    /**
     * THE QUEUE'S OWN TERMINAL HOOK — the only writer for the deaths `handle()` cannot see.
     *
     * `handle()` sets the run to `running` BEFORE its `try`, so a death that skips PHP's unwinding
     * skips the `catch` AND the `finally`, and leaves the row in `running` with nothing that will
     * ever move it. {@see BulkInvoiceRunStatus::Running} names that state as "the worker died
     * mid-cohort" and, until this method existed, nothing wrote the state that says so.
     *
     * WHAT IT COVERS: an uncaught throw out of `handle()` (there is none today, but `tries = 1`
     * means one would be terminal immediately), a `MaxAttemptsExceededException` after the
     * `timeout = 3600` alarm fires, and a `TimeoutExceededException` — every death where the worker
     * process is still alive to run it.
     *
     * WHAT IT DOES NOT COVER, and this is not a caveat to be skimmed: **a SIGKILL, an OOM kill, or
     * the machine going away still strand the row in `running`**, because nothing in this process
     * runs afterwards. There is no way to close that from inside the job — it needs a sweeper that
     * fails runs whose `started_at` is older than the timeout, and no such sweeper exists. Claiming
     * this method makes the state unreachable would be exactly the shape of overclaim this branch
     * has already had to correct twice.
     *
     * IT ESTABLISHES ITS OWN School CONTEXT. Job middleware does NOT wrap `failed()`, so `SchoolAware`
     * has not run here — and `BulkInvoiceRun` is not in `rbac.fail_closed_models`, so its `SchoolScope`
     * would read UNSCOPED rather than refuse. Resting a write on that fail-open behaviour is the
     * thing Constitution 13 forbids, so the School the job carries is named explicitly.
     *
     * IT REFUSES TO OVERWRITE A TERMINAL STATE. A run that already reached `completed` or `failed`
     * did its work; a late queue-level death must not rewrite the outcome of a run that finished.
     */
    public function failed(Throwable $e): void
    {
        ActiveSchool::runFor($this->schoolId, function () use ($e) {
            $run = BulkInvoiceRun::find($this->runId);

            if (! $run instanceof BulkInvoiceRun) {
                return;
            }

            if ($run->status === BulkInvoiceRunStatus::Completed || $run->status === BulkInvoiceRunStatus::Failed) {
                return;
            }

            // The same three-column writer as failRun(). The model here is freshly loaded and carries
            // no dirty state, so this is consistency rather than necessity — but two ways to fail a
            // run is how one of them ends up being the one nobody audited.
            $this->writeFailure(
                $run->id,
                'The run did not finish: the worker died before it could report. '
                .'Invoices already raised have been kept, and re-running is safe. ('.$e->getMessage().')'
            );
        });
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

            $this->failRun($run, 'The run did not finish. This is a fault in the portal, not in the fee schedule: '
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
            $this->failRun($run, 'No active fee schedule exists at these coordinates, so there is no price list to bill from.');

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
            $this->failRun($run, $e->getMessage());

            return;
        }

        // BOTH LISTS ARE READ BEFORE EITHER LOOP, and the cohort read MOVED UP HERE to make that so.
        // It used to sit between the two loops, which was fine while every per-run condition was a
        // fact about the SCHEDULE. The scholarship condition is a fact about the COHORT, so it cannot
        // be settled without the cohort — and settling it after the unplaceable loop would mean a
        // refusal that had already written rows, breaking the property every other per-run refusal
        // has: zero rows exist and nothing was billed. Two reads swapping order is not observable to
        // anything else here; both are taken at run time and neither feeds the other.
        $unplaceable = $enrollments->listUnplaceableForSchool($this->schoolId);
        $cohort = $enrollments->listForCohort($this->schoolId, $run->term_id, $run->class_level_id);

        $schemes = $this->schemesForCohort($cohort, $enrollments);

        if ($schemes['refusal'] !== null) {
            $this->failRun($run, $schemes['refusal']);

            return;
        }

        // ── Per-student work ─────────────────────────────────────────────────────────────────
        // Unplaceable first, and recorded rather than merely counted: these are the students no
        // cohort query anyone runs will ever return, which is precisely why they have to be written
        // down at the moment they were observed.
        foreach ($unplaceable as $enrollment) {
            $this->attempt($run, $enrollment, fn () => $this->record($run, $enrollment, BulkInvoiceRunOutcome::Unplaceable));
        }

        foreach ($cohort as $enrollment) {
            // SPONSORED STUDENTS ARE WALKED AND RECORDED, NOT FILTERED OUT. They are in the cohort —
            // they sit at these coordinates and the preview counts them — so they get a row like
            // every other member, and `cohort_count` still means what a preview showed. Dropping
            // them from the list instead would have moved them into `outside_coordinates_count`,
            // whose name says they are priced ELSEWHERE, and left no record of who must now be
            // invoiced by hand. See BulkInvoiceRunOutcome::Sponsored.
            if (($schemes['byStudent'][$enrollment->studentId] ?? null) === ScholarshipKind::Sponsored) {
                $this->attempt($run, $enrollment, fn () => $this->record($run, $enrollment, BulkInvoiceRunOutcome::Sponsored));

                continue;
            }

            $this->attempt($run, $enrollment, fn () => $this->bill($run, $enrollment, $lines, $generate, $invoices));
        }

        $this->reconcile(
            $run,
            cohortSize: count($cohort),
            unplaceableSize: count($unplaceable),
            billable: $enrollments->countBillableForSchool($this->schoolId),
        );
    }

    /**
     * WHICH SCHEME EACH SCHOLARSHIP-HOLDING COHORT MEMBER IS ON — and the run-level refusal if any of
     * those schemes is not configured.
     *
     * TWO SEPARATE REFUSALS, because they are two different faults with two different fixes and one
     * message covering both would tell an operator to do the wrong thing:
     *
     *   UNCONFIGURED — the scholarship exists in this School and its `kind` is NULL. Somebody has to
     *                  say whether it is a discount scheme or a sponsored one. Named by NAME, which
     *                  is the only handle an operator has on it in the setup screen.
     *
     *   UNRESOLVABLE — `students.scholarship_id` names a row this School cannot read: it is gone, or
     *                  it belongs to another School. THIS IS SCHEMA-REACHABLE, not a paranoid branch:
     *                  the FK is a plain `scholarship_id` REFERENCES `scholarships (id)` and is NOT
     *                  composite with `school_id`, so nothing at the engine stops School A assigning
     *                  School B a scholarship. Named by ID, because there is no name to print — and
     *                  printing another School's scholarship name here would be a cross-School leak
     *                  through an error message.
     *
     * WHY THIS ONE IS NOT A SILENT SKIP, which is what it would have been if it were left out. A
     * scholarship that does not resolve reads, to every filter below, exactly like a student holding
     * no scholarship at all — so a sponsored C2C student whose scholarship row belongs to the wrong
     * School would be BILLED THE STANDARD SCHEDULE, silently, by the very method written to stop
     * that. The unconfigured refusal would not fire, because there is nothing to find a NULL `kind`
     * on. It is the same fall-through this whole change exists to close, arriving through a hole in
     * the lookup rather than through a hole in the data.
     *
     * ISOLATION IS THE ARGUMENT, NOT THE AMBIENT CONTEXT. Both reads name `$this->schoolId`
     * explicitly. Under `SchoolAware` the ambient School is the same one, so `SchoolScope` agrees and
     * the predicate is redundant — but the run's School is the job's declared argument, and a read
     * that took it from ambient context would be trusting the very thing SchoolAware exists to set
     * (the same decision, for the same reason, as {@see record()}).
     *
     * IT DOES NOT TOUCH `students.scholarship_id`. Nothing here writes; the assignment is read as it
     * stands.
     *
     * @param  list<BillableEnrollment>  $cohort
     * @return array{byStudent: array<int, ScholarshipKind>, refusal: string|null}
     */
    private function schemesForCohort(array $cohort, BillableEnrollmentProvider $enrollments): array
    {
        $studentIds = array_values(array_unique(array_map(
            fn (BillableEnrollment $enrollment) => $enrollment->studentId,
            $cohort,
        )));

        if ($studentIds === []) {
            return ['byStudent' => [], 'refusal' => null];
        }

        // THROUGH THE PORT, NOT A QUERY HERE. The cohort read includes SOFT-DELETED students by a
        // deliberate ruling, and `Student` uses `SoftDeletes` — so the obvious `Student::whereIn()`
        // in this class returns nothing for exactly those students and reads them as holding no
        // scholarship. A trashed sponsored student would then be billed the standard schedule by
        // this method. The port matches the cohort's own soft-delete rule; see its docblock.
        $held = $enrollments->scholarshipIdsFor($studentIds, $this->schoolId);

        if ($held === []) {
            return ['byStudent' => [], 'refusal' => null];
        }

        $scholarships = Scholarship::query()
            ->where('school_id', $this->schoolId)
            ->whereIn('id', array_values(array_unique($held)))
            ->get()
            ->keyBy('id');

        $byStudent = [];
        $unconfigured = [];
        $unresolvable = [];

        foreach ($held as $studentId => $scholarshipId) {
            $scholarship = $scholarships->get($scholarshipId);

            if (! $scholarship instanceof Scholarship) {
                $unresolvable[$scholarshipId] = true;

                continue;
            }

            if ($scholarship->kind === null) {
                // Keyed by NAME so one scholarship held by forty students is named once.
                $unconfigured[$scholarship->name] = true;

                continue;
            }

            $byStudent[(int) $studentId] = $scholarship->kind;
        }

        return [
            'byStudent' => $byStudent,
            'refusal' => $this->scholarshipRefusal(array_keys($unconfigured), array_keys($unresolvable)),
        ];
    }

    /**
     * The sentence a refused run carries, or null when there is nothing to refuse.
     *
     * IT SAYS WHAT WAS NOT DONE, which the fee-schedule refusal beside it also does: an operator
     * reading `failed` needs to know whether anything was billed before they re-run. Nothing was —
     * this is settled before the first row — and saying so is what makes re-running after the fix
     * obviously safe rather than merely safe.
     *
     * @param  list<string>  $unconfigured
     * @param  list<int>  $unresolvable
     */
    private function scholarshipRefusal(array $unconfigured, array $unresolvable): ?string
    {
        if ($unconfigured === [] && $unresolvable === []) {
            return null;
        }

        $parts = [];

        if ($unconfigured !== []) {
            sort($unconfigured);

            $parts[] = 'these scholarships do not say which scheme they are: '.implode(', ', $unconfigured)
                .'. Set each one to a discount scholarship (billed here, at the standard fee schedule) '
                .'or a sponsored scholarship (billed by hand, and excluded from this run)';
        }

        if ($unresolvable !== []) {
            sort($unresolvable);

            $parts[] = 'these scholarship records could not be read in this school, so the students '
                .'holding them cannot be classified: '.implode(', ', array_map(
                    fn (int $id) => '#'.$id,
                    $unresolvable,
                )).'. Each one has been deleted or belongs to another school';
        }

        return 'This run was stopped before it billed anyone, and '.implode('; also, ', $parts).'. '
            .'Nothing was invoiced and no student was charged. The run refuses rather than billing '
            .'the standard fee schedule, because a sponsored student billed by mistake looks exactly '
            .'like a successful run until their parent opens a full-price invoice.';
    }

    /**
     * ONE STUDENT'S WHOLE UNIT OF WORK, INCLUDING THE ROW WRITE.
     *
     * THIS EXISTS BECAUSE TWO `record()` CALL SITES WERE OUTSIDE ANY `try`, and cold review measured
     * what that cost: a provider whose unplaceable list repeated one member produced **1062** on the
     * second insert, and the run died in the unplaceable loop — BEFORE the cohort loop ran at all.
     * Two billable students, zero invoices raised, run `failed`, every count NULL. The migration's
     * own comment said that shape was "refused rather than silently double-counted", which was true
     * of the INDEX and false of the JOB. Refusing a write and surviving the refusal are two different
     * properties and only one of them was built.
     *
     * THE RULING, stated rather than left to be inferred: **AN UNRECORDABLE ROW IS A PER-STUDENT
     * FAULT, NOT A RUN-LEVEL ONE.** The run carries on and bills everyone it still can. That is the
     * same judgement already made for a student whose INVOICE cannot be raised, and the reasoning is
     * identical — thirty-nine of forty is a partial result, thirty-one of forty because the ninth row
     * hit a constraint is a defect.
     *
     * AND IT IS NOT SILENT, which is the part that makes the ruling defensible. A student whose row
     * could not be written is missing from `finance_bulk_invoice_run_rows`, so
     *
     *     billed_count + already_billed_count + failed_count  <  cohort_count
     *
     * and that inequality is the run's own alarm (see the migration, and {@see reconcile()}). There
     * is deliberately no extra flag column saying "something went wrong": a flag the job sets is a
     * flag the job can forget to set, whereas the equality is over four numbers all counted from the
     * persisted rows and the list the run actually walked.
     *
     * The log line is the diagnosis, since the row that would have carried the reason is the row
     * that could not be written.
     */
    private function attempt(BulkInvoiceRun $run, BillableEnrollment $enrollment, Closure $work): void
    {
        try {
            $work();
        } catch (Throwable $e) {
            Log::error('ProcessBulkInvoiceRun: could not record an outcome for one enrollment', [
                'run_id' => $run->id,
                'school_id' => $this->schoolId,
                'enrollment_id' => $enrollment->enrollmentId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * One student's INVOICE. Every failure of {@see GenerateInvoice} ends in a recorded row and a
     * `return`. A failure of the row write itself still propagates from here — deliberately, because
     * {@see attempt()} is the one place that decides what an unrecordable row means, and having two
     * places decide it is how the two `record()` sites outside a `try` came to exist in the first
     * place.
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
     * Close the run and write both kinds of figure — they are not the same kind of number and the
     * migration says why at length.
     *
     * COUNTED FROM THE ROWS, NOT FROM A TALLY. An in-memory counter says what this job BELIEVES it
     * did; `finance_bulk_invoice_run_rows` says what is there. When they differ the difference is
     * the interesting fact, and only one of the two can show it. `sponsored_count` is counted the
     * same way and for the same reason — it is a term of the cohort equality, not a note about what
     * the job intended to skip.
     *
     * `outside_coordinates_count` IS UNAFFECTED BY THE EXCLUSION, and that is the point of recording
     * sponsored students as a cohort outcome rather than removing them from the list. `cohort_count`
     * is still the size of the list the run walked, so the residual still means exactly what its
     * name says: billable students priced at OTHER coordinates.
     *
     * ─────────────────────────────────────────────────────────────────────────────────────────
     * THE NOBODY-BILLED RULE, AND IT IS A HEURISTIC RATHER THAN A DIAGNOSIS
     *
     * A run over a NON-EMPTY cohort that billed nobody — `billed + already_billed == 0` and
     * `failed == cohort_count` — is recorded as `failed`, not `completed`.
     *
     * WHY, measured: a connection error injected at the first read inside GenerateInvoice produced
     * three `failed` rows, `status = completed`, `failure_reason` NULL and a cohort equality that
     * balanced perfectly. A screen would have said "Completed — 0 billed, 3 failed", which is a
     * green word over a total outage. Every per-student catch this job needs in order to survive one
     * bad episode is also what lets an environmental fault wear the costume of N ordinary
     * per-student failures.
     *
     * IT IS A GUESS ABOUT SHAPE, NOT A CAUSE, and the run has no way to do better. It CANNOT tell an
     * environmental fault from a genuine domain case where every student legitimately fails — the
     * two produce byte-identical rows — and it deliberately does not try. Both are reported the same
     * way, because in both cases the honest thing to tell an operator is "nothing was billed, go
     * and read the reasons". The row-level reasons are the diagnosis; this is only the word on the
     * run.
     *
     * WHAT IT DOES NOT CATCH, stated so nobody mistakes it for a health check:
     *
     *   - A PARTIAL OUTAGE. Half the cohort failing still reports `completed`, and that is the
     *     realistic shape of a flaky connection. The rule fires only on total failure.
     *   - An outage that lands AFTER the cohort loop. That throws, and is a run-level failure by
     *     the ordinary path.
     *   - An outage during the UNPLACEABLE loop. Those rows are not in this rule at all — their
     *     alarm is the unplaceable equality above.
     *   - An empty cohort. `cohort_count == 0` is excluded: a class level with nobody in it is a
     *     normal, successful run that billed nobody, and firing on it would cry wolf every term.
     */
    private function reconcile(BulkInvoiceRun $run, int $cohortSize, int $unplaceableSize, int $billable): void
    {
        $counts = BulkInvoiceRunRow::query()
            ->where('run_id', $run->id)
            ->selectRaw('outcome, COUNT(*) AS total')
            ->groupBy('outcome')
            ->pluck('total', 'outcome');

        $of = fn (BulkInvoiceRunOutcome $outcome): int => (int) ($counts[$outcome->value] ?? 0);

        $billed = $of(BulkInvoiceRunOutcome::Billed);
        $already = $of(BulkInvoiceRunOutcome::AlreadyBilled);
        $failed = $of(BulkInvoiceRunOutcome::Failed);
        $sponsored = $of(BulkInvoiceRunOutcome::Sponsored);

        // THE NOBODY-BILLED RULE. A run that walked a non-empty cohort and raised nothing, where
        // every single member failed, did not complete — see the docblock for why this is a
        // heuristic and what it does not catch.
        //
        // `$failed === $cohortSize` IS WHAT KEEPS SPONSORED STUDENTS OUT OF THIS RULE, and it does so
        // without a clause of its own: a sponsored row is not a failed row, so a cohort of forty
        // sponsored students has `failed = 0`, the equality does not hold, and the rule stays silent
        // on a run that correctly billed nobody. That is the right answer — an all-C2C class level
        // billing nobody is a SUCCESSFUL run, not an outage — and it is worth stating because the
        // rule reads as "billed nothing" and is in fact "every member failed".
        $nobodyBilled = $cohortSize > 0 && $billed === 0 && $already === 0 && $failed === $cohortSize;

        $run->update([
            'status' => $nobodyBilled ? BulkInvoiceRunStatus::Failed : BulkInvoiceRunStatus::Completed,
            'finished_at' => now(),
            'failure_reason' => $nobodyBilled
                ? 'Every one of the '.$cohortSize.' students in this cohort failed and none was billed. '
                    .'That is far more likely to be a fault in the portal than a fault in '.$cohortSize
                    .' separate enrollments — check the rows for the repeated reason before re-running. '
                    .'Re-running is safe: anything that was billed comes back as already billed.'
                : null,

            // THE RUN'S OWN ACCOUNTING — two lists, two equalities. `cohort_count` and
            // `unplaceable_listed_count` are the sizes of the lists the run WALKED; the four counts
            // beside them are counted from the rows it PERSISTED. Two independent sources per line.
            'cohort_count' => $cohortSize,
            'billed_count' => $billed,
            'already_billed_count' => $already,
            'failed_count' => $failed,

            // THE FOURTH TERM OF THE COHORT EQUALITY. Counted from the persisted rows like the three
            // above it, so a sponsored row that could not be written unbalances the equality instead
            // of vanishing — the same property, and the same alarm, the other three have.
            'sponsored_count' => $sponsored,
            'unplaceable_listed_count' => $unplaceableSize,
            'unplaceable_count' => $of(BulkInvoiceRunOutcome::Unplaceable),

            // THE SCHOOL-WIDE FIGURE. BOTH SUBTRAHENDS ARE LIST SIZES — the earlier version used the
            // unplaceable ROW count here while this comment claimed otherwise, so a lost unplaceable
            // row moved out of an alarm and into a residual that is large and unalarming on every
            // healthy run. That movement is the exact thing this line exists to prevent.
            'billable_count' => $billable,
            'outside_coordinates_count' => $billable - $cohortSize - $unplaceableSize,
        ]);
    }

    /**
     * A PER-RUN failure. `failure_reason` is the run's own; a student who could not be billed keeps
     * their reason on their row and never reaches here.
     *
     * NOTHING IT WRITES COMES FROM THE MODEL'S ATTRIBUTE BAG, and that is the whole of this method's
     * shape. `Model::update()` is `fill()` then `save()`: when the `save()` throws, the ATTRIBUTES
     * STAY FILLED. So every earlier refused write left its payload sitting dirty on `$run`, and this
     * method — which used to be another `$run->update()` — flushed it to the database on the way
     * out. Cold review measured both states, and both are the same bug:
     *
     *   the run-transition refused → `status = failed` carrying a `started_at` that never persisted,
     *                                on a run the database still believed was `pending`;
     *   the closing write refused  → `status = failed` carrying `finished_at` AND EVERY ONE OF THE
     *                                NINE COUNTS, correct and complete, on a run whose status says
     *                                it did not finish. A screen would render a full, accurate
     *                                report under the word "failed" — the worst of the two, because
     *                                it is credible.
     *
     * (`reconcile()`'s payload is TWELVE keys — `status`, `finished_at`, `failure_reason` and the
     * nine counts — of which ELEVEN reach the wire on a healthy run: `failure_reason` goes NULL to
     * NULL and is therefore not dirty. The counts are the nine that matter here, and an earlier
     * version of this comment said "all ten counts", conflating the payload's size with the number
     * of figures in it. Both figures moved by one when `sponsored_count` landed.)
     *
     * {@see writeFailure()} bypasses the attribute bag entirely, so there is no dirty state to
     * inherit — not "we remembered to refresh", but "there is nothing to refresh FROM".
     */
    private function failRun(BulkInvoiceRun $run, string $reason): void
    {
        $this->writeFailure($run->id, $reason);

        // Bring the in-memory model back in step with the row, discarding whatever the refused write
        // left on it. Callers and tests holding this instance must see what is actually stored.
        $run->refresh();
    }

    /**
     * Mark ONE run failed. The `SET` clause carries FOUR columns: `status`, `finished_at` and
     * `failure_reason` from the caller, plus `updated_at`, which Eloquent's builder appends itself
     * (`Builder::addUpdatedAtColumn()`).
     *
     * THE FOURTH ONE IS NOT AN EXCEPTION TO THE PROPERTY, and saying "exactly three" — as this
     * docblock and 7a37f2a's commit message both did — described the wrong thing. The property that
     * matters is not the COUNT of columns, it is the SOURCE: **nothing in this statement is read
     * from the model's attribute bag.** `updated_at` is stamped from the framework's clock at
     * statement-build time and the statement is built on a FRESH builder, so no model instance is
     * consulted and a figure left dirty by a refused write has no route in. The re-check confirmed
     * it from the other end: a sentinel `updated_at` set on the model does NOT land.
     *
     * Measured on 8.0.43 with `DB::pretend()`, which is also where the `where` note below comes
     * from — six bindings, four in the `SET` and two in the `WHERE`:
     *
     *     update `finance_bulk_invoice_runs`
     *        set `status` = ?, `finished_at` = ?, `failure_reason` = ?,
     *            `finance_bulk_invoice_runs`.`updated_at` = ?
     *      where `finance_bulk_invoice_runs`.`id` = ? and `finance_bulk_invoice_runs`.`school_id` = ?
     *
     * A QUERY-BUILDER UPDATE, DELIBERATELY, AND NOT A MODEL SAVE. A model save writes whatever is
     * dirty, and the callers of this method are precisely the paths where something already went
     * wrong and left the model dirty — see {@see failRun()} for the two measured states. Naming the
     * columns makes inheriting a figure UNREPRESENTABLE rather than merely unlikely; a `refresh()`
     * before a `save()` would have fixed the same two cases and would still write whatever the next
     * caller happened to have pending.
     *
     * THE `WHERE` CLAUSE IS NOT JUST THE KEY. `BulkInvoiceRun` carries `BelongsToSchool`, so
     * `SchoolScope` adds `school_id = <active school>` beside `whereKey()` — a reader counting the
     * bindings will meet it. Harmless and mildly welcome here: under `SchoolAware` the active School
     * is the job's own, and in {@see failed()} it is the one that method sets for itself, so the
     * extra predicate can only ever match the row already named by its primary key.
     *
     * The status column's BEFORE UPDATE trigger fires on this write exactly as it does on a model
     * save — the guard is on the table, not on the ORM — so the enum domain is still enforced.
     *
     * `status` is passed as its backed VALUE: an Eloquent mass update does not run casts.
     */
    private function writeFailure(int $runId, string $reason): void
    {
        BulkInvoiceRun::query()->whereKey($runId)->update([
            'status' => BulkInvoiceRunStatus::Failed->value,
            'finished_at' => now(),
            'failure_reason' => $reason,
        ]);
    }
}
