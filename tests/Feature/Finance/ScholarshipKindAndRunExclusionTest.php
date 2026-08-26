<?php

/*
 * `scholarships.kind`, and the two things it changes about the bulk invoice run: the run REFUSES a
 * cohort whose scholarships are unconfigured, and it EXCLUDES the students on a sponsored scheme.
 *
 * THE DEFECT THESE ARMS EXIST TO CATCH IS A SILENT SUCCESS. Before this branch the run billed every
 * scholarship holder the standard fee schedule, including roughly seventy sponsored students whose
 * fees an outside organisation pays on a different basis. Nothing failed. The run reported success,
 * the counts balanced, and the only symptom available anywhere was a parent opening a full-price
 * invoice for a child who owes nothing — weeks later, against an append-only invoice that needs a
 * credit note to unwind. So every arm below asserts what was NOT written as hard as what was.
 *
 * WHAT MAKES EACH FIXTURE DISCRIMINATING, stated because a fixture whose degrees of freedom have
 * collapsed passes for the wrong reason while its name stays true:
 *
 *   - EVERY COHORT HAS AT LEAST TWO STUDENTS, and in the exclusion arms they are on DIFFERENT
 *     schemes. A single-student cohort cannot tell "the sponsored one was excluded" from "the run
 *     billed nobody", which is the same pass for opposite reasons.
 *   - THE DISCOUNT ARM CARRIES A PLAIN STUDENT BESIDE IT. Asserting only that a discount holder is
 *     billed would also pass if `kind` were ignored entirely — which is the pre-branch behaviour.
 *     The arm that discriminates is the one where a discount holder and a sponsored holder sit in
 *     the SAME cohort and only one of them is billed.
 *   - THE REFUSAL ARM ASSERTS ZERO INVOICE ROWS AND ZERO RUN ROWS, not merely `status === failed`.
 *     A run that billed half the cohort and then failed is also `failed`, and it is the disaster.
 *   - THE MIGRATION ARM SEEDS THE PRE-MIGRATION SHAPE. A test starting from a post-migration
 *     factory proves nothing about the 182 assignments that already exist in production.
 */

use App\Enums\ScholarshipKind;
use App\Enums\StudentStatusEnum;
use App\Enums\TermStatusEnum;
use App\Finance\Actions\CreateFeeSchedule;
use App\Finance\Enums\BulkInvoiceRunOutcome;
use App\Finance\Enums\BulkInvoiceRunStatus;
use App\Finance\Enums\FeeScheduleStatus;
use App\Finance\Jobs\ProcessBulkInvoiceRun;
use App\Finance\Models\BulkInvoiceRun;
use App\Finance\Models\BulkInvoiceRunRow;
use App\Finance\Models\Invoice;
use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\Curriculum;
use App\Models\Scholarship;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\Term;
use App\Support\ActiveSchool;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * A School with one term and one class level — the coordinates a run names.
 *
 * `sk` PREFIX, and the helpers are duplicated from BulkInvoiceRunTest rather than imported. Pest
 * defines a test file's functions when it loads that file, so calling another file's helper works
 * only if that file happened to load first. That is a load-order dependency, and it fails as a
 * collision the day both files are loaded in the same process.
 *
 * @return array{school: School, term: Term, level: ClassLevel, arm: ClassLevelArm}
 */
function skSchool(): array
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
        $level = ClassLevel::create(['school_id' => $school->id, 'name' => 'JSS 1', 'order' => 1]);
        $arm = ClassLevelArm::create([
            'school_id' => $school->id,
            'class_level_id' => $level->id,
            'arm_id' => Arm::create(['school_id' => $school->id, 'label' => strtoupper(Str::random(3))])->id,
        ]);

        return compact('school', 'term', 'level', 'arm');
    });
}

/** An ACTIVE fee schedule at $ctx's coordinates, one mandatory item. */
function skSchedule(array $ctx): void
{
    ActiveSchool::runFor($ctx['school']->id, function () use ($ctx) {
        $schedule = app(CreateFeeSchedule::class)->handle(
            $ctx['term']->id, $ctx['level']->id, 'v1-'.Str::random(4),
            [[
                'description' => 'Tuition', 'amount_minor' => 1000000, 'currency' => 'NGN',
                'is_mandatory' => true, 'is_discountable' => true, 'sort_order' => 0,
                'bank_account_id' => testBankAccountUuid($ctx['school']->id),
            ]],
        );

        DB::table('finance_fee_schedules')->where('id', $schedule->id)
            ->update(['status' => FeeScheduleStatus::Active->value]);
    });
}

