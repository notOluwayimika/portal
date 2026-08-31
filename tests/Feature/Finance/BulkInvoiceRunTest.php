<?php

/*
 * U6 commit 3 — the bulk invoice run: the job, and the record that accounts for every billable
 * student.
 *
 * Every guard below was PLANTED and watched red before it was believed; the plants are named in the
 * branch report (docs/handoff/reports/feat-u6-bulk-invoice-run.md). The claims these tests exist to
 * break, in the order they matter:
 *
 *   1. THE RUN MUST NOT DOUBLE-BILL, AND MUST NOT REPORT A RE-RUN AS FAILURE. The authority is
 *      UNIQUE(school_id, active_enrollment_key) on finance_invoices, not anything in the job — so
 *      the job's whole contribution is CLASSIFYING that refusal as `already_billed` rather than as
 *      an error. A re-run after a partial failure is the recovery path and forty students already
 *      done must not read as forty failures.
 *
 *   2. ONE STUDENT MUST NOT TAKE THE RUN DOWN. Thirty-nine of forty is a partial result; thirty-one
 *      of forty because the ninth had a corrupt episode is a defect.
 *
 *   3. A SCHEDULE-LEVEL REFUSAL MUST BE REPORTED ONCE. The mapper's five refusals are facts about
 *      the PRICE LIST. Discovered inside the loop they print once per child and bury the one thing
 *      an operator can act on.
 *
 *   4. THE RECONCILIATION MUST ADD UP OVER A FIXTURE WHERE ALL FIVE BUCKETS ARE NON-EMPTY, and the
 *      denominator must be able to SEE the two shapes no coordinate reasoning reaches — a placeable
 *      student at coordinates nobody asked about, and a NULL-`student_id` episode
 *      (docs/handoff/tickets/bulk-run-must-account-for-every-billable-student.md).
 *
 *   5. ISOLATION. The run's School is an ARGUMENT carried on the job; the cohort read strips the
 *      ambient scope, so that argument is the only thing between School A's run and School B's
 *      students.
 *
 * THE JOB IS DISPATCHED, NEVER CALLED. Every arm goes through `ProcessBulkInvoiceRun::dispatch()` on
 * the sync queue, which is the ONLY path that runs job middleware — so SchoolAware is under test
 * rather than assumed. And every dispatch happens OUTSIDE any ActiveSchool::runFor: if the run only
 * works because a test happened to leave a context open, it fails here.
 */

use App\Academics\BillableEnrollmentAdapter;
use App\Enums\StudentStatusEnum;
use App\Enums\TermStatusEnum;
use App\Finance\Actions\CreateFeeSchedule;
use App\Finance\Actions\GenerateInvoice;
use App\Finance\Contracts\BillableEnrollment;
use App\Finance\Contracts\BillableEnrollmentProvider;
use App\Finance\Enums\BulkInvoiceRunOutcome;
use App\Finance\Enums\BulkInvoiceRunStatus;
use App\Finance\Enums\FeeScheduleStatus;
use App\Finance\Enums\InvoiceKind;
use App\Finance\Jobs\ProcessBulkInvoiceRun;
use App\Finance\Models\BulkInvoiceRun;
use App\Finance\Models\BulkInvoiceRunRow;
use App\Finance\Models\Invoice;
use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\Curriculum;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\Term;
use App\Support\ActiveSchool;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * A School with the pricing coordinates a run names, plus a SECOND class level so "placeable at
 * coordinates nobody asked about" is constructible.
 *
 * @return array{school: School, term: Term, level: ClassLevel, arm: ClassLevelArm, level2: ClassLevel, arm2: ClassLevelArm}
 */
function birSchool(): array
{
    $school = School::factory()->create();

    return ActiveSchool::runFor($school->id, function () use ($school) {
        $session = AcademicSession::create([
            'school_id' => $school->id, 'name' => '2026/2027-'.Str::random(4),
            'slug' => 'sess-'.Str::random(8), 'is_current' => true,
        ]);
        $term = Term::create([
            'academic_session_id' => $session->id, 'school_id' => $school->id, 'name' => 'First Term',
            'slug' => 'term-'.Str::random(8), 'order' => 1, 'start_date' => now()->subMonth(),
            'end_date' => now()->addMonths(2), 'status' => TermStatusEnum::ACTIVE->value,
        ]);

        $make = function (string $name, int $order) use ($school) {
            $level = ClassLevel::create(['school_id' => $school->id, 'name' => $name, 'order' => $order]);
            $arm = ClassLevelArm::create([
                'school_id' => $school->id,
                'class_level_id' => $level->id,
                'arm_id' => Arm::create(['school_id' => $school->id, 'label' => strtoupper(Str::random(3))])->id,
            ]);

            return [$level, $arm];
        };

        [$level, $arm] = $make('JSS 1', 1);
        [$level2, $arm2] = $make('JSS 2', 2);

        return compact('school', 'term', 'level', 'arm', 'level2', 'arm2');
    });
}

/**
 * An ACTIVE fee schedule at $ctx's coordinates. CreateFeeSchedule always authors a DRAFT (the
 * parent-state triggers only admit item inserts into one), so the activation is a raw status write —
 * the way the rest of the suite moves a lifecycle it is not the subject of.
 *
 * @param  list<array<string, mixed>>  $items
 */
function birSchedule(array $ctx, ?array $items = null, ?ClassLevel $level = null): int
{
    $items ??= [[
        'description' => 'Tuition', 'amount_minor' => 1000000, 'currency' => 'NGN',
        'is_mandatory' => true, 'is_discountable' => true, 'sort_order' => 0,
    ]];

    return ActiveSchool::runFor($ctx['school']->id, function () use ($ctx, $items, $level) {
        $specs = array_map(fn (array $item) => $item + ['bank_account_id' => testBankAccountUuid($ctx['school']->id)], $items);

        $schedule = app(CreateFeeSchedule::class)->handle(
            $ctx['term']->id, ($level ?? $ctx['level'])->id, 'v1-'.Str::random(4), $specs
        );

        DB::table('finance_fee_schedules')->where('id', $schedule->id)
            ->update(['status' => FeeScheduleStatus::Active->value]);

        return $schedule->id;
    });
}

/** A student in $ctx's School with one ACTIVE enrollment at the given coordinates. */
function birStudent(array $ctx, ?int $armId, ?int $termId): Student
{
    return ActiveSchool::runFor($ctx['school']->id, function () use ($ctx, $armId, $termId) {
        $student = Student::factory()->create([
            'school_id' => $ctx['school']->id,
            'admission_number' => 'ADM-'.Str::random(8),
        ]);

        StudentCurriculum::create([
            'student_id' => $student->id,
            'school_id' => $ctx['school']->id,
            'curriculum_id' => Curriculum::factory()->create([
                'school_id' => $ctx['school']->id,
                'class_level_arm_id' => $armId,
                'term_id' => $termId,
            ])->id,
            'status' => StudentStatusEnum::ACTIVE,
        ]);

        return $student;
    });
}

