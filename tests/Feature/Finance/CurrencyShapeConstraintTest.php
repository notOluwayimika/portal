<?php

// The currency SHAPE invariant (^[A-Z]{3}$, case-sensitive) is a DB CHECK on every *_currency column.
// Four columns stand for the four write paths: a FormRequest-only column reached by a model create,
// the raw-DB path SubledgerPoster uses, an append-only table with an existing immutability trigger the
// CHECK must not have displaced, and (4a) a scratch staging table with NO trigger at all, where the
// CHECK is the only door. 'ngn' — a right-currency wrong-case value — must be 3819 on all of them.

use App\Finance\Enums\DiscountBasis;
use App\Finance\Enums\DiscountPolicyStatus;
use App\Finance\Models\DiscountPolicy;
use App\Models\AcademicSession;
use App\Models\School;
use App\Models\Student;
use App\Models\Term;
use App\Support\ActiveSchool;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);
beforeEach(fn () => $this->seed(DatabaseSeeder::class));

/** Assert the closure throws a QueryException carrying MySQL driver code 3819 (a CHECK violation). */
function expect3819(Closure $fn): void
{
    try {
        $fn();
        throw new RuntimeException('expected a QueryException, none thrown');
    } catch (QueryException $e) {
        expect((int) ($e->errorInfo[1] ?? 0))->toBe(3819);
    }
}

/**
 * Raw-insert a finance_student_accounts row with the given currency, bypassing every PHP path — the
 * refusal below is then a proof about the DATABASE rather than about an Action.
 *
 * IT NO LONGER MIRRORS SubledgerPoster, and the comment that said it did was made false by
 * `fix/subledger-single-clock-frame`: that method now BINDS a PHP-captured instant into created_at
 * and updated_at, because MySQL's NOW() is in the session zone and every other timestamp in the
 * schema is in app.timezone (docs/handoff/tickets/stored-epoch-offset.md). The NOW(), NOW() here is
 * deliberately left alone: this helper exists to trip the balance_currency CHECK, no assertion in
 * this file reads a timestamp, and the clock frame is irrelevant to what it proves — changing it
 * would be churn. It is, however, the one occurrence of the pattern in tests/, which
 * docs/handoff/tickets/sql-clock-lint.md records against its survey.
 */
function insertAccount(int $schoolId, int $studentId, string $currency): void
{
    DB::insert(
        'INSERT INTO finance_student_accounts (uuid, school_id, student_id, balance_minor, balance_currency, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
        [(string) Str::orderedUuid(), $schoolId, $studentId, -1000, $currency],
    );
}