/** A scholarship in $ctx's School. `$kind` null means the unconfigured backfill state. */
function skScholarship(array $ctx, string $name, ?ScholarshipKind $kind): Scholarship
{
    return ActiveSchool::runFor($ctx['school']->id, fn () => Scholarship::create([
        'school_id' => $ctx['school']->id,
        'name' => $name,
        'kind' => $kind,
    ]));
}

/** A student with one ACTIVE enrollment at $ctx's coordinates, optionally holding a scholarship. */
function skStudent(array $ctx, ?Scholarship $scholarship = null): Student
{
    return ActiveSchool::runFor($ctx['school']->id, function () use ($ctx, $scholarship) {
        $student = Student::factory()->create([
            'school_id' => $ctx['school']->id,
            'admission_number' => 'ADM-'.Str::random(8),
            'scholarship_id' => $scholarship?->id,
        ]);

        StudentCurriculum::create([
            'student_id' => $student->id,
            'school_id' => $ctx['school']->id,
            'curriculum_id' => Curriculum::factory()->create([
                'school_id' => $ctx['school']->id,
                'class_level_arm_id' => $ctx['arm']->id,
                'term_id' => $ctx['term']->id,
            ])->id,
            'status' => StudentStatusEnum::ACTIVE,
        ]);

        return $student;
    });
}

/** Insert the pending run row and dispatch. NO ambient context — SchoolAware must supply it. */
function skRun(array $ctx): BulkInvoiceRun
{
    $run = ActiveSchool::runFor($ctx['school']->id, fn () => BulkInvoiceRun::create([
        'school_id' => $ctx['school']->id,
        'term_id' => $ctx['term']->id,
        'class_level_id' => $ctx['level']->id,
        'status' => BulkInvoiceRunStatus::Pending,
    ]));

    ProcessBulkInvoiceRun::dispatch($run->id, $ctx['school']->id);

    return $run->refresh();
}

/** @return array<string, int> outcome => count, over one run's rows */
function skOutcomes(BulkInvoiceRun $run): array
{
    return BulkInvoiceRunRow::withoutGlobalScopes()->where('run_id', $run->id)
        ->get()->groupBy(fn (BulkInvoiceRunRow $row) => $row->outcome->value)
        ->map->count()->all();
}

/** The student ids this run gave a row with $outcome. Sorted, so it is a SET comparison. */
function skStudentsWith(BulkInvoiceRun $run, BulkInvoiceRunOutcome $outcome): array
{
    $ids = BulkInvoiceRunRow::withoutGlobalScopes()
        ->where('run_id', $run->id)->where('outcome', $outcome->value)
        ->pluck('student_id')->map(fn ($id) => (int) $id)->all();

    sort($ids);

    return $ids;
}

// ─────────────────────────────────────────────────────────────────────────────────────────────────
// 1 · A SPONSORED STUDENT IS NOT BILLED. EVERYONE ELSE IS.
// ─────────────────────────────────────────────────────────────────────────────────────────────────

