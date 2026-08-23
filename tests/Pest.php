<?php

use App\Finance\Actions\ApproveVoidRequest;
use App\Finance\Actions\SubmitVoidRequest;
use App\Finance\Models\BankAccount;
use App\Finance\Models\Invoice;
use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\ClassLevelTermParticipation;
use App\Models\Curriculum;
use App\Models\ExamType;
use App\Models\Guardian;
use App\Models\Role;
use App\Models\School;
use App\Models\Term;
use App\Models\User;
use App\Support\ActiveSchool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Create a School row. The project has no SchoolFactory, and School only
 * needs name + slug (uuid is auto-generated in the model's booted hook).
 */
function al_makeSchool(): School
{
    return School::create([
        'name' => 'Test School '.Str::random(6),
        'slug' => (string) Str::uuid(),
    ]);
}

/**
 * Create a User row directly. The bundled UserFactory is out of sync with
 * the schema (it inserts a `name` column that doesn't exist), so tests
 * build users from the real columns instead.
 */
function al_makeUser(int|string $schoolId): User
{
    return User::forceCreate([
        'uuid' => (string) Str::uuid(),
        'first_name' => 'Test',
        'last_name' => 'User '.Str::random(5),
        'email' => Str::uuid().'@example.test',
        'password' => bcrypt('password'),
        'school_id' => $schoolId,
        'two_factor_confirmed_at' => now(), // pre-enrolled; C7 tests null it explicitly
        'email_verified_at' => now(),       // settings routes sit behind 'verified'
    ]);
}

function al_makeGuardian(int|string $schoolId, int|string $userId): Guardian
{
    return Guardian::forceCreate([
        'uuid' => (string) Str::uuid(),
        'school_id' => $schoolId,
        'user_id' => $userId,
        'first_name' => 'Guardian',
        'last_name' => 'Test',
        'phone' => '0800'.random_int(1000000, 9999999),
        'status' => 'active',
    ]);
}

/*
|--------------------------------------------------------------------------
| Authentication scenario helpers
|--------------------------------------------------------------------------
|
| Express the supported multi-School authentication scenarios (§6.5, §7.1)
| instead of hand-assembling RBAC state in every test. School access is granted
| through the real path (grantSchoolAccess = school_user pivot + per-team role),
| so these hold under both the legacy union and the single-source path.
*/

/** A user with exactly one accessible School. */
function singleSchoolUser(array $attributes = []): User
{
    $school = al_makeSchool();
    $user = User::factory()->create(array_merge(['school_id' => $school->id], $attributes));
    $user->grantSchoolAccess($school, 'admin');

    return $user;
}

/** A user with several accessible Schools and no default context (must pick one). */
function multiSchoolUser(int $schools = 2, array $attributes = []): User
{
    $user = User::factory()->create($attributes); // no school_id: access via grants only
    foreach (range(1, $schools) as $ignored) {
        $user->grantSchoolAccess(al_makeSchool(), 'admin');
    }

    return $user;
}

/** A platform super admin (global context, no School). */
function superAdminUser(array $attributes = []): User
{
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $user = User::factory()->create($attributes);
    setPermissionsTeamId(null);
    $user->assignRole('super_admin');
    $user->flushSchoolAccessCache();

    return $user;
}

/** A user with zero accessible Schools (no pivot, no role, no school_id). */
function userWithNoAccessibleSchools(array $attributes = []): User
{
    return User::factory()->create(array_merge(['school_id' => null], $attributes));
}

/**
 * Void an invoice through the Ph3b maker-checker path — the one-step cancel route is RETIRED,
 * so pre-existing tests that used to `POST …/cancel` now drive submit→approve at the domain
 * layer instead: a fresh maker submits a void request, a distinct checker approves it (approval
 * is what flips the invoice to void + posts the reversal). Returns the CHECKER — the user the
 * invoice records as cancelled_by_user_id — so callers can assert on it.
 *
 * This deliberately uses the Actions, not HTTP: a single test actor can never hold both the
 * maker and the checker permission (the grant guard forbids it), so a two-person void cannot be
 * driven from one acting-as session. The HTTP surface is proven in FinanceApiAcceptanceTest.
 */
function voidInvoiceViaApproval(int $schoolId, string $invoiceUuid, string $reason = 'entered in error'): User
{
    $maker = User::factory()->create(['school_id' => $schoolId]);
    $checker = User::factory()->create(['school_id' => $schoolId]);

    ActiveSchool::runFor($schoolId, function () use ($invoiceUuid, $reason, $maker, $checker) {
        $invoice = Invoice::withoutGlobalScopes()->where('uuid', $invoiceUuid)->firstOrFail();
        $request = app(SubmitVoidRequest::class)->handle($invoice, $reason, $maker);
        app(ApproveVoidRequest::class)->handle($request, $checker);
    });

    return $checker;
}

/**
 * An ACTIVE bank account for the school currently in context, created on first use.
 *
 * Lives here rather than in each test file because 42 call sites across 12 files need one:
 * finance_payments.bank_account_id is required for portal-issued payments (the origin-keyed CHECK),
 * and finance_fee_items.bank_account_id is NOT NULL.
 *
 * THE SCHOOL IS RESOLVED FROM CONTEXT, and a wrong resolution CANNOT pass silently: the foreign key
 * is composite — (bank_account_id, school_id) -> finance_bank_accounts(id, school_id) — so an
 * account belonging to another school is refused by the database rather than quietly accepted. That
 * is what makes a context-resolving helper safe here; with a single-column FK it would not be.
 */
function testBankAccountId(?int $schoolId = null): int
{
    $schoolId ??= ActiveSchool::id() ?? (int) School::query()->value('id');

    return (int) BankAccount::withoutGlobalScopes()->firstOrCreate(
        ['school_id' => $schoolId, 'account_number' => 'TEST-'.$schoolId],
        ['label' => 'Test account', 'bank_name' => 'Test Bank'],
    )->id;
}

/** The same account as {@see testBankAccountId()}, addressed the way the API addresses it. */
function testBankAccountUuid(?int $schoolId = null): string
{
    $schoolId ??= ActiveSchool::id() ?? (int) School::query()->value('id');

    return (string) BankAccount::withoutGlobalScopes()
        ->where('id', testBankAccountId($schoolId))->value('uuid');
}

/*
|--------------------------------------------------------------------------
| Rollover fixture — sessions, terms, levels with participation slots
|--------------------------------------------------------------------------
|
| Built for RolloverCommandsTest and shared with RolloverSameRingContractTest,
| which needs the SAME cyclic world to prove the walk and the planner report
| one ring. Moved rather than duplicated for the reason CohortSiblings gives
| about its own query: two copies of a fixture drift, and a drifted fixture is
| worse than a duplicated one because the tests keep passing while they stop
| describing the same system.
|
*/

function rc_session(School $school, string $name): AcademicSession
{
    return AcademicSession::create([
        'school_id' => $school->id,
        'name' => $name,
        'slug' => 'sess-'.Str::random(8),
        'is_current' => false,
    ]);
}

function rc_term(AcademicSession $session, int $order): Term
{
    return Term::create([
        'academic_session_id' => $session->id,
        'school_id' => $session->school_id,
        'name' => "Term {$order}",
        'slug' => 'term-'.Str::random(8),
        'order' => $order,
        'start_date' => now()->addMonths($order * 3),
        'end_date' => now()->addMonths($order * 3 + 2),
        'status' => 'active',
    ]);
}

function rc_level(School $school, string $name, int $order, array $slots, array $attrs = []): array
{
    $level = ClassLevel::forceCreate(array_merge([
        'school_id' => $school->id, 'name' => $name, 'order' => $order,
    ], $attrs));

    foreach ($slots as $slot) {
        ClassLevelTermParticipation::forceCreate([
            'school_id' => $school->id,
            'class_level_id' => $level->id,
            'term_order' => $slot,
            'is_ccm' => false,
        ]);
    }

    $arm = ClassLevelArm::forceCreate([
        'school_id' => $school->id,
        'class_level_id' => $level->id,
        'arm_id' => Arm::firstOrCreate(['school_id' => $school->id, 'label' => 'B'])->id,
    ]);

    return [$level, $arm];
}

function rc_curriculum(School $school, ClassLevelArm $arm, Term $term, ExamType $et, bool $isCcm = false): Curriculum
{
    return Curriculum::create([
        'school_id' => $school->id,
        'term_id' => $term->id,
        'class_level_arm_id' => $arm->id,
        'exam_type_id' => $et->id,
        'status' => 'active',
        'is_ccm' => $isCcm,
        'min_subjects' => 1,
    ]);
}

function rc_world(): array
{
    $school = al_makeSchool();
    $admin = al_makeUser($school->id);
    $examType = ExamType::create(['school_id' => $school->id, 'name' => 'Internal', 'slug' => 'et-'.Str::random(8)]);
    $source = rc_session($school, '2025/2026');
    $target = rc_session($school, '2026/2027');

    return compact('school', 'admin', 'examType', 'source', 'target');
}

/**
 * A school whose progression graph contains a ring: Year 7 -> Year 8 -> Year 7.
 *
 * The database trigger permits this (it guards only the self-loop), which is exactly why the gate
 * has to exist in code — and why both the walk and the rollover pre-flight must agree about it.
 */
function rollover_cyclic_world(): array
{
    $w = rc_world();
    $t1 = rc_term($w['source'], 1);
    rc_term($w['target'], 1);

    [$a, $armA] = rc_level($w['school'], 'Year 7', 7, [1]);
    [$b] = rc_level($w['school'], 'Year 8', 8, [1]);

    $a->update(['next_class_level_id' => $b->id]);
    $b->update(['next_class_level_id' => $a->id]);

    rc_curriculum($w['school'], $armA, $t1, $w['examType']);

    return $w;
}
