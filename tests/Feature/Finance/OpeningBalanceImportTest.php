<?php

/*
 * §9 commit 1 — the read-only WCBS opening-balance validator
 * (docs/handoff/opening-balance-import-spec.md Rev 2).
 *
 * Every rule the command claims to enforce has a RED case here, not just a green one: a rule whose
 * only test is the happy path is wallpaper, because a green is equally consistent with the rule
 * working, the rule never running, and the fixture quietly satisfying it. So each block pairs the
 * violation with the neighbouring value that must still be ACCEPTED — "0.00" beside a blank, a
 * negative balance beside a negative arrears, not_comparable beside an exception.
 *
 * Nothing here asserts a posting. There is no posting in this commit; the assertions that matter
 * most are the ones proving the tables stay empty of consequence.
 */

use App\Enums\TermStatusEnum;
use App\Finance\Contracts\BillableEnrollmentProvider;
use App\Finance\Enums\OpeningBalanceBatchStatus;
use App\Finance\Enums\OpeningBalanceRowStatus;
use App\Finance\Models\FeeItem;
use App\Finance\Models\FeeSchedule;
use App\Finance\Models\OpeningBalanceBatch;
use App\Finance\Models\OpeningBalanceRow;
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
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

const OB_HEADER = 'admission_number,wcbs_student_ref,prior_arrears,wcbs_billed_total,paid_to_date,wcbs_total_balance,wcbs_bill_reference,last_payment_date';

/**
 * A School with a current session, an active term, a class level and an arm — the coordinates a
 * fee schedule and an enrollment both key off.
 *
 * @return array{school: School, term: Term, level: ClassLevel, arm: ClassLevelArm}
 */
function obSchool(): array
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

        return ['school' => $school, 'term' => $term, 'level' => $level, 'arm' => $arm];
    });
}

/** A student in $school with $admission, enrolled ACTIVE on a curriculum for ($term, $arm). */
function obStudent(array $ctx, string $admission, ?Term $term = null): Student
{
    return ActiveSchool::runFor($ctx['school']->id, function () use ($ctx, $admission, $term) {
        $student = Student::factory()->create([
            'school_id' => $ctx['school']->id,
            'admission_number' => $admission,
        ]);

        $curriculum = Curriculum::factory()->create([
            'school_id' => $ctx['school']->id,
            'class_level_arm_id' => $ctx['arm']->id,
            'term_id' => ($term ?? $ctx['term'])->id,
        ]);

        StudentCurriculum::create([
            'student_id' => $student->id,
            'school_id' => $ctx['school']->id,
            'curriculum_id' => $curriculum->id,
            'status' => 'active',
        ]);

        return $student;
    });
}

/**
 * An ACTIVE fee schedule for ($term, $level) totalling $kobo across one item.
 *
 * Authored as a DRAFT and then published, because a DB trigger refuses an item on a non-draft
 * parent ("Fee items may only be added to a draft fee schedule") — the real lifecycle, not a
 * shortcut round it.
 */
function obSchedule(array $ctx, int $kobo): FeeSchedule
{
    return ActiveSchool::runFor($ctx['school']->id, function () use ($ctx, $kobo) {
        $schedule = FeeSchedule::create([
            'school_id' => $ctx['school']->id,
            'term_id' => $ctx['term']->id,
            'class_level_id' => $ctx['level']->id,
            'label' => 'JSS1 '.Str::random(4),
            'status' => 'draft',
        ]);

        FeeItem::create([
            'school_id' => $ctx['school']->id,
            'fee_schedule_id' => $schedule->id,
            'description' => 'Tuition',
            'amount' => Money::fromKobo($kobo),
            'is_mandatory' => true,
            'is_discountable' => true,
            'sort_order' => 0,
        ]);

        $schedule->update(['status' => 'active']);

        return $schedule->refresh();
    });
}