it('excludes a sponsored student from the run and bills the rest of the cohort', function () {
    $ctx = skSchool();
    skSchedule($ctx);

    // THREE DISTINGUISHABLE POSITIONS, so no single wrong rule passes: one sponsored, one on a
    // DIFFERENT scheme that must still be billed, one on no scheme at all. A run that excluded
    // "anyone with a scholarship" would bill 1 of 3 and fail here; a run that ignored `kind` would
    // bill 3 of 3 and fail here too.
    $sponsored = skStudent($ctx, skScholarship($ctx, 'C2C', ScholarshipKind::Sponsored));
    $discount = skStudent($ctx, skScholarship($ctx, 'BSS', ScholarshipKind::Discount));
    $plain = skStudent($ctx);

    $run = skRun($ctx)->refresh();

    expect($run->status)->toBe(BulkInvoiceRunStatus::Completed);

    // THE SET, NOT THE COUNT. A count cannot tell "these two were billed" from "some other two",
    // and the swap is exactly the case that slips through.
    $billed = [$discount->id, $plain->id];
    sort($billed);

    expect(skStudentsWith($run, BulkInvoiceRunOutcome::Billed))->toBe($billed)
        ->and(skStudentsWith($run, BulkInvoiceRunOutcome::Sponsored))->toBe([$sponsored->id]);

    // AND NO INVOICE EXISTS FOR THEM. The row saying "sponsored" is worth nothing if an invoice was
    // raised anyway — that is the money question, and it is asked of finance_invoices directly.
    expect(Invoice::withoutGlobalScopes()->where('student_id', $sponsored->id)->count())->toBe(0)
        ->and(Invoice::withoutGlobalScopes()->where('student_id', $discount->id)->count())->toBe(1)
        ->and(Invoice::withoutGlobalScopes()->where('student_id', $plain->id)->count())->toBe(1);

    // THE COHORT EQUALITY STILL HOLDS, with the sponsored term in it.
    expect($run->cohort_count)->toBe(3)
        ->and($run->billed_count)->toBe(2)
        ->and($run->already_billed_count)->toBe(0)
        ->and($run->failed_count)->toBe(0)
        ->and($run->sponsored_count)->toBe(1)
        ->and($run->billed_count + $run->already_billed_count + $run->failed_count + $run->sponsored_count)
        ->toBe($run->cohort_count);

    // AND THE EXCLUDED STUDENT IS STILL COUNTED AS A COHORT MEMBER, so the residual that means
    // "priced at other coordinates" does not quietly absorb them.
    expect($run->outside_coordinates_count)->toBe(0);
});

it('records a sponsored row with no invoice and no failure reason', function () {
    $ctx = skSchool();
    skSchedule($ctx);

    $sponsored = skStudent($ctx, skScholarship($ctx, 'C2C', ScholarshipKind::Sponsored));
    skStudent($ctx);

    $run = skRun($ctx)->refresh();

    $row = BulkInvoiceRunRow::withoutGlobalScopes()
        ->where('run_id', $run->id)->where('student_id', $sponsored->id)->sole();

    // NOT A FAILURE. `reason` is what a screen prints under "what went wrong"; a sponsored student
    // is not a thing that went wrong, and a reason here would read as one.
    expect($row->outcome)->toBe(BulkInvoiceRunOutcome::Sponsored)
        ->and($row->invoice_id)->toBeNull()
        ->and($row->reason)->toBeNull();
});

it('does not fire the nobody-billed rule when the whole cohort is sponsored', function () {
    $ctx = skSchool();
    skSchedule($ctx);

    $c2c = skScholarship($ctx, 'C2C', ScholarshipKind::Sponsored);
    skStudent($ctx, $c2c);
    skStudent($ctx, $c2c);

    $run = skRun($ctx)->refresh();

    // A class level where every child is sponsored is a SUCCESSFUL run that billed nobody, not an
    // outage. The rule keys on `failed === cohort_count`, and a sponsored row is not a failed row —
    // this arm is what holds that apart, because the rule reads as "billed nothing".
    expect($run->status)->toBe(BulkInvoiceRunStatus::Completed)
        ->and($run->failure_reason)->toBeNull()
        ->and($run->billed_count)->toBe(0)
        ->and($run->sponsored_count)->toBe(2);
});

it('excludes a SOFT-DELETED sponsored student, who is still in the cohort and still billable', function () {
    $ctx = skSchool();
    skSchedule($ctx);

    // THE COHORT DELIBERATELY INCLUDES SOFT-DELETED STUDENTS. BillableEnrollmentAdapter reads
    // student_curricula and its EXISTS-through-students clause IGNORES deleted_at, by a ruling
    // recorded on billableEpisodes() — so a trashed student with an active episode IS billed.
    //
    // THIS ARM EXISTS BECAUSE THE FIRST IMPLEMENTATION GOT IT WRONG. Reading the assignment with
    // `Student::whereIn()` from inside the job let SoftDeletes apply, so this student came back as
    // holding NO scholarship and was billed the standard fee schedule — the exact silent
    // full-price invoice the whole change exists to prevent, reintroduced by the fix for it.
    $sponsored = skStudent($ctx, skScholarship($ctx, 'C2C', ScholarshipKind::Sponsored));
    $plain = skStudent($ctx);

    ActiveSchool::runFor($ctx['school']->id, fn () => Student::find($sponsored->id)->delete());

    expect(Student::withoutGlobalScopes()->find($sponsored->id)->deleted_at)->not->toBeNull();

    $run = skRun($ctx)->refresh();

    // Still walked as a cohort member...
    expect($run->cohort_count)->toBe(2)
        ->and(skStudentsWith($run, BulkInvoiceRunOutcome::Sponsored))->toBe([$sponsored->id])
        ->and(skStudentsWith($run, BulkInvoiceRunOutcome::Billed))->toBe([$plain->id]);

    // ...and NOT invoiced.
    expect(Invoice::withoutGlobalScopes()->where('student_id', $sponsored->id)->count())->toBe(0);
});