/**
 * AN ACTIVE EPISODE WITH NO STUDENT — schema-legal, and the one shape no coordinate reasoning
 * reaches. `student_curricula.student_id` is nullable and MySQL's default MATCH SIMPLE skips the
 * composite FK check when a component is NULL, so `(NULL, school_id)` satisfies
 * `student_curricula_student_school_foreign`. Inserted RAW because StudentCurriculum::create() fires
 * an observer that fatals on a null curriculum — and raw SQL / imports are exactly how these rows
 * arise in production (the observer's own docblock says so).
 */
function birStudentlessEpisode(array $ctx): void
{
    DB::table('student_curricula')->insert([
        'uuid' => (string) Str::uuid(),
        'student_id' => null,
        'school_id' => $ctx['school']->id,
        'curriculum_id' => null,
        'status' => StudentStatusEnum::ACTIVE->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** Insert the run row a controller will insert in commit 4, in `pending`, and dispatch the job. */
function birRun(array $ctx, ?ClassLevel $level = null): BulkInvoiceRun
{
    $run = ActiveSchool::runFor($ctx['school']->id, fn () => BulkInvoiceRun::create([
        'school_id' => $ctx['school']->id,
        'term_id' => $ctx['term']->id,
        'class_level_id' => ($level ?? $ctx['level'])->id,
        'status' => BulkInvoiceRunStatus::Pending,
    ]));

    // NO ambient context around the dispatch — SchoolAware is what must supply it.
    ProcessBulkInvoiceRun::dispatch($run->id, $ctx['school']->id);

    return $run->refresh();
}

/** @return array<string, int> outcome => count, over the rows of one run */
function birOutcomes(BulkInvoiceRun $run): array
{
    return BulkInvoiceRunRow::withoutGlobalScopes()->where('run_id', $run->id)
        ->get()->groupBy(fn (BulkInvoiceRunRow $row) => $row->outcome->value)
        ->map->count()->all();
}

/**
 * Bind a provider that is the real adapter with ONE list bent — the two lists the run walks, and
 * `findByUuid`, are the only things a decorator here may touch.
 *
 * EVERYTHING ELSE DELEGATES to a real `BillableEnrollmentAdapter` (which is `final`, hence a
 * decorator rather than a subclass), so the cohort read, the unplaceable read and the population
 * count under test are all the genuine ones. Only the specific shape each test needs is injected.
 *
 * The three shapes used below are all reachable in production:
 *
 *   $vanishing     — an episode that resolves in the ARGUMENT-scoped cohort read and no longer
 *                    resolves in the AMBIENT-scoped `findByUuid` (the port's docblock splits its
 *                    methods on exactly that line). A withdrawal landing mid-run does this, and
 *                    GenerateInvoice then throws its own sentence.
 *   $onUnplaceable — a list containing one member twice. `currentEnrollments()` de-duplicates by
 *                    construction today, so this stands in for any future read that stops doing so;
 *                    the point under test is what the JOB does with the 1062, not whether the read
 *                    can produce it.
 *   $onCohort      — the same for the cohort, plus the phantom-member case: a well-formed DTO whose
 *                    `enrollmentId` names no row, so the row write fails the composite FK (1452)
 *                    rather than the unique index. A failure that is NOT a duplicate.
 */
function birProviderWith(?string $vanishing = null, ?Closure $onCohort = null, ?Closure $onUnplaceable = null, ?Closure $onFind = null): void
{
    app()->bind(BillableEnrollmentProvider::class, fn () => new class($vanishing, $onCohort, $onUnplaceable, $onFind) implements BillableEnrollmentProvider
    {
        private BillableEnrollmentAdapter $inner;

        public function __construct(
            private readonly ?string $vanishing,
            private readonly ?Closure $onCohort,
            private readonly ?Closure $onUnplaceable,
            private readonly ?Closure $onFind,
        ) {
            $this->inner = new BillableEnrollmentAdapter;
        }

        public function findByUuid(string $enrollmentUuid): ?BillableEnrollment
        {
            if ($this->onFind !== null) {
                return ($this->onFind)($enrollmentUuid);
            }

            return $enrollmentUuid === $this->vanishing ? null : $this->inner->findByUuid($enrollmentUuid);
        }

        public function currentForStudent(int $studentId): ?BillableEnrollment
        {
            return $this->inner->currentForStudent($studentId);
        }

        public function currentForStudents(array $studentIds): array
        {
            return $this->inner->currentForStudents($studentIds);
        }

        public function scholarshipIdsFor(array $studentIds, int $schoolId): array
        {
            return $this->inner->scholarshipIdsFor($studentIds, $schoolId);
        }

        public function displayFor(array $studentIds): array
        {
            return $this->inner->displayFor($studentIds);
        }

        public function matchingStudentIds(string $term): array
        {
            return $this->inner->matchingStudentIds($term);
        }

        public function listForCohort(int $schoolId, int $termId, int $classLevelId): array
        {
            $cohort = $this->inner->listForCohort($schoolId, $termId, $classLevelId);

            return $this->onCohort === null ? $cohort : ($this->onCohort)($cohort);
        }

        public function listUnplaceableForSchool(int $schoolId): array
        {
            $unplaceable = $this->inner->listUnplaceableForSchool($schoolId);

            return $this->onUnplaceable === null ? $unplaceable : ($this->onUnplaceable)($unplaceable);
        }

        public function countBillableForSchool(int $schoolId): int
        {
            return $this->inner->countBillableForSchool($schoolId);
        }

        public function admissionNumberIndex(): array
        {
            return $this->inner->admissionNumberIndex();
        }
    });
}

/** The original single-purpose helper, kept because four tests read better with the narrow name. */
function birProviderLosing(string $vanishingUuid): void
{
    birProviderWith(vanishing: $vanishingUuid);
}

/** Repeat the first element of a list — the 1062 shape, for either list. */
function birRepeatFirst(): Closure
{
    return fn (array $items) => $items === [] ? $items : [$items[0], ...$items];
}

/**
 * A well-formed DTO naming an episode that does not exist, appended to the cohort. Its row write
 * fails `finance_bulk_invoice_run_rows_enrollment_school_foreign` (1452) — an unrecordable row for a
 * reason that is NOT a duplicate, which is the third shape FIX 1 has to survive.
 */
function birAppendPhantom(int $schoolId): Closure
{
    return fn (array $cohort) => [...$cohort, new BillableEnrollment(
        enrollmentId: 2_000_000_000,
        enrollmentUuid: (string) Str::uuid(),
        studentId: 2_000_000_000,
        schoolId: $schoolId,
        studentName: 'Phantom',
        academicContext: 'JSS 1',
        termId: 1,
        classLevelId: 1,
    )];
}

/* ── 1 · A run over a cohort bills every student once ──────────────────────────────────────── */

test('a run over a cohort bills every student once', function () {
    $ctx = birSchool();
    birSchedule($ctx);
    $students = collect([birStudent($ctx, $ctx['arm']->id, $ctx['term']->id), birStudent($ctx, $ctx['arm']->id, $ctx['term']->id), birStudent($ctx, $ctx['arm']->id, $ctx['term']->id)]);

    $run = birRun($ctx);

    expect($run->status)->toBe(BulkInvoiceRunStatus::Completed)
        ->and($run->failure_reason)->toBeNull()
        ->and($run->fee_schedule_id)->not->toBeNull()
        ->and($run->started_at)->not->toBeNull()
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->billed_count)->toBe(3);

    // ONCE, asserted per student rather than in aggregate: three invoices could also be two for one
    // child and one for another, which is the failure worth naming.
    foreach ($students as $student) {
        $invoices = Invoice::withoutGlobalScopes()->where('student_id', $student->id)->get();
        expect($invoices)->toHaveCount(1)
            ->and($invoices->first()->kind)->toBe(InvoiceKind::Scheduled);
    }

    $rows = BulkInvoiceRunRow::withoutGlobalScopes()->where('run_id', $run->id)->get();
    expect($rows)->toHaveCount(3);

    foreach ($rows as $row) {
        expect($row->outcome)->toBe(BulkInvoiceRunOutcome::Billed)
            ->and($row->invoice_id)->not->toBeNull()
            ->and($row->reason)->toBeNull()
            ->and($row->school_id)->toBe($ctx['school']->id);
    }
});

/* ── 2 · A re-run bills nobody twice and records already-billed ────────────────────────────── */

test('a re-run bills nobody twice and records already-billed, not failed', function () {
    $ctx = birSchool();
    birSchedule($ctx);
    birStudent($ctx, $ctx['arm']->id, $ctx['term']->id);
    birStudent($ctx, $ctx['arm']->id, $ctx['term']->id);

    $first = birRun($ctx);
    $invoiceIds = Invoice::withoutGlobalScopes()->pluck('id')->sort()->values()->all();

    $second = birRun($ctx);

    expect($second->status)->toBe(BulkInvoiceRunStatus::Completed)
        ->and($second->billed_count)->toBe(0)
        ->and($second->already_billed_count)->toBe(2)
        ->and($second->failed_count)->toBe(0, 'a re-run is the recovery path; already-billed must not read as failure');

    // Not one new invoice anywhere — the unique index is the authority and it held.
    expect(Invoice::withoutGlobalScopes()->pluck('id')->sort()->values()->all())->toBe($invoiceIds);

    // And the rows NAME the invoice that was already there — the first run's, not a new one.
    $rows = BulkInvoiceRunRow::withoutGlobalScopes()->where('run_id', $second->id)->get();
    expect($rows->pluck('invoice_id')->sort()->values()->all())->toBe($invoiceIds);

    expect($first->refresh()->billed_count)->toBe(2);
});

/* ── 3 · One student failing does not stop the others ──────────────────────────────────────── */

test('one student failing does not stop the others, and the reason is recorded', function () {
    $ctx = birSchool();
    birSchedule($ctx);
    $doomed = birStudent($ctx, $ctx['arm']->id, $ctx['term']->id);
    birStudent($ctx, $ctx['arm']->id, $ctx['term']->id);
    birStudent($ctx, $ctx['arm']->id, $ctx['term']->id);

    $vanishing = (string) StudentCurriculum::withoutGlobalScopes()
        ->where('student_id', $doomed->id)->value('uuid');
    birProviderLosing($vanishing);

    $run = birRun($ctx);

    expect($run->status)->toBe(BulkInvoiceRunStatus::Completed,
        'a per-student failure is not a per-run failure — the run must reach the end of the cohort')
        ->and($run->failure_reason)->toBeNull()
        ->and($run->billed_count)->toBe(2)
        ->and($run->failed_count)->toBe(1);

    // The other two were billed even though the failure came FIRST in the cohort (ordered by
    // student_id, and $doomed was created first) — so this is not passing because the loop happened
    // to reach them before it broke.
    expect(Invoice::withoutGlobalScopes()->count())->toBe(2)
        ->and(Invoice::withoutGlobalScopes()->where('student_id', $doomed->id)->count())->toBe(0);

    $failed = BulkInvoiceRunRow::withoutGlobalScopes()
        ->where('run_id', $run->id)->where('outcome', BulkInvoiceRunOutcome::Failed->value)->sole();

    expect($failed->student_id)->toBe($doomed->id)
        ->and($failed->invoice_id)->toBeNull()
        // The ACTUAL message, not a generic one — an operator acts on this sentence.
        ->and($failed->reason)->toContain('No billable enrollment found');
});

/* ── 4 · A mapper refusal fails the run ONCE ───────────────────────────────────────────────── */

test('a mapper refusal fails the run once, not once per student', function () {
    $ctx = birSchool();

    // A schedule of purely OPTIONAL items — authorable, active, and unable to produce a term bill.
    // One of the mapper's five refusals, and a fact about the PRICE LIST, not about any child.
    birSchedule($ctx, [[
        'description' => 'Transport', 'amount_minor' => 200000, 'currency' => 'NGN',
        'is_mandatory' => false, 'is_discountable' => true, 'sort_order' => 0,
    ]]);

    birStudent($ctx, $ctx['arm']->id, $ctx['term']->id);
    birStudent($ctx, $ctx['arm']->id, $ctx['term']->id);
    birStudent($ctx, $ctx['arm']->id, $ctx['term']->id);

    $run = birRun($ctx);

    expect($run->status)->toBe(BulkInvoiceRunStatus::Failed)
        ->and($run->failure_reason)->toContain('no mandatory items')
        // POSITIVE, not `->not->toBeNull($message)`: Pest discards a message on a negated
        // expectation (PestNegatedExpectationMessagesTest pins that repo-wide), and the message is
        // the point here — the refused schedule is the useful fact on a failed run.
        ->and($run->fee_schedule_id !== null)->toBeTrue('a failed run must still name the price list it read');

    // ONCE. Three students in the cohort and ZERO rows — the refusal was not restated per child, and
    // the run stopped before the first invoice rather than failing three times over.
    expect(BulkInvoiceRunRow::withoutGlobalScopes()->where('run_id', $run->id)->count())->toBe(0)
        ->and(Invoice::withoutGlobalScopes()->count())->toBe(0);

    // The counts stay NULL: a run that aborted must not present a reconciliation it never made.
    expect($run->billed_count)->toBeNull()
        ->and($run->cohort_count)->toBeNull()
        ->and($run->billable_count)->toBeNull()
        ->and($run->outside_coordinates_count)->toBeNull();
});

test('a run with no active fee schedule at its coordinates fails once, before any row', function () {
    $ctx = birSchool();
    birStudent($ctx, $ctx['arm']->id, $ctx['term']->id);

    $run = birRun($ctx);

    expect($run->status)->toBe(BulkInvoiceRunStatus::Failed)
        ->and($run->failure_reason)->toContain('No active fee schedule')
        ->and($run->fee_schedule_id)->toBeNull()
        ->and(BulkInvoiceRunRow::withoutGlobalScopes()->where('run_id', $run->id)->count())->toBe(0);
});

/* ── 5 · The reconciliation adds up, with all five buckets non-empty ───────────────────────── */

test('the reconciliation adds up over a fixture where all five buckets are non-empty', function () {
    $ctx = birSchool();
    birSchedule($ctx);

    // BUCKET 3 (already billed) — billed by a FIRST run, which is exactly how this state arises.
    $already = birStudent($ctx, $ctx['arm']->id, $ctx['term']->id);
    $first = birRun($ctx);
    expect($first->billed_count)->toBe(1);

    // BUCKET 1 (billed) × 2, added after the first run so the second run bills exactly these.
    birStudent($ctx, $ctx['arm']->id, $ctx['term']->id);
    birStudent($ctx, $ctx['arm']->id, $ctx['term']->id);

    // BUCKET 2 (failed) × 1 — the episode vanishes between the cohort read and the bill.
    $doomed = birStudent($ctx, $ctx['arm']->id, $ctx['term']->id);
    birProviderLosing((string) StudentCurriculum::withoutGlobalScopes()->where('student_id', $doomed->id)->value('uuid'));

    // BUCKET 4 (unplaceable) × 1 — a null term, so no fee schedule can ever be keyed to it.
    birStudent($ctx, $ctx['arm']->id, null);

    // BUCKET 5a (unaccounted) × 1 — PLACEABLE, at a class level this run does not name.
    birStudent($ctx, $ctx['arm2']->id, $ctx['term']->id);

    // BUCKET 5b (unaccounted) × 1 — the NULL-student_id episode. It fails the
    // EXISTS-through-students clause in currentEnrollments(), so it is in NEITHER list; only a
    // denominator built on billableEpisodes() WITHOUT that clause can see it. This is the shape the
    // ticket names as the one no coordinate reasoning reaches.
    birStudentlessEpisode($ctx);

    $run = birRun($ctx);

    expect($run->status)->toBe(BulkInvoiceRunStatus::Completed);

    // The five buckets, each non-empty and each asserted by its OWN expected number — not by the
    // identity, which any set of numbers summing correctly would satisfy.
    expect($run->billed_count)->toBe(2)
        ->and($run->already_billed_count)->toBe(1)
        ->and($run->failed_count)->toBe(1)
        ->and($run->unplaceable_count)->toBe(1)
        ->and($run->outside_coordinates_count)->toBe(2, 'one placeable student at a class level this run did not name, plus the student-less episode');

    // The population, counted independently of every one of them.
    expect($run->billable_count)->toBe(7);

    // THE RUN'S OWN ACCOUNTING, which is the exact one: four true headcounts of one set.
    expect($run->cohort_count)->toBe(4)
        ->and($run->billed_count + $run->already_billed_count + $run->failed_count)
        ->toBe($run->cohort_count);

    // And the school-wide identity, which is a DIFFERENT kind of statement — a residual, not a
    // miss count. `outside_coordinates_count` is subtracted from the LIST SIZES, never from the row
    // counts, so an unrecordable row cannot quietly drain into it.
    expect($run->cohort_count + $run->unplaceable_listed_count + $run->outside_coordinates_count)
        ->toBe($run->billable_count);

    // Both lists were walked whole, so both equalities hold here.
    expect($run->unplaceable_count)->toBe($run->unplaceable_listed_count);

    // The unplaceable student is RECORDED, not merely counted — the roster moves, so the run has to
    // write down who it saw at the moment it saw them.
    expect(birOutcomes($run))->toBe([
        'unplaceable' => 1, 'already_billed' => 1, 'billed' => 2, 'failed' => 1,
    ], 'rows are written unplaceable-first, then the cohort in student_id order');
});

test('countBillableForSchool is decided by its argument, not by an ambient context', function () {
    $a = birSchool();
    $b = birSchool();

    birStudent($a, $a['arm']->id, $a['term']->id);
    birStudent($a, $a['arm']->id, null);
    birStudentlessEpisode($a);

    // School B's students at School A's OWN term and arm ids — nothing in the schema forbids it
    // (curricula's term and arm FKs are single-column), so school_id is the only separator.
    birStudent($b, $a['arm']->id, $a['term']->id);
    birStudent($b, $a['arm']->id, $a['term']->id);

    $adapter = new BillableEnrollmentAdapter;

    // No ambient context at all — the argument decides, or nothing does.
    expect($adapter->countBillableForSchool($a['school']->id))->toBe(3)
        ->and($adapter->countBillableForSchool($b['school']->id))->toBe(2);

    // And a DISAGREEING ambient context changes neither answer. A second, ambient opinion would
    // empty the intersection, and a zero population makes every reconciliation read "nothing
    // unaccounted for" — the silent-total-result failure, in the figure written to detect it.
    ActiveSchool::runFor($b['school']->id, function () use ($adapter, $a) {
        expect($adapter->countBillableForSchool($a['school']->id))->toBe(3);
    });
});

/* ── 6 · Cross-School ──────────────────────────────────────────────────────────────────────── */

test('a run for School A touches nothing of School B', function () {
    $a = birSchool();
    $b = birSchool();

    birSchedule($a);
    birSchedule($b);

    $mine = birStudent($a, $a['arm']->id, $a['term']->id);
    // School B students sitting at School A's OWN coordinate ids, and one unplaceable, so School B
    // is reachable by every read the run performs if the School argument ever stops deciding.
    $theirs = birStudent($b, $a['arm']->id, $a['term']->id);
    $theirsUnplaceable = birStudent($b, $b['arm']->id, null);

    $run = birRun($a);

    expect($run->status)->toBe(BulkInvoiceRunStatus::Completed)
        ->and($run->billed_count)->toBe(1)
        ->and($run->unplaceable_count)->toBe(0, 'School B unplaceable students are not School A unplaceable students')
        ->and($run->billable_count)->toBe(1);

    // Not one invoice for School B.
    expect(Invoice::withoutGlobalScopes()->where('school_id', $b['school']->id)->count())->toBe(0)
        ->and(Invoice::withoutGlobalScopes()->where('student_id', $theirs->id)->count())->toBe(0)
        ->and(Invoice::withoutGlobalScopes()->where('student_id', $theirsUnplaceable->id)->count())->toBe(0)
        ->and(Invoice::withoutGlobalScopes()->where('student_id', $mine->id)->count())->toBe(1);

    // And not one run row naming a School B student or School B itself.
    $rows = BulkInvoiceRunRow::withoutGlobalScopes()->where('run_id', $run->id)->get();
    expect($rows->pluck('school_id')->unique()->all())->toBe([$a['school']->id])
        ->and($rows->pluck('student_id')->all())->toBe([$mine->id]);
});

/* ── 7 · The enum domains BITE at the engine, on MySQL 5.7 as well as 8.0 ──────────────────── */

test('the run status domain is enforced by a TRIGGER — a raw write of an unknown status is refused', function () {
    $ctx = birSchool();
    $run = ActiveSchool::runFor($ctx['school']->id, fn () => BulkInvoiceRun::create([
        'school_id' => $ctx['school']->id, 'term_id' => $ctx['term']->id,
        'class_level_id' => $ctx['level']->id, 'status' => BulkInvoiceRunStatus::Pending,
    ]));

    // A RAW write, never touching the model or its cast — the case a CHECK was supposed to hold and
    // does not on the server that holds the money.
    $update = fn (string $status) => DB::table('finance_bulk_invoice_runs')
        ->where('id', $run->id)->update(['status' => $status]);

    expect(fn () => $update('bogus'))->toThrow(QueryException::class);

    // CASE VARIANCE IS REFUSED TOO, and that is the COLLATE clause being load-bearing rather than
    // decorative: under the table's utf8mb4_unicode_ci, 'COMPLETED' would satisfy a naive IN() while
    // every where('status','completed') read in the application missed it.
    expect(fn () => $update('COMPLETED'))->toThrow(QueryException::class);

    // And a legal value still passes, so the guard is not simply refusing everything.
    $update('completed');
    expect(DB::table('finance_bulk_invoice_runs')->where('id', $run->id)->value('status'))->toBe('completed');
});

test('the row outcome domain is enforced by a TRIGGER — a raw insert of an unknown outcome is refused', function () {
    $ctx = birSchool();
    $student = birStudent($ctx, $ctx['arm']->id, $ctx['term']->id);

    // A `pending` run with NO rows, and no job dispatched. THE FIXTURE IS THE POINT: the first
    // version of this test inserted against a run that had already billed this enrollment, so
    // unique(school_id, run_id, enrollment_id) refused every insert with 1062 and the arm passed
    // whether the trigger existed or not. Planted against a trigger-less schema it stayed GREEN —
    // a test proving the index, wearing the trigger's name.
    $run = ActiveSchool::runFor($ctx['school']->id, fn () => BulkInvoiceRun::create([
        'school_id' => $ctx['school']->id, 'term_id' => $ctx['term']->id,
        'class_level_id' => $ctx['level']->id, 'status' => BulkInvoiceRunStatus::Pending,
    ]));

    $enrollmentId = (int) StudentCurriculum::withoutGlobalScopes()->where('student_id', $student->id)->value('id');

    $insert = fn (string $outcome) => DB::table('finance_bulk_invoice_run_rows')->insert([
        'uuid' => (string) Str::uuid(), 'school_id' => $ctx['school']->id, 'run_id' => $run->id,
        'enrollment_id' => $enrollmentId, 'enrollment_uuid' => (string) Str::uuid(),
        'student_id' => $student->id, 'outcome' => $outcome,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(fn () => $insert('bogus'))->toThrow(QueryException::class)
        // Case variance too — the COLLATE clause being load-bearing rather than decorative.
        ->and(fn () => $insert('BILLED'))->toThrow(QueryException::class);

    // A legal value still inserts, so the guard is not refusing everything — and this is what makes
    // the two refusals above statements about the OUTCOME rather than about the row.
    $insert('billed');
    expect(DB::table('finance_bulk_invoice_run_rows')->where('run_id', $run->id)->count())->toBe(1);
});

/* ── 8 · A per-student WRITE that cannot land must not take the run down (FIX 1) ───────────── */

/*
 * Three shapes, one ruling. Cold review measured the defect these close: two `record()` call sites —
 * the unplaceable loop and the already-billed branch — sat outside every `try`, so a duplicate in
 * either list produced 1062 and killed the run BEFORE the cohort loop, leaving two billable students
 * unbilled, zero invoices, and every count NULL. The migration comment claimed that shape was
 * "refused rather than silently double-counted", which was true of the INDEX and false of the JOB.
 *
 * The ruling is that an unrecordable row is a PER-STUDENT fault: the run bills everyone it still
 * can. It is not silent — the student is missing from the rows, so
 * billed + already + failed no longer equals cohort_count, and that inequality is the alarm. Each
 * test below asserts BOTH halves: the other students were billed, AND the alarm fired.
 */

test('FIX1a: a duplicated unplaceable episode does not stop the cohort from being billed', function () {
    $ctx = birSchool();
    birSchedule($ctx);
    $one = birStudent($ctx, $ctx['arm']->id, $ctx['term']->id);
    $two = birStudent($ctx, $ctx['arm']->id, $ctx['term']->id);
    birStudent($ctx, $ctx['arm']->id, null);   // the unplaceable one, which the decorator repeats

    birProviderWith(onUnplaceable: birRepeatFirst());

    $run = birRun($ctx);

    // THE HARM, ASSERTED FIRST so a red says what was lost rather than what a status field held:
    // the 1062 lands in the UNPLACEABLE loop, which runs BEFORE the cohort loop, so an uncaught one
    // means these two billable students are never reached at all.
    expect(Invoice::withoutGlobalScopes()->count())->toBe(2,
        'two billable students were in the cohort and neither was billed: a 1062 in the unplaceable '
        .'loop killed the run before the cohort loop ran');

    expect(Invoice::withoutGlobalScopes()->where('student_id', $one->id)->count())->toBe(1)
        ->and(Invoice::withoutGlobalScopes()->where('student_id', $two->id)->count())->toBe(1)
        ->and($run->status)->toBe(BulkInvoiceRunStatus::Completed)
        ->and($run->failure_reason)->toBeNull()
        ->and($run->billed_count)->toBe(2);

    // The duplicate landed once, not twice — the index refused the second and the job survived it.
    expect($run->unplaceable_count)->toBe(1);

    // The cohort was untouched by the unplaceable list's problem, so the run's own accounting is
    // still exact here.
    expect($run->cohort_count)->toBe(2)
        ->and($run->billed_count + $run->already_billed_count + $run->failed_count)->toBe($run->cohort_count);
});

test('FIX1b: a duplicated cohort member does not stop the other students, and the alarm fires', function () {
    $ctx = birSchool();
    birSchedule($ctx);
    $students = collect([
        birStudent($ctx, $ctx['arm']->id, $ctx['term']->id),
        birStudent($ctx, $ctx['arm']->id, $ctx['term']->id),
        birStudent($ctx, $ctx['arm']->id, $ctx['term']->id),
    ]);

    birProviderWith(onCohort: birRepeatFirst());

    $run = birRun($ctx);

    // THE HARM, ASSERTED FIRST. The repeat is the FIRST cohort entry, so its second pass hits the
    // already-billed branch while two students are still unbilled: an uncaught 1062 there loses
    // both of them.
    expect(Invoice::withoutGlobalScopes()->count())->toBe(3,
        'a 1062 in the already-billed branch left the rest of the cohort unbilled');

    expect($run->status)->toBe(BulkInvoiceRunStatus::Completed)
        ->and($run->failure_reason)->toBeNull();

    // EVERY STUDENT BILLED EXACTLY ONCE, the repeated one included. Its second pass finds the
    // invoice its first pass raised, tries to record `already_billed`, and is refused by
    // unique(school_id, run_id, enrollment_id) — the row is lost, the invoice is not duplicated.
    foreach ($students as $student) {
        expect(Invoice::withoutGlobalScopes()->where('student_id', $student->id)->count())->toBe(1);
    }

    expect(BulkInvoiceRunRow::withoutGlobalScopes()->where('run_id', $run->id)->count())->toBe(3);

    // THE ALARM. The run walked four entries and could record only three, so the equality that
    // holds on every healthy run does not hold here — and that is the only thing that says a row
    // was lost.
    expect($run->cohort_count)->toBe(4)
        ->and($run->billed_count + $run->already_billed_count + $run->failed_count)
        ->toBe(3)
        ->and($run->billed_count + $run->already_billed_count + $run->failed_count)
        ->toBeLessThan($run->cohort_count);
});

test('FIX1c: a cohort member whose row violates an unrelated constraint does not stop the others', function () {
    $ctx = birSchool();
    birSchedule($ctx);
    $one = birStudent($ctx, $ctx['arm']->id, $ctx['term']->id);
    $two = birStudent($ctx, $ctx['arm']->id, $ctx['term']->id);

    // NOT a duplicate: the phantom's row fails the composite enrollment FK (1452). The two failures
    // must be handled the same way, or FIX 1 has only special-cased 1062.
    birProviderWith(onCohort: birAppendPhantom($ctx['school']->id));

    $run = birRun($ctx);

    // THE HARM, ASSERTED FIRST. The phantom is appended LAST, so the two real students are billed
    // before it — but its uncaught 1452 still discards the whole run's record and its counts.
    expect(Invoice::withoutGlobalScopes()->count())->toBe(2,
        'the two real students must stay billed even though the phantom row could not be written');

    expect($run->status)->toBe(BulkInvoiceRunStatus::Completed,
        'a 1452 on ONE row is a per-student fault; the run must still close and report its counts')
        ->and($run->failure_reason)->toBeNull()
        ->and($run->billed_count)->toBe(2)
        ->and(Invoice::withoutGlobalScopes()->where('student_id', $one->id)->count())->toBe(1)
        ->and(Invoice::withoutGlobalScopes()->where('student_id', $two->id)->count())->toBe(1);

    expect($run->cohort_count)->toBe(3)
        ->and($run->billed_count + $run->already_billed_count + $run->failed_count)
        ->toBe(2)
        ->and($run->billed_count + $run->already_billed_count + $run->failed_count)
        ->toBeLessThan($run->cohort_count);
});

/* ── 9 · The run's own cohort accounting is EXACT (FIX 5) ──────────────────────────────────── */

test('FIX5: billed + already_billed + failed equals cohort_count, on a run where all three are non-empty', function () {
    $ctx = birSchool();
    birSchedule($ctx);

    // already_billed × 1 — billed by a first run, which is how the state actually arises.
    birStudent($ctx, $ctx['arm']->id, $ctx['term']->id);
    birRun($ctx);

    // billed × 2
    birStudent($ctx, $ctx['arm']->id, $ctx['term']->id);
    birStudent($ctx, $ctx['arm']->id, $ctx['term']->id);

    // failed × 1 — the episode vanishes between the cohort read and the bill.
    $doomed = birStudent($ctx, $ctx['arm']->id, $ctx['term']->id);
    birProviderLosing((string) StudentCurriculum::withoutGlobalScopes()->where('student_id', $doomed->id)->value('uuid'));

    // Noise the equality must ignore: an unplaceable student and one at another class level. Both
    // move the SCHOOL-WIDE figures and neither may touch the cohort accounting.
    birStudent($ctx, $ctx['arm']->id, null);
    birStudent($ctx, $ctx['arm2']->id, $ctx['term']->id);

    $run = birRun($ctx);

    expect($run->status)->toBe(BulkInvoiceRunStatus::Completed);

    // THE INVARIANT, ASSERTED FIRST so a red names the defect rather than a bucket total.
    // `cohort_count` is the size of the list the run WALKED; the three summed here are counted from
    // the rows it PERSISTED. Two independent sources — which is the only reason this assertion is
    // worth writing down, and the only reason it can fail.
    expect($run->billed_count + $run->already_billed_count + $run->failed_count)->toBe(
        $run->cohort_count,
        'the run walked '.$run->cohort_count.' cohort members but only accounted for '
        .($run->billed_count + $run->already_billed_count + $run->failed_count)
        .' of them — a student the run saw has no row, so nothing records what happened to them'
    );

    // Then the buckets individually, so the equality above cannot be satisfied by a wrong split.
    expect($run->billed_count)->toBe(2)
        ->and($run->already_billed_count)->toBe(1)
        ->and($run->failed_count)->toBe(1)
        ->and($run->cohort_count)->toBe(4);

    // And the school-wide residual is a DIFFERENT statement, unaffected by any of the above: it is
    // the unplaceable student and the one at the other class level, and it is NOT a miss count.
    expect($run->unplaceable_count)->toBe(1)
        ->and($run->outside_coordinates_count)->toBe(1)
        ->and($run->billable_count)->toBe(6);
});

/* ── 10 · A dead worker no longer strands a run in `running` (FIX 2) ───────────────────────── */

test('FIX2: failed() closes a run the worker died in the middle of', function () {
    $ctx = birSchool();

    // The state a killed worker leaves behind: handle() sets `running` BEFORE its try, so a fatal
    // or a timeout runs neither the catch nor the finally and nothing else writes this row.
    $run = ActiveSchool::runFor($ctx['school']->id, fn () => BulkInvoiceRun::create([
        'school_id' => $ctx['school']->id, 'term_id' => $ctx['term']->id,
        'class_level_id' => $ctx['level']->id, 'status' => BulkInvoiceRunStatus::Running,
        'started_at' => now(),
    ]));

    // The queue's own terminal hook, invoked the way the framework invokes it — outside any job
    // middleware, so SchoolAware has NOT run and the method must establish its own School context.
    (new ProcessBulkInvoiceRun($run->id, $ctx['school']->id))
        ->failed(new RuntimeException('Job has timed out.'));

    $run->refresh();

    expect($run->status)->toBe(BulkInvoiceRunStatus::Failed,
        'a run whose worker died must not sit in `running` forever with no writer')
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->failure_reason)->toContain('the worker died before it could report')
        ->and($run->failure_reason)->toContain('Job has timed out.');
});

test('FIX2: failed() refuses to overwrite a run that already reached a terminal state', function () {
    $ctx = birSchool();
    birSchedule($ctx);
    birStudent($ctx, $ctx['arm']->id, $ctx['term']->id);

    $run = birRun($ctx);
    expect($run->status)->toBe(BulkInvoiceRunStatus::Completed);

    // A late queue-level death must not rewrite the outcome of a run that finished its work.
    (new ProcessBulkInvoiceRun($run->id, $ctx['school']->id))
        ->failed(new RuntimeException('late'));

    expect($run->refresh()->status)->toBe(BulkInvoiceRunStatus::Completed)
        ->and($run->failure_reason)->toBeNull()
        ->and($run->billed_count)->toBe(1);
});

test('FIX3: the private run-level failure helper does not shadow InteractsWithQueue::fail()', function () {
    // `fail()` is the trait's PUBLIC method — the queue's own "mark this job failed" call. A private
    // method of the same name on the class silently wins over it, Larastan sees nothing, and the
    // trap springs the moment someone adds `failed()` beside it (FIX 2 did exactly that). Pinned by
    // reflection so a future rename back is a red test, not a discovery.
    $method = new ReflectionMethod(ProcessBulkInvoiceRun::class, 'fail');

    expect($method->isPublic())->toBeTrue('ProcessBulkInvoiceRun::fail() must be the trait method, not a private override');

    // getDeclaringClass() reports the USING class for a trait method, so it cannot tell the two
    // apart. The file can: the trait's method lives in the trait's file, an override lives in the
    // job's. This is the assertion that actually distinguishes them.
    expect($method->getFileName())->toBe((new ReflectionClass(InteractsWithQueue::class))->getFileName());

    // And the run-level helper still exists under its own name, declared in the job.
    $ours = new ReflectionMethod(ProcessBulkInvoiceRun::class, 'failRun');
    expect($ours->isPrivate())->toBeTrue()
        ->and($ours->getFileName())->toBe((new ReflectionClass(ProcessBulkInvoiceRun::class))->getFileName());
});

/* ── 11 · Both subtrahends are LIST sizes, and both lists have an alarm (FIX A) ────────────── */

/**
 * Block the row write for ONE named enrollment, and nothing else.
 *
 * A model `creating` listener rather than a decorated provider, because the list must stay exactly
 * what the real adapter returned — the whole question is what happens when the LIST and the ROWS
 * disagree, and a decorator that changed the list would be changing the wrong side of it.
 *
 * A BLOCK CLOSURE, NOT AN ARROW FN. `creating` is a HALTING event (dispatched via `until()`), so an
 * arrow fn returning a value silently cancels the rest of the chain — the AddUuid/BelongsToSchool
 * failure mode the boundary lint's `halting-event-arrow-fn` rule exists for.
 */
function birBlockRowFor(int $enrollmentId): void
{
    BulkInvoiceRunRow::creating(function (BulkInvoiceRunRow $row) use ($enrollmentId): void {
        if ((int) $row->enrollment_id === $enrollmentId) {
            throw new RuntimeException('planted: this row cannot be written');
        }
    });
}

test('FIXA: both equalities hold and the residual subtracts the LIST sizes on a whole run', function () {
    $ctx = birSchool();
    birSchedule($ctx);

    birStudent($ctx, $ctx['arm']->id, $ctx['term']->id);          // billed
    birStudent($ctx, $ctx['arm']->id, null);                      // unplaceable
    birStudent($ctx, $ctx['arm']->id, null);                      // unplaceable
    birStudent($ctx, $ctx['arm2']->id, $ctx['term']->id);         // outside these coordinates

    $run = birRun($ctx);

    expect($run->status)->toBe(BulkInvoiceRunStatus::Completed);

    // THE SECOND EQUALITY, which did not exist before this fix: the unplaceable list had no alarm
    // at all, so a row lost out of it was invisible.
    expect($run->unplaceable_count)->toBe(
        $run->unplaceable_listed_count,
        'the run listed '.$run->unplaceable_listed_count.' unplaceable enrollments and recorded '
        .$run->unplaceable_count.' — an unplaceable student the run saw has no row'
    );

    expect($run->cohort_count)->toBe(1)
        ->and($run->unplaceable_listed_count)->toBe(2)
        ->and($run->unplaceable_count)->toBe(2)
        ->and($run->billable_count)->toBe(4)
        ->and($run->outside_coordinates_count)->toBe(1);
});

test('FIXA: a blocked unplaceable row fires the unplaceable alarm and does NOT move the residual', function () {
    $ctx = birSchool();
    birSchedule($ctx);

    birStudent($ctx, $ctx['arm']->id, $ctx['term']->id);          // billed
    $blocked = birStudent($ctx, $ctx['arm']->id, null);           // unplaceable, row blocked below
    birStudent($ctx, $ctx['arm']->id, null);                      // unplaceable, records fine
    birStudent($ctx, $ctx['arm2']->id, $ctx['term']->id);         // outside these coordinates

    birBlockRowFor((int) StudentCurriculum::withoutGlobalScopes()->where('student_id', $blocked->id)->value('id'));

    $run = birRun($ctx);

    // The run survives it — an unrecordable row is a per-student fault (FIX 1).
    expect($run->status)->toBe(BulkInvoiceRunStatus::Completed)
        ->and($run->billed_count)->toBe(1);

    // THE ALARM FIRES. Two listed, one recorded.
    expect($run->unplaceable_listed_count)->toBe(2)
        ->and($run->unplaceable_count)->toBe(1)
        ->and($run->unplaceable_count)->toBeLessThan($run->unplaceable_listed_count);

    // AND THE RESIDUAL DOES NOT ABSORB THE LOST ROW. This is the assertion FIX A exists for:
    // subtracting the unplaceable ROW count would read 2 here, quietly turning one lost row into
    // "one more student is priced elsewhere" — a defect moving out of an alarm and into a figure
    // that is large and unalarming on every healthy run.
    expect($run->outside_coordinates_count)->toBe(
        1,
        'the residual must be computed from the unplaceable LIST size, so a lost row stays in the '
        .'unplaceable alarm instead of draining into a number nobody reads as a problem'
    );
});

/* ── 12 · A refused write must not leave its payload for failRun() to persist (FIX B) ──────── */

/**
 * Refuse exactly ONE `BulkInvoiceRun` update — the first whose dirty payload matches — then disarm.
 *
 * It has to disarm: the point of the plant is what the NEXT write does, so a listener that kept
 * throwing would block the very thing under test.
 */
function birRefuseRunUpdateOnce(Closure $matches): void
{
    $state = new stdClass;
    $state->fired = false;

    BulkInvoiceRun::updating(function (BulkInvoiceRun $run) use ($matches, $state): void {
        if (! $state->fired && $matches($run->getDirty())) {
            $state->fired = true;

            throw new RuntimeException('planted: this update is refused');
        }
    });
}

/**
 * Every figure `reconcile()` writes must be absent, NAMED ONE AT A TIME so a red says WHICH column a
 * failed run inherited. A chained `->and(...)->toBeNull()` reports only "2 is null" and leaves the
 * reader to work out which of ten columns that was.
 */
function birExpectNoFigures(object $stored): void
{
    $figures = [
        'cohort_count', 'billed_count', 'already_billed_count', 'failed_count',
        'unplaceable_listed_count', 'unplaceable_count', 'billable_count', 'outside_coordinates_count',
    ];

    foreach ($figures as $column) {
        expect($stored->{$column})->toBeNull(
            "[{$column}] was written by an update that was REFUSED — a failing run must not inherit "
            .'the figures of a run that did not close'
        );
    }
}

/** The stored row, read past the model, so nothing in memory can dress it up. */
function birStoredRun(BulkInvoiceRun $run): object
{
    return DB::table('finance_bulk_invoice_runs')->where('id', $run->id)->first();
}

test('FIXB: a refused run-transition leaves a failed run with no started_at it never persisted', function () {
    $ctx = birSchool();
    birSchedule($ctx);
    birStudent($ctx, $ctx['arm']->id, $ctx['term']->id);

    // The very first write process() makes: pending -> running, with started_at.
    birRefuseRunUpdateOnce(fn (array $dirty) => ($dirty['status'] ?? null) === BulkInvoiceRunStatus::Running->value);

    $run = birRun($ctx);
    $stored = birStoredRun($run);

    // Column by column, against the row.
    expect($stored->status)->toBe('failed')
        ->and($stored->started_at)->toBeNull('the transition was refused, so the database never held a started_at — a failed run must not report one')
        ->and($stored->finished_at)->not->toBeNull()
        ->and($stored->failure_reason)->not->toBeNull()
        ->and($stored->fee_schedule_id)->toBeNull();

    birExpectNoFigures($stored);
});

test('FIXB: a refused closing write leaves a failed run with NONE of the figures it never stored', function () {
    $ctx = birSchool();
    birSchedule($ctx);
    birStudent($ctx, $ctx['arm']->id, $ctx['term']->id);
    birStudent($ctx, $ctx['arm']->id, $ctx['term']->id);
    birStudent($ctx, $ctx['arm']->id, null);

    // reconcile()'s single update — the one carrying every count.
    birRefuseRunUpdateOnce(fn (array $dirty) => array_key_exists('billed_count', $dirty));

    $run = birRun($ctx);
    $stored = birStoredRun($run);

    // THIS IS THE ONE A SCREEN WOULD RENDER: before the fix the row said `failed` while carrying a
    // complete and CORRECT set of counts — 2 billed, 1 unplaceable, a balanced cohort equality —
    // because failRun() flushed the payload the refused update had left dirty on the model. A
    // credible full report under the word "failed" is worse than an obviously empty one.
    expect($stored->status)->toBe('failed')
        ->and($stored->failure_reason)->not->toBeNull()
        ->and($stored->finished_at)->not->toBeNull()
        // The transition DID persist here, so this one is legitimately set.
        ->and($stored->started_at)->not->toBeNull()
        ->and($stored->fee_schedule_id)->not->toBeNull();

    // And not one figure from the write that was refused.
    birExpectNoFigures($stored);

    // The invoices the run DID raise are untouched — the run failed, the money it made did not
    // un-happen, and re-running returns them as already billed.
    expect(Invoice::withoutGlobalScopes()->count())->toBe(2);

    // And the in-memory model agrees with the row rather than with the payload that was refused.
    expect($run->refresh()->billed_count)->toBeNull();
});

/* ── 13 · Nobody billed is not "completed" (FIX C) ─────────────────────────────────────────── */

test('FIXC: an environmental fault that fails every student leaves the run FAILED, not completed', function () {
    $ctx = birSchool();
    birSchedule($ctx);
    birStudent($ctx, $ctx['arm']->id, $ctx['term']->id);
    birStudent($ctx, $ctx['arm']->id, $ctx['term']->id);
    birStudent($ctx, $ctx['arm']->id, $ctx['term']->id);

    // The shape a lost connection takes on this path: the first read inside GenerateInvoice blows up
    // for every student, and each blow-up is caught per student — which is exactly what lets an
    // outage wear the costume of N ordinary failures.
    birProviderWith(onFind: function (string $uuid) {
        throw new RuntimeException('SQLSTATE[HY000] [2006] MySQL server has gone away');
    });

    $run = birRun($ctx);

    expect($run->status)->toBe(
        BulkInvoiceRunStatus::Failed,
        'three students, three failures, nothing billed: "Completed — 0 billed, 3 failed" is a green '
        .'word over a total outage'
    )
        ->and($run->failure_reason)->toContain('Every one of the 3 students in this cohort failed')
        // The rows are still there and still carry the real reason — the heuristic changes the word
        // on the RUN, it does not replace the diagnosis.
        ->and($run->failed_count)->toBe(3)
        ->and($run->cohort_count)->toBe(3)
        ->and($run->billed_count)->toBe(0);

    $reasons = BulkInvoiceRunRow::withoutGlobalScopes()->where('run_id', $run->id)->pluck('reason');
    expect($reasons)->toHaveCount(3);
    foreach ($reasons as $reason) {
        expect($reason)->toContain('MySQL server has gone away');
    }
});

test('FIXC: a genuine all-students-fail domain case is reported IDENTICALLY, because the run cannot tell them apart', function () {
    $ctx = birSchool();
    birSchedule($ctx);
    birStudent($ctx, $ctx['arm']->id, $ctx['term']->id);
    birStudent($ctx, $ctx['arm']->id, $ctx['term']->id);

    // No infrastructure fault anywhere: every episode legitimately fails to resolve at billing time,
    // which is the domain refusal GenerateInvoice raises by name. The rows differ from the test
    // above — the run row does not.
    birProviderWith(onFind: fn (string $uuid) => null);

    $run = birRun($ctx);

    expect($run->status)->toBe(BulkInvoiceRunStatus::Failed)
        ->and($run->failure_reason)->toContain('Every one of the 2 students in this cohort failed')
        ->and($run->failed_count)->toBe(2);

    expect(BulkInvoiceRunRow::withoutGlobalScopes()->where('run_id', $run->id)->first()->reason)
        ->toContain('No billable enrollment found');

    // SAID OUT LOUD: the two runs above are byte-identical at the run level and the rule does not
    // try to separate them. It is a statement about SHAPE — nothing was billed — not about cause.
    // The row reasons are the diagnosis.
});

test('FIXC: the rule does NOT fire on a partial outage — a pinned limitation, not an oversight', function () {
    $ctx = birSchool();
    birSchedule($ctx);
    $doomed = birStudent($ctx, $ctx['arm']->id, $ctx['term']->id);
    birStudent($ctx, $ctx['arm']->id, $ctx['term']->id);

    birProviderLosing((string) StudentCurriculum::withoutGlobalScopes()->where('student_id', $doomed->id)->value('uuid'));

    // HALF THE COHORT FAILING STILL REPORTS COMPLETED. This is the realistic shape of a flaky
    // connection and the heuristic misses it — stated here as a pinned limitation rather than left
    // for someone to discover as a surprise.
    $partial = birRun($ctx);
    expect($partial->status)->toBe(BulkInvoiceRunStatus::Completed)
        ->and($partial->billed_count)->toBe(1)
        ->and($partial->failed_count)->toBe(1);

});

test('FIXC: an empty cohort is a normal successful run, not a nobody-billed failure', function () {
    $ctx = birSchool();
    // A schedule at the SECOND class level, where no student is enrolled.
    birSchedule($ctx, level: $ctx['level2']);
    birStudent($ctx, $ctx['arm']->id, $ctx['term']->id);   // enrolled at the FIRST level

    $run = birRun($ctx, $ctx['level2']);

    expect($run->status)->toBe(BulkInvoiceRunStatus::Completed)
        ->and($run->cohort_count)->toBe(0)
        ->and($run->billed_count)->toBe(0)
        ->and($run->failure_reason)->toBeNull('a class level with nobody in it bills nobody, and that is not a failure');
});