/** Write $lines (data rows; the header is prepended) to a temp CSV and return its path. */
function obCsv(array $lines): string
{
    $path = tempnam(sys_get_temp_dir(), 'ob').'.csv';
    file_put_contents($path, OB_HEADER."\n".implode("\n", $lines)."\n");

    return $path;
}

/** Run the validator. Returns the exit code. */
function obRun(array $ctx, string $csv, array $overrides = []): int
{
    return test()->artisan('finance:import-opening-balances', array_merge([
        '--file' => $csv,
        '--school' => (string) $ctx['school']->id,
        '--term' => (string) $ctx['term']->id,
        '--as-at' => '2026-08-06',
        '--batch-reference' => 'BATCH-'.Str::random(8),
        '--dry-run' => true,
    ], $overrides))->run();
}

/** The staged rows for the School, keyed by line number. */
function obRows(array $ctx): array
{
    return ActiveSchool::runFor($ctx['school']->id,
        fn () => OpeningBalanceRow::query()->orderBy('line_number')->get()->keyBy('line_number')->all());
}

function obBatch(array $ctx): OpeningBalanceBatch
{
    return ActiveSchool::runFor($ctx['school']->id, fn () => OpeningBalanceBatch::query()->latest('id')->firstOrFail());
}

/** The finding codes on a staged row. */
function obCodes(OpeningBalanceRow $row): array
{
    return array_column($row->findings ?? [], 'code');
}

// ── §1 — the identity, which is the whole defence against a mis-split extract ──

it('accepts a row satisfying the identity and rejects one that is off by a single kobo, naming both sides', function () {
    $ctx = obSchool();
    obStudent($ctx, 'ADM-OK');
    obStudent($ctx, 'ADM-BAD');

    // 25,000 + 100,000 − 60,000 = 65,000 ✓   |   the second row's checksum is one kobo out.
    $exit = obRun($ctx, obCsv([
        'ADM-OK,W1,25000.00,100000.00,60000.00,65000.00,BILL-1,',
        'ADM-BAD,W2,25000.00,100000.00,60000.00,65000.01,BILL-2,',
    ]));

    $rows = obRows($ctx);
    // NOT rejected, rather than Ok: these Schools have no priced class level, so a sound row is
    // legitimately `not_comparable` (§5). Asserting Ok here would be asserting the wrong thing and
    // would couple every parsing test to a fee schedule it does not care about.
    expect($rows[2]->status)->not->toBe(OpeningBalanceRowStatus::Rejected)
        ->and(obCodes($rows[2]))->not->toContain('identity_mismatch')
        ->and($rows[3]->status)->toBe(OpeningBalanceRowStatus::Rejected)
        ->and(obCodes($rows[3]))->toContain('identity_mismatch')
        ->and($exit)->toBe(1);

    // BOTH sides of the equation must be in the finding — a bare "identity failed" tells the
    // operator nothing about which column the extract mis-split.
    $message = collect($rows[3]->findings)->firstWhere('code', 'identity_mismatch')['message'];
    expect($message)->toContain('65000.00')   // what the three figures derive
        ->and($message)->toContain('65000.01') // what WCBS reported as the checksum
        ->and($message)->toContain('-1 kobo');
});

// ── §2 — blank ≠ zero ──

it('rejects a blank required column but accepts a literal 0.00 as a real zero', function () {
    $ctx = obSchool();
    obStudent($ctx, 'ADM-ZERO');
    obStudent($ctx, 'ADM-BLANK');

    $exit = obRun($ctx, obCsv([
        'ADM-ZERO,W1,0.00,0.00,0.00,0.00,BILL-1,',
        'ADM-BLANK,W2,,100000.00,60000.00,40000.00,BILL-2,',
    ]));

    $rows = obRows($ctx);
    expect($rows[2]->status)->not->toBe(OpeningBalanceRowStatus::Rejected)
        ->and($rows[2]->prior_arrears->toKobo())->toBe(0)   // a stated zero is a value, not an absence
        ->and($rows[3]->status)->toBe(OpeningBalanceRowStatus::Rejected)
        ->and(obCodes($rows[3]))->toContain('blank_required_column')
        ->and($rows[3]->prior_arrears)->toBeNull()          // never coerced to zero
        ->and($exit)->toBe(1);
});