it('refuses the run when a SOFT-DELETED cohort member holds an unconfigured scholarship', function () {
    $ctx = skSchool();
    skSchedule($ctx);

    $student = skStudent($ctx, skScholarship($ctx, 'Legacy Scheme', null));
    skStudent($ctx);

    ActiveSchool::runFor($ctx['school']->id, fn () => Student::find($student->id)->delete());

    // The refusal must see them too — otherwise a trashed holder is the one hole left in the
    // pre-flight, and it fails OPEN (bills) rather than closed.
    $run = skRun($ctx)->refresh();

    expect($run->status)->toBe(BulkInvoiceRunStatus::Failed)
        ->and($run->failure_reason)->toContain('Legacy Scheme')
        ->and(Invoice::withoutGlobalScopes()->where('school_id', $ctx['school']->id)->count())->toBe(0);
});

// ─────────────────────────────────────────────────────────────────────────────────────────────────
// 2 · A DISCOUNT STUDENT IS BILLED EXACTLY AS TODAY.
// ─────────────────────────────────────────────────────────────────────────────────────────────────

it('bills a discount student exactly as it bills a student with no scholarship', function () {
    $ctx = skSchool();
    skSchedule($ctx);

    $discount = skStudent($ctx, skScholarship($ctx, 'BSS', ScholarshipKind::Discount));
    $plain = skStudent($ctx);

    $run = skRun($ctx)->refresh();

    $discounted = Invoice::withoutGlobalScopes()->where('student_id', $discount->id)->sole();
    $ordinary = Invoice::withoutGlobalScopes()->where('student_id', $plain->id)->sole();

    // THIS COMMIT APPLIES NO DISCOUNT, and that is asserted rather than assumed: the two invoices
    // are the same money and the same number of lines. An arm that only checked "a discount holder
    // is billed" would stay green if a reduction line appeared, which is the next commit's change
    // and must not arrive early or by accident.
    // `equals()` rather than comparing minor units: it throws on a currency mismatch, so the arm
    // cannot pass by comparing 1,000,000 of two different currencies.
    expect($discounted->total->equals($ordinary->total))->toBeTrue()
        ->and($discounted->lines()->count())->toBe($ordinary->lines()->count())
        ->and($discounted->lines()->where('kind', '!=', 'charge')->count())->toBe(0);

    expect(skOutcomes($run))->toBe(['billed' => 2])
        ->and($run->sponsored_count)->toBe(0);
});

// ─────────────────────────────────────────────────────────────────────────────────────────────────
// 3 · AN UNCONFIGURED SCHOLARSHIP FAILS THE WHOLE RUN, BEFORE THE FIRST ROW.
// ─────────────────────────────────────────────────────────────────────────────────────────────────

it('refuses the whole run when a cohort scholarship has no kind, and writes zero rows', function () {
    $ctx = skSchool();
    skSchedule($ctx);

    $unconfigured = skScholarship($ctx, 'Legacy Scheme', null);
    skStudent($ctx, $unconfigured);

    // TWO STUDENTS THE RUN COULD HAVE BILLED. With only the unconfigured holder in the cohort,
    // "zero invoices" would also be true of a run that simply had nobody to bill — the arm would
    // pass without the refusal existing at all.
    skStudent($ctx);
    skStudent($ctx, skScholarship($ctx, 'BSS', ScholarshipKind::Discount));

    $run = skRun($ctx)->refresh();

    expect($run->status)->toBe(BulkInvoiceRunStatus::Failed)
        ->and($run->failure_reason)->toContain('Legacy Scheme');

    // ZERO ROWS AND ZERO INVOICES — the property every per-run refusal has, asserted as a count
    // rather than inferred from the status. "Failed" is also what a run that billed half the cohort
    // and then died reports, and that is the disaster this arm is here to tell apart.
    expect(BulkInvoiceRunRow::withoutGlobalScopes()->where('run_id', $run->id)->count())->toBe(0)
        ->and(Invoice::withoutGlobalScopes()->where('school_id', $ctx['school']->id)->count())->toBe(0);

    // Not even the counts were written: reconcile() is downstream of the refusal.
    expect($run->cohort_count)->toBeNull();
});