/** Raw-insert a finance_ledger_transactions row with the given currency. */
function insertLedger(int $schoolId, int $studentId, string $currency): int
{
    return DB::table('finance_ledger_transactions')->insertGetId([
        'uuid' => (string) Str::orderedUuid(), 'school_id' => $schoolId, 'student_id' => $studentId,
        'type' => 'payment', 'amount_minor' => -1000, 'amount_currency' => $currency,
        'source_type' => 'payment', 'source_id' => 1, 'posted_at' => now(), 'effective_at' => now()->toDateString(), 'narration' => 'x', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

// ── Path 1: FormRequest-only column, reached by a model create (bypasses the request) ──

it('finance_discount_policies.value_currency — a model create with "ngn" is refused 3819', function () {
    $school = School::factory()->create();
    ActiveSchool::runFor($school->id, function () use ($school) {
        expect3819(fn () => DiscountPolicy::create([
            'school_id' => $school->id, 'name' => 'Bad', 'basis' => DiscountBasis::Amount,
            'value_minor' => 5000, 'value_currency' => 'ngn', // wrong case
            'requires_approval' => false, 'status' => DiscountPolicyStatus::Active,
        ]));

        // Negative: a well-formed NGN amount policy inserts; and a PERCENT policy with BOTH value columns
        // NULL inserts — the shape CHECK must not have broken the legitimate absence of a value.
        DiscountPolicy::create(['school_id' => $school->id, 'name' => 'AmtOk', 'basis' => DiscountBasis::Amount, 'value_minor' => 5000, 'value_currency' => 'NGN', 'requires_approval' => false, 'status' => DiscountPolicyStatus::Active]);
        DiscountPolicy::create(['school_id' => $school->id, 'name' => 'PctOk', 'basis' => DiscountBasis::Percent, 'percent' => 10, 'requires_approval' => false, 'status' => DiscountPolicyStatus::Active]);
        expect(DiscountPolicy::whereIn('name', ['AmtOk', 'PctOk'])->count())->toBe(2);
    });
});

// ── Path 2: the raw-SQL path SubledgerPoster uses — the column whose corruption was real ──

it('finance_student_accounts.balance_currency — a raw DB::insert of "ngn" is refused 3819; "NGN" inserts', function () {
    $school = School::factory()->create();
    $a = Student::factory()->create(['school_id' => $school->id]);
    $b = Student::factory()->create(['school_id' => $school->id]);

    expect3819(fn () => insertAccount($school->id, $a->id, 'ngn'));
    insertAccount($school->id, $b->id, 'NGN'); // negative: valid inserts
    expect(DB::table('finance_student_accounts')->where('student_id', $b->id)->count())->toBe(1);
});

// ── Path 3: append-only table — CHECK fires on insert, immutability trigger STILL fires on update ──

it('finance_ledger_transactions.amount_currency — CHECK 3819 on insert; immutability trigger still 1644 on update', function () {
    $school = School::factory()->create();
    $stu = Student::factory()->create(['school_id' => $school->id]);

    expect3819(fn () => insertLedger($school->id, $stu->id, 'ngn'));  // CHECK
    $id = insertLedger($school->id, $stu->id, 'NGN');                  // negative: valid inserts

    // The pre-existing no_update immutability trigger must not have been displaced by adding the CHECK.
    try {
        DB::table('finance_ledger_transactions')->where('id', $id)->update(['posted_at' => now(), 'effective_at' => now()->toDateString(), 'narration' => 'tampered']);
        throw new RuntimeException('expected the immutability trigger to fire');
    } catch (QueryException $e) {
        expect((int) ($e->errorInfo[1] ?? 0))->toBe(1644);
    }
});

// ── Path 4: the 4a staging columns — a table with NO immutability trigger at all ──
//
// `finance_opening_balance_rows` is scratch: CASCADE-deleted from its batch, no append-only trigger,
// and nothing posts from it. That is exactly why its two new *_currency columns need this case — on
// the three tables above, a dropped CHECK would still leave a trigger in the way of a bad UPDATE;
// here the CHECK is the ONLY door, so a migration that re-added it without COLLATE utf8mb4_bin would
// admit 'ngn' with nothing else to notice. Both columns arrived in
// 2026_08_08_100000_realign_opening_balance_staging_for_per_fee_type_file.php with no watched red.

it('the 4a opening-balance currency columns — "ngn" is refused 3819 on all three; "NGN" inserts', function () {
    $school = School::factory()->create();

    // A batch needs a term (batches.term_id is NOT NULL with an FK — §9's open decision), so one is
    // built rather than assumed to be seeded.
    $termId = ActiveSchool::runFor($school->id, function () use ($school) {
        $session = AcademicSession::create([
            'school_id' => $school->id, 'name' => '2026/2027-'.Str::random(4),
            'slug' => 'sess-'.Str::random(8), 'is_current' => true,
        ]);

        return Term::create([
            'academic_session_id' => $session->id, 'school_id' => $school->id, 'name' => 'First Term',
            'slug' => 'term-'.Str::random(8), 'order' => 1,
            'start_date' => now()->subMonth(), 'end_date' => now()->addMonths(2), 'status' => 'active',
        ])->id;
    });

    $insertBatch = fn (?string $controlCurrency) => DB::table('finance_opening_balance_batches')->insertGetId([
        'uuid' => (string) Str::orderedUuid(), 'school_id' => $school->id,
        'batch_reference' => 'CUR-'.Str::random(6), 'filename' => 'x.csv', 'status' => 'draft',
        'row_count' => 0, 'file_row_count' => 0, 'cutover_date' => '2026-08-06',
        'control_total_minor' => 10000, 'control_total_currency' => $controlCurrency,
        'term_id' => $termId,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // The batch's own money column — L2's operator-typed witness — is under the same CHECK.
    expect3819(fn () => $insertBatch('ngn'));
    $batchId = $insertBatch('NGN');

    $insertRow = fn (string $label, ?string $balanceCurrency, ?string $totalCurrency) => DB::table('finance_opening_balance_rows')->insert([
        'uuid' => (string) Str::orderedUuid(), 'school_id' => $school->id, 'batch_id' => $batchId,
        'line_number' => 2, 'admission_number' => $label, 'fee_type_label' => 'Tuition',
        'balance_minor' => 1000, 'balance_currency' => $balanceCurrency,
        'student_total_balance_minor' => 1000, 'student_total_balance_currency' => $totalCurrency,
        'status' => 'ok', 'created_at' => now(), 'updated_at' => now(),
    ]);

    // Wrong case on either column is a CHECK violation — this is the case utf8mb4_unicode_ci would
    // have admitted had COLLATE utf8mb4_bin been left off the constraint.
    expect3819(fn () => $insertRow('ADM-BAD-1', 'ngn', 'NGN'));
    expect3819(fn () => $insertRow('ADM-BAD-2', 'NGN', 'ngn'));

    $insertRow('ADM-GOOD', 'NGN', 'NGN'); // negative: the valid shape inserts
    expect(DB::table('finance_opening_balance_rows')->where('batch_id', $batchId)->count())->toBe(1);
});