// ── §7 — negatives ──

it('rejects a negative prior_arrears but accepts a negative wcbs_total_balance', function () {
    $ctx = obSchool();
    obStudent($ctx, 'ADM-CREDIT');
    obStudent($ctx, 'ADM-NEG');

    // Row 2: paid more than billed — legitimately in credit, so the checksum is negative.
    // Row 3: a negative arrears figure, which §7 forbids outright.
    $exit = obRun($ctx, obCsv([
        'ADM-CREDIT,W1,0.00,100000.00,150000.00,-50000.00,BILL-1,',
        'ADM-NEG,W2,-5000.00,100000.00,60000.00,35000.00,BILL-2,',
    ]));

    $rows = obRows($ctx);
    expect($rows[2]->status)->not->toBe(OpeningBalanceRowStatus::Rejected)
        ->and($rows[2]->wcbs_total_balance->toKobo())->toBe(-5000000)
        ->and($rows[3]->status)->toBe(OpeningBalanceRowStatus::Rejected)
        ->and(obCodes($rows[3]))->toContain('negative_amount')
        ->and($exit)->toBe(1);
});

// ── §2 — naira→kobo at the boundary, by integer string arithmetic ──

it('parses naira to exact kobo, including a value that loses a kobo through float round-tripping', function () {
    $ctx = obSchool();
    obStudent($ctx, 'ADM-BIG');
    obStudent($ctx, 'ADM-FLOAT');

    // The counter-example is CHECKED, not assumed. The brief suggested 8.07; on this PHP the
    // product rounds up to exactly 807.0 and 8.07 does not break at all, so a test built on it
    // would have passed no matter how the parser worked. 80000.15 does break: the double is a
    // hair under 8000015 and the cast truncates a kobo away.
    expect((int) ((float) '80000.15' * 100))->toBe(8000014);

    obRun($ctx, obCsv([
        'ADM-BIG,W1,0.00,120000.00,0.00,120000.00,BILL-1,',
        'ADM-FLOAT,W2,80000.15,0.00,0.00,80000.15,BILL-2,',
    ]));

    $rows = obRows($ctx);
    expect($rows[2]->wcbs_billed_total->toKobo())->toBe(12000000)
        ->and($rows[3]->prior_arrears->toKobo())->toBe(8000015)   // not 8000014
        ->and($rows[3]->status)->not->toBe(OpeningBalanceRowStatus::Rejected);
});

// ── §6/§7 — the join key ──

it('counts a file row matching no student, still stages the batch, and exits non-zero', function () {
    $ctx = obSchool();
    obStudent($ctx, 'ADM-REAL');

    $exit = obRun($ctx, obCsv([
        'ADM-REAL,W1,0.00,100000.00,100000.00,0.00,BILL-1,',
        'ADM-GHOST,W2,0.00,100000.00,100000.00,0.00,BILL-2,',
    ]));

    $rows = obRows($ctx);
    $batch = obBatch($ctx);

    expect($rows[3]->status)->toBe(OpeningBalanceRowStatus::Rejected)
        ->and(obCodes($rows[3]))->toContain('student_not_found')
        ->and($rows[3]->student_id)->toBeNull()
        ->and($batch->row_count)->toBe(2)          // the run completed and staged everything
        ->and($exit)->toBe(1);

    // A student is NEVER created from a finance import (§7).
    expect(ActiveSchool::runFor($ctx['school']->id, fn () => Student::query()->count()))->toBe(1);
});

it('raises a BATCH-level finding when the School has admission numbers that collide after trimming', function () {
    $ctx = obSchool();
    obStudent($ctx, 'ADM-DUP');
    obStudent($ctx, ' ADM-DUP');   // distinct at the unique index, identical after trim

    $exit = obRun($ctx, obCsv([
        'ADM-OTHER,W1,0.00,0.00,0.00,0.00,BILL-1,',
    ]));

    $batch = obBatch($ctx);
    expect(array_column($batch->findings ?? [], 'code'))->toContain('school_has_duplicate_admission_numbers')
        ->and($batch->status)->toBe(OpeningBalanceBatchStatus::Rejected)
        ->and($exit)->toBe(1);
});

