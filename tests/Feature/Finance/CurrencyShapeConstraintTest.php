<?php

// The currency SHAPE invariant (^[A-Z]{3}$, case-sensitive) is now a DB CHECK on all ten *_currency
// columns. Three columns stand for the three write paths: a FormRequest-only column reached by a model
// create, the raw-DB path SubledgerPoster uses, and an append-only table with an existing immutability
// trigger the CHECK must not have displaced. 'ngn' — a right-currency wrong-case value — must be 3819.

use App\Finance\Enums\DiscountBasis;
use App\Finance\Enums\DiscountPolicyStatus;
use App\Finance\Models\DiscountPolicy;
use App\Models\School;
use App\Models\Student;
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

/** Raw-insert a finance_student_accounts row the way SubledgerPoster does, with the given currency. */
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
        'source_type' => 'payment', 'source_id' => 1, 'narration' => 'x', 'created_at' => now(), 'updated_at' => now(),
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
        DB::table('finance_ledger_transactions')->where('id', $id)->update(['narration' => 'tampered']);
        throw new RuntimeException('expected the immutability trigger to fire');
    } catch (QueryException $e) {
        expect((int) ($e->errorInfo[1] ?? 0))->toBe(1644);
    }
});