it('names every unconfigured scholarship once, however many students hold it', function () {
    $ctx = skSchool();
    skSchedule($ctx);

    $one = skScholarship($ctx, 'Alpha Scheme', null);
    $two = skScholarship($ctx, 'Beta Scheme', null);

    skStudent($ctx, $one);
    skStudent($ctx, $one);
    skStudent($ctx, $two);

    $run = skRun($ctx)->refresh();

    expect($run->status)->toBe(BulkInvoiceRunStatus::Failed);

    // BOTH named, and the one held twice named ONCE. A message that repeats a scholarship per holder
    // is the "same sentence once per child" failure the run's own docblock rules out for the mapper.
    expect(substr_count((string) $run->failure_reason, 'Alpha Scheme'))->toBe(1)
        ->and(substr_count((string) $run->failure_reason, 'Beta Scheme'))->toBe(1);
});

it('refuses the run when a cohort scholarship belongs to another school', function () {
    $ctx = skSchool();
    skSchedule($ctx);

    // SCHEMA-REACHABLE, not contrived: students.scholarship_id references scholarships(id) and is
    // NOT composite with school_id, so nothing at the engine stops this. Written raw because the
    // model's SchoolScope is exactly what would refuse to let a test set it up through Eloquent.
    $foreign = skScholarship(skSchool(), 'Other School Scheme', ScholarshipKind::Sponsored);
    $student = skStudent($ctx);
    DB::table('students')->where('id', $student->id)->update(['scholarship_id' => $foreign->id]);

    skStudent($ctx);

    $run = skRun($ctx)->refresh();

    // WITHOUT THIS BRANCH THE RUN WOULD HAVE BILLED THEM. An unreadable scholarship looks, to every
    // filter, exactly like holding no scholarship — so a sponsored student whose row sits in the
    // wrong School would be billed the standard schedule by the very code written to stop that.
    expect($run->status)->toBe(BulkInvoiceRunStatus::Failed)
        ->and($run->failure_reason)->toContain('#'.$foreign->id)
        ->and(Invoice::withoutGlobalScopes()->where('school_id', $ctx['school']->id)->count())->toBe(0);

    // AND IT DOES NOT LEAK THE OTHER SCHOOL'S SCHOLARSHIP NAME through the error message.
    expect($run->failure_reason)->not->toContain('Other School Scheme');
});

// ─────────────────────────────────────────────────────────────────────────────────────────────────
// 4 · A COHORT WITH NO SCHOLARSHIP HOLDERS IS UNCHANGED.
// ─────────────────────────────────────────────────────────────────────────────────────────────────

it('leaves a cohort with no scholarship holders exactly as it was', function () {
    $ctx = skSchool();
    skSchedule($ctx);

    skStudent($ctx);
    skStudent($ctx);

    $run = skRun($ctx)->refresh();

    expect($run->status)->toBe(BulkInvoiceRunStatus::Completed)
        ->and(skOutcomes($run))->toBe(['billed' => 2])
        ->and($run->sponsored_count)->toBe(0)
        ->and($run->billed_count)->toBe(2)
        ->and($run->cohort_count)->toBe(2)
        ->and(Invoice::withoutGlobalScopes()->where('school_id', $ctx['school']->id)->count())->toBe(2);
});

// ─────────────────────────────────────────────────────────────────────────────────────────────────
// 5 · THE MIGRATION PRESERVES EVERY EXISTING ASSIGNMENT.
// ─────────────────────────────────────────────────────────────────────────────────────────────────

/**
 * SEEDED IN THE PRE-MIGRATION SHAPE AND THEN MIGRATED, which is the only way to say anything about
 * the rows that already exist in production. A test that started from the post-migration factory
 * would be describing rows the migration created, not rows it had to survive.
 *
 * THE PRE-MIGRATION SHAPE IS RECONSTRUCTED BY DROPPING THE COLUMN AND ITS TRIGGERS, rather than by
 * `migrate:rollback --step=N`. `--step` counts from the branch's latest migrations, so a sibling
 * migration sitting on top would be rolled back instead and the arm would pass having tested
 * nothing — the failure this repository has already been bitten by once.
 *
 * DDL COMMITS IMPLICITLY, so RefreshDatabase's transaction will NOT undo the drop. The `finally`
 * re-runs `up()` — which is idempotent by construction — so a failure here cannot leave the schema
 * broken for the rest of the run.
 */