// ── §5 — the comparison, and the three outcomes that must never be conflated ──

it('reports equal, exception with a signed difference, and not_comparable — and never counts not_comparable as an exception', function () {
    $ctx = obSchool();
    obSchedule($ctx, 10000000);          // the portal would bill ₦100,000 for JSS 1
    obStudent($ctx, 'ADM-EQUAL');
    obStudent($ctx, 'ADM-DIFF');

    // A second class level that U1 has NOT priced — its student is not comparable, not in error.
    $other = ActiveSchool::runFor($ctx['school']->id, function () use ($ctx) {
        $level = ClassLevel::create(['school_id' => $ctx['school']->id, 'name' => 'JSS 2', 'order' => 2]);

        return ClassLevelArm::create([
            'school_id' => $ctx['school']->id,
            'class_level_id' => $level->id,
            'arm_id' => Arm::create(['school_id' => $ctx['school']->id, 'label' => strtoupper(Str::random(3))])->id,
        ]);
    });
    $unpriced = obStudent(['school' => $ctx['school'], 'term' => $ctx['term'], 'arm' => $other], 'ADM-UNPRICED');

    obRun($ctx, obCsv([
        'ADM-EQUAL,W1,0.00,100000.00,0.00,100000.00,BILL-1,',
        'ADM-DIFF,W2,0.00,85000.00,0.00,85000.00,BILL-2,',      // a discount applied off-platform
        'ADM-UNPRICED,W3,0.00,70000.00,0.00,70000.00,BILL-3,',
    ]));

    $rows = obRows($ctx);

    // equal
    expect($rows[2]->status)->toBe(OpeningBalanceRowStatus::Ok)
        ->and($rows[2]->expected_billed->toKobo())->toBe(10000000)
        ->and(obCodes($rows[2]))->not->toContain('comparison_mismatch');

    // different → an EXCEPTION: still `ok`, both figures and the signed difference recorded
    expect($rows[3]->status)->toBe(OpeningBalanceRowStatus::Ok)
        ->and($rows[3]->expected_billed->toKobo())->toBe(10000000)
        ->and(obCodes($rows[3]))->toContain('comparison_mismatch');
    $message = collect($rows[3]->findings)->firstWhere('code', 'comparison_mismatch')['message'];
    expect($message)->toContain('100000.00')->and($message)->toContain('85000.00')
        ->and($message)->toContain('1500000 kobo');   // portal − WCBS, signed

    // no active schedule → NOT comparable, and NOT an exception
    expect($rows[4]->status)->toBe(OpeningBalanceRowStatus::NotComparable)
        ->and($rows[4]->expected_billed)->toBeNull()
        ->and(obCodes($rows[4]))->toContain('no_active_fee_schedule')
        ->and(obCodes($rows[4]))->not->toContain('comparison_mismatch')
        ->and($unpriced->exists)->toBeTrue();
});

// ── §7 — idempotency, at the DATABASE ──

it('refuses a re-run of the same batch_reference at the unique index, not in PHP', function () {
    $ctx = obSchool();
    obStudent($ctx, 'ADM-1');
    $csv = obCsv(['ADM-1,W1,0.00,0.00,0.00,0.00,BILL-1,']);

    expect(obRun($ctx, $csv, ['--batch-reference' => 'WCBS-2026-T1']))->toBe(0);

    // The SECOND run must die at the engine. Asserting the driver code — not a message, not an
    // exit code — is what proves the refusal is the index and not a guard clause someone can
    // delete: a PHP guard would raise a BusinessRuleException or return FAILURE, never 1062.
    try {
        obRun($ctx, $csv, ['--batch-reference' => 'WCBS-2026-T1']);
        throw new RuntimeException('expected the unique index to refuse the second run');
    } catch (QueryException $e) {
        expect((int) ($e->errorInfo[1] ?? 0))->toBe(1062);
    }

    expect(ActiveSchool::runFor($ctx['school']->id, fn () => OpeningBalanceBatch::query()->count()))->toBe(1);
});

