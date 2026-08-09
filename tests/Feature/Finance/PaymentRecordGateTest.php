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
        ->postJson("/api/v1/finance/invoices/{$invoiceUuid}/payments", ['amount_minor' => 50000, 'received_at' => now()->toDateString(), 'payer_name' => 'X']);
}

function postAccountPayment(User $user, School $school, string $studentUuid)
{
    return test()->actingAs($user)->withSession(['school_id' => $school->id])
        ->postJson("/api/v1/finance/students/{$studentUuid}/payments", ['amount_minor' => 50000, 'received_at' => now()->toDateString(), 'payer_name' => 'X']);
}

/**
 * The same invoice-payment call, but as a STATEFUL request — the transport the SPA actually uses.
 *
 * The plain helpers above set a session and it is never read: `api/*` only gets session middleware
 * when Sanctum's EnsureFrontendRequestsAreStateful decides the request came from the frontend, which
 * it judges from Referer/Origin against `sanctum.stateful` (config/sanctum.php:21-26 — `localhost` is
 * in the default list). Without that header there is no session on the request, so
 * ActiveSchool::id() (app/Support/ActiveSchool.php:42) never sees `school_id` and a super_admin —
 * who is explicitly denied the own-school fallback at :54 — resolves to NO context at all.
 *
 * Adding the header is therefore not a trick to make a test pass; it is the difference between
 * "a super_admin with a school selected" and "a super_admin who has selected nothing", and those two
 * are now different behaviours that both need their own arm.
 */
function postInvoicePaymentInSchoolContext(User $user, School $school, string $invoiceUuid)
{
    return test()->actingAs($user)->withSession(['school_id' => $school->id])
        ->withHeader('Referer', config('app.url'))
        ->postJson("/api/v1/finance/invoices/{$invoiceUuid}/payments", ['amount_minor' => 50000, 'received_at' => now()->toDateString(), 'payer_name' => 'X']);
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
//    the gate let super_admin through. This is isolation behaviour, unrelated to this gate.
//
//    THIS ARM WAS SPLIT IN TWO when the finance transactional models were opted into
//    rbac.fail_closed_models, and the reason is worth keeping because the obvious fix was wrong.
//    Invoice is now fail-closed, and route-model BINDING is itself a scoped read — SubstituteBindings
//    sits in Laravel's middleware priority list AHEAD of both SetSchoolContext and the route's
//    `permission:` middleware, so a contextless request is now refused at 409 BEFORE either gate runs.
//    Simply changing assertCreated() to assertStatus(409) would therefore have kept a green arm that
//    asserts nothing about the bypass: flip auth.gate_before_superadmin to false and a 409 arm passes
//    identically, because the Gate is never reached. That is a vacuous assertion wearing the costume
//    of a passing test.
//
//    So the two claims are now proved separately, and each is bite-proved against a different mutation:
//      Arm 3a — the BYPASS. super_admin WITH a school selected records (201). Goes red if the bypass
//               is disabled, which is the thing this file exists to pin.
//      Arm 3b — the ISOLATION. super_admin with NO school selected is refused (409). Goes red when
//               the finance transactional batch is emptied — measured at 201, i.e. the payment is
//               recorded.
//
//    ONE MEASURED CORRECTION, because the obvious version of that sentence is wrong: removing
//    Invoice ALONE does NOT turn arm 3b red. Bite-proved — with Invoice off the list the request is
//    still refused, by `App\Finance\Models\PaymentAllocation`, which RecordPayment reads downstream
//    of the binding. Two independent models on the batch guard the same path, so no single-model
//    mutation is a valid bite-proof for this arm, and anyone re-deriving it with one will conclude
//    the arm is dead when it is not.
it('super_admin with a school selected is not gate-blocked on either payment route (record is not a checker ability)', function () {
    config(['auth.gate_before_superadmin' => true]);
    $school = School::factory()->create();
    [, $invoice] = paymentGateFixture($school);

    setPermissionsTeamId(null);
    $super = User::factory()->create(['school_id' => $school->id]);
    $super->assignRole('super_admin');
    $super->flushSchoolAccessCache();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // Invoice route: records — the bypass passed the gate (super_admin holds no finance.payment.record).
    // Stateful, so the selected school is visible and binding resolves; see the helper's docblock.
    postInvoicePaymentInSchoolContext($super, $school, $invoice->uuid)->assertCreated();

    // The ACCOUNT route's 422 moved to arm 3b, and not for tidiness: a stateful request STARTS a
    // session that later calls on the same test instance inherit, so a contextless assertion placed
    // after the one above would silently be testing a request that now HAS context. Measured: it
    // returned 201 when it sat here. A contextless claim has to be made in an arm that never
    // establishes context at all.
});

it('super_admin with NO school selected is refused at 409 before the gate — isolation, not authority', function () {
    // THE CAPABILITY REMOVAL, ACCEPTED AND SPECIFIED HERE RATHER THAN ONLY IN A REPORT.
    //
    // Before Invoice was opted into rbac.fail_closed_models, this request RECORDED A PAYMENT. A
    // super_admin who had selected no school could post against any school's invoice by uuid, and it
    // worked because RecordPayment takes the school off the BOUND invoice rather than off
    // ActiveSchool — so the missing context was never noticed by anything. Six finance routes had
    // that shape.
    //
    // That was the defect, and the 409 is the specification arriving. SchoolScope's docblock already
    // states the position: there is deliberately no super-admin exemption, because authority and
    // isolation are separate axes — a team-less super_admin bypasses AUTHORIZATION (Gate::before) and
    // does not thereby bypass SCHOOL ISOLATION. Selecting a school is the whole fix, and arm 3a above
    // shows the capability is intact the moment they do.
    //
    // 409 rather than 403 is load-bearing: it comes from MissingSchoolContextException::render(), so
    // it is a deliberate context refusal reaching the client rather than an unhandled 500.
    config(['auth.gate_before_superadmin' => true]);
    $school = School::factory()->create();
    [$student, $invoice] = paymentGateFixture($school);

    setPermissionsTeamId(null);
    $super = User::factory()->create(['school_id' => $school->id]);
    $super->assignRole('super_admin');
    $super->flushSchoolAccessCache();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // The ACCOUNT route, unchanged by this commit and asserted FIRST so no earlier stateful call in
    // this arm can have started a session for it to inherit. 422 is RecordAccountPayment's own
    // context refusal from BELOW the gate — and its being 422 rather than 403 is still the proof that
    // the permission gate admitted a super_admin holding no finance.payment.record. Two layers now
    // refuse a contextless platform admin, and they refuse in different places for different reasons.
    postAccountPayment($super, $school, $student->uuid)->assertStatus(422);

    $response = postInvoicePayment($super, $school, $invoice->uuid);

    expect($response->status())->toBe(409,
        'A super_admin with no school selected got '.$response->status().' posting a payment against '
        .'an invoice by uuid. 201 means the finance transactional batch left rbac.fail_closed_models '
        .'and a contextless platform admin can once again record money into any school; 403 would '
        .'mean the permission gate started refusing them, which is a different regression in '
        .'ADR 0040.');
});

// ── Arm 4 — no finance.access at all → 403 (the outer group gate still fires first).
it('a user with NO finance.access is refused on BOTH payment routes (outer group gate)', function () {
    $school = School::factory()->create();
    [$student, $invoice] = paymentGateFixture($school);
    $user = paymentGateUser($school, []);

    postInvoicePayment($user, $school, $invoice->uuid)->assertForbidden();
    postAccountPayment($user, $school, $student->uuid)->assertForbidden();
});
