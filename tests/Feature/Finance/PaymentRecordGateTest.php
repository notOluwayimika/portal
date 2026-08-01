<?php

// ADR 0048 D1 — `finance.payment.record` gates the two payment doors. Before it, both routes shipped
// under the `finance.access` group with NO ability of their own, so finance.access ALONE recorded a
// payment (PaymentController calls no authorize(); both FormRequests authorize()=true) — and a
// fabricated payment discharges real receivables (ADR 0048 D2). These arms pin the gate.
//
// Placed beside ApprovalsPageGateTest (the existing finance route-gate test) and, like it, mints actors
// from EXPLICIT PERMISSION LISTS, not role names — role membership is exactly what the grant commit
// changes, so a role-keyed test would move with the thing it is meant to check.

use App\Finance\Actions\GenerateInvoice;
use App\Finance\DTOs\InvoiceLineSpec;
use App\Models\Curriculum;
use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\User;
use App\Support\ActiveSchool;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);
beforeEach(fn () => $this->seed(DatabaseSeeder::class));

/**
 * A web-session user in $school holding EXACTLY $permissions via a dedicated role (mirrors
 * ApprovalsPageGateTest::pageGateUser).
 *
 * @param  list<string>  $permissions
 */
function paymentGateUser(School $school, array $permissions): User
{
    $roleName = 'pmtgate_'.substr(md5(implode(',', $permissions)), 0, 10);
    $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }
    $role->syncPermissions($permissions);

    $user = User::factory()->create(['school_id' => $school->id]);
    $user->grantSchoolAccess($school, $roleName);
    $user->flushSchoolAccessCache();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

/** An issued invoice with one outstanding line, plus its student — enough for a 201 payment. */
function paymentGateFixture(School $school): array
{
    $student = Student::factory()->create(['school_id' => $school->id]);
    $invoice = ActiveSchool::runFor($school->id, function () use ($school, $student) {
        $enrollment = StudentCurriculum::create([
            'student_id' => $student->id,
            'curriculum_id' => Curriculum::factory()->create(['school_id' => $school->id])->id,
            'status' => 'active',
        ]);

        return app(GenerateInvoice::class)->handle($enrollment->uuid, [new InvoiceLineSpec('Tuition', Money::fromKobo(100000))]);
    });

    return [$student, $invoice];
}

function postInvoicePayment(User $user, School $school, string $invoiceUuid)
{
    return test()->actingAs($user)->withSession(['school_id' => $school->id])
        ->postJson("/api/v1/finance/invoices/{$invoiceUuid}/payments", ['amount_minor' => 50000, 'payer_name' => 'X']);
}

function postAccountPayment(User $user, School $school, string $studentUuid)
{
    return test()->actingAs($user)->withSession(['school_id' => $school->id])
        ->postJson("/api/v1/finance/students/{$studentUuid}/payments", ['amount_minor' => 50000, 'payer_name' => 'X']);
}

// ── Arm 1 — finance.access WITHOUT finance.payment.record → 403 on both. THE WATCHED-RED ARM: run
//    this before the middleware lands and it returns 201 (today's hole), proving the assertion bites.
it('finance.access WITHOUT finance.payment.record is refused on BOTH payment routes (403)', function () {
    $school = School::factory()->create();
    [$student, $invoice] = paymentGateFixture($school);
    $user = paymentGateUser($school, ['finance.access']);

    postInvoicePayment($user, $school, $invoice->uuid)->assertForbidden();
    postAccountPayment($user, $school, $student->uuid)->assertForbidden();
});

// ── Arm 2 — finance.access PLUS finance.payment.record → succeeds on both.
it('finance.access PLUS finance.payment.record records on BOTH payment routes (201)', function () {
    $school = School::factory()->create();
    [$student, $invoice] = paymentGateFixture($school);
    $user = paymentGateUser($school, ['finance.access', 'finance.payment.record']);

    postInvoicePayment($user, $school, $invoice->uuid)->assertCreated();
    postAccountPayment($user, $school, $student->uuid)->assertCreated();
});

// ── Arm 3 — super_admin is NOT gate-blocked (Gate::before bypass applies; record is NOT a checker
//    ability). The invoice route records (201): super_admin holds no finance.payment.record yet passes
//    the permission gate — the concrete bypass proof. If finance.payment.record were read as a checker
//    ability, super_admin would be EXCLUDED from the bypass and 403 here (Part 1 would be wrong).
//
//    The account route deliberately asserts 422, NOT 201, and NOT 403. Why: super_admin passes the
//    permission gate the same way (not 403), but RecordAccountPayment then fail-closes on school context
//    — ActiveSchool::id() gives super_admin NO own-school fallback (line 54 excludes super admins; a
//    stateless test request carries no session/token school_id), so it 422s "No active School context".
//    That 422 is a context refusal from BELOW the gate, and its being 422 rather than 403 is itself proof
//    the gate let super_admin through. (The invoice route sidesteps this: RecordPayment takes school off
//    the bound invoice, not ActiveSchool.) This is pre-existing isolation behaviour, unrelated to this gate.
it('super_admin is not gate-blocked on either payment route (record is not a checker ability)', function () {
    config(['auth.gate_before_superadmin' => true]);
    $school = School::factory()->create();
    [$student, $invoice] = paymentGateFixture($school);

    setPermissionsTeamId(null);
    $super = User::factory()->create(['school_id' => $school->id]);
    $super->assignRole('super_admin');
    $super->flushSchoolAccessCache();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // Invoice route: records — the bypass passed the gate (super_admin holds no finance.payment.record).
    postInvoicePayment($super, $school, $invoice->uuid)->assertCreated();
    // Account route: reaches the Action (422 school-context), i.e. the gate did NOT 403 it.
    postAccountPayment($super, $school, $student->uuid)->assertStatus(422);
});

// ── Arm 4 — no finance.access at all → 403 (the outer group gate still fires first).
it('a user with NO finance.access is refused on BOTH payment routes (outer group gate)', function () {
    $school = School::factory()->create();
    [$student, $invoice] = paymentGateFixture($school);
    $user = paymentGateUser($school, []);

    postInvoicePayment($user, $school, $invoice->uuid)->assertForbidden();
    postAccountPayment($user, $school, $student->uuid)->assertForbidden();
});
