<?php

/*
 * S6/S11 COMMIT 2 — money says which account it landed in, and a charge says which it is for.
 *
 * The rule is NOT "bank_account_id is required". It is a PAIRING with `origin`, and the two halves
 * fail for different reasons so they are asserted separately:
 *
 *   portal   MUST name an account — a payment that cannot be reconciled against a bank statement is
 *            the thing finance_bank_accounts exists to prevent.
 *   migrated MUST NOT — money WCBS collected before cutover never entered one of our accounts, and
 *            naming one would assert a fact that is false.
 *
 * A plain NOT NULL could only express the first, and would force every imported row to name an
 * account it never touched.
 */

use App\Enums\TermStatusEnum;
use App\Finance\Enums\FeeScheduleStatus;
use App\Finance\Models\BankAccount;
use App\Finance\Models\FeeSchedule;
use App\Models\AcademicSession;
use App\Models\ClassLevel;
use App\Models\School;
use App\Models\Student;
use App\Models\Term;
use App\Support\ActiveSchool;
use App\Support\SchoolDay;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(fn () => (new RbacSeeder)->run());

/** A raw payment row — past the Actions and past the FormRequests, so the DATABASE is what answers. */
function bafkInsertPayment(int $schoolId, int $studentId, string $origin, ?int $bankAccountId): int
{
    return DB::table('finance_payments')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'school_id' => $schoolId,
        'student_id' => $studentId,
        'reference' => random_int(1, 800_000_000),
        'amount_minor' => 10000,
        'amount_currency' => 'NGN',
        'payer_name' => 'Raw',
        'method' => 'manual',
        'origin' => $origin,
        'received_at' => SchoolDay::today(),
        'bank_account_id' => $bankAccountId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function bafkCode(QueryException $e): int
{
    return (int) ($e->errorInfo[1] ?? 0);
}

it('refuses a PORTAL payment with no bank account, at the database', function () {
    $school = School::factory()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);

    $code = null;
    try {
        bafkInsertPayment($school->id, $student->id, 'portal', null);
    } catch (QueryException $e) {
        $code = bafkCode($e);
    }

    expect($code)->toBe(3819,
        'A portal payment naming no bank account was accepted. It cannot be reconciled against any '
        .'statement, and finance_payments is append-only so the answer can never be supplied later.');
});

it('refuses a MIGRATED payment that DOES name a bank account', function () {
    // THE HALF A PLAIN NOT NULL COULD NOT EXPRESS. Money collected by WCBS before cutover never
    // entered one of our accounts; pointing it at one is a false statement, not a missing one.
    $school = School::factory()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    $account = ActiveSchool::runFor($school->id, fn () => BankAccount::create([
        'school_id' => $school->id, 'label' => 'Zenith', 'bank_name' => 'Zenith Bank',
        'account_number' => '1234567890',
    ]));

    $code = null;
    try {
        bafkInsertPayment($school->id, $student->id, 'migrated', $account->id);
    } catch (QueryException $e) {
        $code = bafkCode($e);
    }

    expect($code)->toBe(3819,
        'A migrated payment was allowed to name one of our bank accounts. The money never arrived '
        .'there — WCBS collected it before cutover — so the row asserts something false.');
});

it('accepts both legal pairings', function () {
    // Not vacuous in either direction: the two arms above would also pass if EVERY insert were
    // refused, which would mean finance could not record a payment at all.
    $school = School::factory()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    $account = ActiveSchool::runFor($school->id, fn () => BankAccount::create([
        'school_id' => $school->id, 'label' => 'Zenith', 'bank_name' => 'Zenith Bank',
        'account_number' => '1234567890',
    ]));

    $portal = bafkInsertPayment($school->id, $student->id, 'portal', $account->id);
    $migrated = bafkInsertPayment($school->id, $student->id, 'migrated', null);

    expect($portal)->toBeGreaterThan(0)->and($migrated)->toBeGreaterThan(0);
});