it('adds kind in place and leaves every existing scholarship assignment untouched', function () {
    $ctx = skSchool();

    $bss = skScholarship($ctx, 'BSS', null);
    $c2c = skScholarship($ctx, 'C2C', null);

    $onBss = skStudent($ctx, $bss);
    $onC2c = skStudent($ctx, $c2c);
    $unassigned = skStudent($ctx);

    $before = DB::table('students')->whereIn('id', [$onBss->id, $onC2c->id, $unassigned->id])
        ->orderBy('id')->pluck('scholarship_id', 'id')->all();
    $scholarshipIdsBefore = DB::table('scholarships')->orderBy('id')->pluck('name', 'id')->all();

    $migration = require database_path('migrations/2026_08_26_100000_add_kind_to_scholarships_table.php');

    try {
        // ── Back to the pre-migration shape: a name and nothing else ──────────────────────────
        DB::unprepared('DROP TRIGGER IF EXISTS scholarships_kind_shape_bi');
        DB::unprepared('DROP TRIGGER IF EXISTS scholarships_kind_shape_bu');
        Schema::table('scholarships', fn ($table) => $table->dropColumn('kind'));

        expect(Schema::hasColumn('scholarships', 'kind'))->toBeFalse();

        $migration->up();

        // ── The ids are stable, so students.scholarship_id still points where it pointed ──────
        expect(DB::table('scholarships')->orderBy('id')->pluck('name', 'id')->all())
            ->toBe($scholarshipIdsBefore);

        expect(DB::table('students')->whereIn('id', [$onBss->id, $onC2c->id, $unassigned->id])
            ->orderBy('id')->pluck('scholarship_id', 'id')->all())
            ->toBe($before);

        // ── AND EVERY ROW BACKFILLED TO NULL. Not to a guess. ────────────────────────────────
        expect(DB::table('scholarships')->whereNull('kind')->count())
            ->toBe(DB::table('scholarships')->count());
    } finally {
        $migration->up();
    }
});

it('refuses a kind outside the domain at the database, and admits null', function () {
    $ctx = skSchool();
    $scholarship = skScholarship($ctx, 'BSS', null);

    // THE TRIGGER, NOT THE CAST. Written raw so the PHP enum is out of the path entirely — a domain
    // held only by an Eloquent cast is absent to every import, console and future job.
    expect(fn () => DB::table('scholarships')->where('id', $scholarship->id)->update(['kind' => 'bursary']))
        ->toThrow(QueryException::class, 'kind must be discount or sponsored');

    // CASE MATTERS, and that is what COLLATE utf8mb4_bin buys. Under the table's utf8mb4_unicode_ci
    // 'Sponsored' would be admitted and then MISSED by every `where('kind', 'sponsored')` read —
    // a row that looks configured and excludes nobody.
    expect(fn () => DB::table('scholarships')->where('id', $scholarship->id)->update(['kind' => 'Sponsored']))
        ->toThrow(QueryException::class, 'kind must be discount or sponsored');

    // NULL IS ADMITTED — it is the backfill, and refusing it would make the migration unable to
    // update its own rows.
    DB::table('scholarships')->where('id', $scholarship->id)->update(['kind' => null]);
    DB::table('scholarships')->where('id', $scholarship->id)->update(['kind' => 'sponsored']);

    expect(DB::table('scholarships')->where('id', $scholarship->id)->value('kind'))->toBe('sponsored');
});

it('refuses a run row outcome outside the widened domain', function () {
    $ctx = skSchool();
    skSchedule($ctx);
    skStudent($ctx);

    $run = skRun($ctx)->refresh();
    $row = BulkInvoiceRunRow::withoutGlobalScopes()->where('run_id', $run->id)->sole();

    // The widened trigger must still REFUSE, or widening it disabled it.
    expect(fn () => DB::table('finance_bulk_invoice_run_rows')->where('id', $row->id)
        ->update(['outcome' => 'skipped']))
        ->toThrow(QueryException::class, 'outcome must be billed');

    expect(fn () => DB::table('finance_bulk_invoice_run_rows')->where('id', $row->id)
        ->update(['outcome' => 'Sponsored']))
        ->toThrow(QueryException::class, 'outcome must be billed');
});