// ── The scope boundary of this commit ──

it('refuses to run without --dry-run and writes nothing', function () {
    $ctx = obSchool();
    obStudent($ctx, 'ADM-1');

    $exit = test()->artisan('finance:import-opening-balances', [
        '--file' => obCsv(['ADM-1,W1,0.00,0.00,0.00,0.00,BILL-1,']),
        '--school' => (string) $ctx['school']->id,
        '--term' => (string) $ctx['term']->id,
        '--as-at' => '2026-08-06',
    ])->expectsOutputToContain('Posting is not implemented in this commit')->run();

    expect($exit)->toBe(1)
        ->and(ActiveSchool::runFor($ctx['school']->id, fn () => OpeningBalanceBatch::query()->count()))->toBe(0)
        ->and(ActiveSchool::runFor($ctx['school']->id, fn () => OpeningBalanceRow::query()->count()))->toBe(0);
});

// ── Isolation, asserted rather than inherited ──

it('never resolves a row against a student belonging to another School', function () {
    $ctx = obSchool();
    $other = obSchool();
    obStudent($other, 'ADM-ELSEWHERE');   // the SAME admission number exists — in the wrong School

    $exit = obRun($ctx, obCsv([
        'ADM-ELSEWHERE,W1,0.00,100000.00,100000.00,0.00,BILL-1,',
    ]));

    $rows = obRows($ctx);
    expect($rows[2]->status)->toBe(OpeningBalanceRowStatus::Rejected)
        ->and(obCodes($rows[2]))->toContain('student_not_found')
        ->and($rows[2]->student_id)->toBeNull()
        ->and($rows[2]->school_id)->toBe($ctx['school']->id)
        ->and($exit)->toBe(1);

    // And the other School saw nothing of this run.
    expect(ActiveSchool::runFor($other['school']->id, fn () => OpeningBalanceRow::query()->count()))->toBe(0);
});

// ── §5's control totals, and the batch's terminal state ──

it('persists the control totals and validates a clean file with exit 0', function () {
    $ctx = obSchool();
    obSchedule($ctx, 10000000);
    obStudent($ctx, 'ADM-A');
    obStudent($ctx, 'ADM-B');

    $exit = obRun($ctx, obCsv([
        'ADM-A,W1,25000.00,100000.00,60000.00,65000.00,BILL-1,2026-07-31',
        'ADM-B,W2,0.00,100000.00,100000.00,0.00,BILL-2,',
    ]));

    $batch = obBatch($ctx);
    expect($exit)->toBe(0)
        ->and($batch->status)->toBe(OpeningBalanceBatchStatus::Validated)
        ->and($batch->row_count)->toBe(2)
        ->and($batch->total_prior_arrears->toKobo())->toBe(2500000)
        ->and($batch->total_wcbs_billed->toKobo())->toBe(20000000)
        ->and($batch->total_paid_to_date->toKobo())->toBe(16000000)
        ->and(obRows($ctx)[2]->last_payment_date->format('Y-m-d'))->toBe('2026-07-31');
});

// ── The ACL port extension, exercised through its own consumer ──

it('resolves the enrollment term and class level through the port, one hop each', function () {
    $ctx = obSchool();
    $student = obStudent($ctx, 'ADM-PORT');

    $enrollment = ActiveSchool::runFor($ctx['school']->id,
        fn () => app(BillableEnrollmentProvider::class)->currentForStudent($student->id));

    expect($enrollment)->not->toBeNull()
        ->and($enrollment->termId)->toBe($ctx['term']->id)
        ->and($enrollment->classLevelId)->toBe($ctx['level']->id);
});