it('refuses a payment naming ANOTHER school’s bank account, at the database', function () {
    // THE COMPOSITE FOREIGN KEY. (bank_account_id, school_id) -> finance_bank_accounts(id,
    // school_id), so a cross-School reference is impossible rather than merely improbable: the PAIR
    // does not exist in the parent. A single-column FK would accept this row happily.
    $mine = School::factory()->create();
    $theirs = School::factory()->create();
    $student = Student::factory()->create(['school_id' => $mine->id]);

    $foreign = ActiveSchool::runFor($theirs->id, fn () => BankAccount::create([
        'school_id' => $theirs->id, 'label' => 'Theirs', 'bank_name' => 'Other Bank',
        'account_number' => '9999999999',
    ]));

    $code = null;
    try {
        bafkInsertPayment($mine->id, $student->id, 'portal', $foreign->id);
    } catch (QueryException $e) {
        $code = bafkCode($e);
    }

    expect($code)->toBe(1452,
        'A payment in one School was allowed to name another School’s bank account. The composite '
        .'foreign key is what makes that impossible; a single-column one would not.');
});

it('refuses a fee item with no bank account, at the database', function () {
    // NOT NULL with no default — a fee item that does not say where its money should go is not a
    // configured charge. Asserted as the driver code so a future DEFAULT would fail here rather
    // than quietly supplying one.
    $school = School::factory()->create();

    // A REAL schedule, so the missing bank_account_id is the ONLY thing wrong with the row. With a
    // dangling fee_schedule_id the insert fails 1452 on that foreign key first and never reaches
    // the column under test — an arm that would have passed for the wrong reason.
    $schedule = ActiveSchool::runFor($school->id, fn () => FeeSchedule::create([
        'school_id' => $school->id,
        'term_id' => Term::create([
            'academic_session_id' => AcademicSession::create([
                'school_id' => $school->id, 'name' => '2026/2027', 'slug' => 'sess-'.Str::random(8), 'is_current' => true,
            ])->id,
            'school_id' => $school->id, 'name' => 'Third Term', 'slug' => 'term-'.Str::random(8),
            'order' => 3, 'start_date' => now()->subMonths(4), 'end_date' => now()->subMonth(),
            'status' => TermStatusEnum::ACTIVE->value,
        ])->id,
        'class_level_id' => ClassLevel::create([
            'school_id' => $school->id, 'name' => 'JSS 1', 'order' => 1,
        ])->id,
        'label' => 'v1',
        'status' => FeeScheduleStatus::Draft,
    ]));

    $code = null;
    try {
        DB::table('finance_fee_items')->insert([
            'uuid' => (string) Str::uuid(),
            'school_id' => $school->id,
            'fee_schedule_id' => $schedule->id,
            'description' => 'Tuition',
            'amount_minor' => 10000,
            'amount_currency' => 'NGN',
            'is_mandatory' => 1,
            'is_discountable' => 1,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } catch (QueryException $e) {
        $code = bafkCode($e);
    }

    expect($code)->toBe(1364,
        'finance_fee_items.bank_account_id accepted a row without it — the column has gained a '
        .'default or become nullable, either of which fabricates a destination nobody chose.');
});

it('a deactivated account cannot be chosen for NEW money, but stays nameable by old money', function () {
    // THE POINT OF DEACTIVATION OVER DELETION, end to end. The payment recorded while the account
    // was active still resolves its name afterwards; a new payment naming it is refused at the edge.
    $school = School::factory()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    $account = ActiveSchool::runFor($school->id, fn () => BankAccount::create([
        'school_id' => $school->id, 'label' => 'Zenith', 'bank_name' => 'Zenith Bank',
        'account_number' => '1234567890',
    ]));

    $paymentId = bafkInsertPayment($school->id, $student->id, 'portal', $account->id);

    ActiveSchool::runFor($school->id, fn () => $account->update(['deactivated_at' => now()]));

    // The historical row still names it.
    $stillNamed = DB::table('finance_payments as p')
        ->join('finance_bank_accounts as b', 'b.id', '=', 'p.bank_account_id')
        ->where('p.id', $paymentId)->value('b.label');

    expect($stillNamed)->toBe('Zenith',
        'A payment lost the name of the account it was reconciled against when that account was '
        .'retired. Deactivation exists precisely so that cannot happen.');

    // And the account is no longer offerable.
    $offerable = ActiveSchool::runFor($school->id,
        fn () => BankAccount::query()->active()->count());

    expect($offerable)->toBe(0);
});
