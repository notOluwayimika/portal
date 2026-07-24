<?php

use App\Http\Requests\PromoteStudentRequest;
use App\Http\Requests\RejectSubjectResultRequest;
use App\Models\Export;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Support\Impersonation;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/**
 * The standing invariant test from chore/superadmin-authority-probe — encodes
 * how super_admin authority ACTUALLY resolves, per decision path and per
 * auth.gate_before_superadmin flag state. Predictions were committed before
 * these observations (docs/handoff/superadmin-authority-probe-predictions.md);
 * this file records what the app passes BECAUSE of, so the next person does
 * not re-derive it. Like AuthorizationOrderingTest and FortifyPostureTest it
 * asserts a permanent property of the authorization model and survives the
 * ADR 0043 §5 teardown.
 *
 * Mechanisms these invariants rest on (vendor-read, spatie 7.4.1 / L13.11.2):
 *  - PermissionMiddleware:38 uses canAny() → the Gate → Gate::before applies.
 *  - Spatie registers its OWN Gate::before (PermissionRegistrar:116) returning
 *    true-or-NULL; the app's super-admin before also returns true-or-NULL.
 *    They compose safely ONLY because both are null-on-miss; either returning
 *    false would silently defeat the other. These cells pin that composition.
 */
function superAdmin(): User
{
    setPermissionsTeamId(null);
    $sa = User::factory()->create();
    $sa->assignRole('super_admin');

    return $sa;
}

beforeEach(function () {
    (new RbacSeeder)->run();
});

// ── Precondition re-derived live (C1's claim, independent of the fixture) ──

it('seeds super_admin(web) exactly the PLATFORM set — rbac.impersonate present BY NAME (the master key)', function () {
    $granted = Role::where('name', 'super_admin')->where('guard_name', 'web')
        ->firstOrFail()->permissions->pluck('name')->sort()->values();

    // The SET member-by-name, never a count: a drifted row totalling right
    // but missing rbac.impersonate would pass a count and strand super_admin
    // on de-bypass (ADR 0045 A3 — the same check the prod gate runs).
    expect($granted->all())->toEqual(collect(RbacSeeder::SUPER_ADMIN_PLATFORM)->sort()->values()->all())
        ->and($granted)->toContain('rbac.impersonate');
});

it('POST-DE-BYPASS INVARIANT (flag OFF, the 0045-C state proven early): platform as itself, domain only as someone', function () {
    config(['auth.gate_before_superadmin' => false]);
    $sa = superAdmin();
    $school = School::factory()->create();
    $teacher = User::factory()->create();
    setPermissionsTeamId($school->id);
    $teacher->assignRole('teacher');
    setPermissionsTeamId(null);
    $teacher->flushSchoolAccessCache();

    // As ITSELF: platform yes, school-scoped domain no.
    expect($sa->can('activity_log.view_system'))->toBeTrue()
        ->and($sa->can('rbac.manage_users'))->toBeTrue()
        ->and($sa->can('result.view_scores'))->toBeFalse()
        ->and($sa->can('student_subject.view'))->toBeFalse()
        ->and($sa->can('activity_log.view'))->toBeFalse();

    // Domain capability is NOT stranded: impersonation enters flag-off (the
    // explicit grant is flag-independent) and resolves domain as the target.
    $can = Impersonation::actAs($sa, $teacher, $school->id,
        fn () => auth()->user()->can('result.submit'));
    expect($can)->toBeTrue();
});

// ── Row 1: $user->can() ────────────────────────────────────────────────────
//
// C3 CHANGED THIS ROW, DELIBERATELY. The probe observed (2026-07-21, bypass ON)
// `can('result.approve') === true`. C3 implements ADR 0040's exclusion, so that
// exact call is now false — and that is the slice's whole point, not a
// regression. The probed MECHANISM (Mode A: the bypass really does decide at the
// Gate, regardless of the seeded absence) is unchanged and is still pinned here,
// now via a non-checker ability. See SuperAdminBypassExclusionTest.

it('row 1 — with the bypass ON, can() allows super_admin an unseeded ability (Mode A is real at the Gate)', function () {
    config(['auth.gate_before_superadmin' => true]);

    // Unseeded for super_admin (precondition test above) and NOT a checker
    // action, so a true here can only be the bypass — Mode A, still real.
    expect(superAdmin()->can('student_curriculum.promote'))->toBeTrue();
});

it('row 1 — the bypass never reaches a checker action, flag ON (ADR 0040, implemented in C3)', function () {
    config(['auth.gate_before_superadmin' => true]);

    expect(superAdmin()->can('result.approve'))->toBeFalse()
        ->and(superAdmin()->can('result.reject'))->toBeFalse();
});

it('row 1 — with the bypass OFF, the seeded absence is decisive: denies domain, allows platform', function () {
    config(['auth.gate_before_superadmin' => false]);
    $sa = superAdmin();

    expect($sa->can('result.approve'))->toBeFalse()
        ->and($sa->can('activity_log.view_system'))->toBeTrue(); // the explicit platform layer works
});

// ── Row 2: Spatie permission: middleware (decides Mode B / the C2 risk) ────

it('row 2 — permission: middleware routes through the Gate in v7.4.1: bypass ON passes super_admin (Mode B refuted)', function () {
    Route::get('/__probe/promote', fn () => response('ok'))
        ->middleware(['web', 'auth', 'permission:student_curriculum.promote']);

    config(['auth.gate_before_superadmin' => true]);
    $this->actingAs(superAdmin())->get('/__probe/promote')->assertOk();
});

it('row 2 — with the bypass OFF the same route 403s super_admin: C2 swap + flag-off = lockout, a declared dependency', function () {
    Route::get('/__probe/promote', fn () => response('ok'))
        ->middleware(['web', 'auth', 'permission:student_curriculum.promote']);

    config(['auth.gate_before_superadmin' => false]);
    $this->actingAs(superAdmin())->get('/__probe/promote')->assertForbidden();
});

it('row 2 — a permission: route on a CHECKER ability 403s super_admin even with the bypass ON (C3)', function () {
    Route::get('/__probe/approve', fn () => response('ok'))
        ->middleware(['web', 'auth', 'permission:result.approve']);

    config(['auth.gate_before_superadmin' => true]);
    $this->actingAs(superAdmin())->get('/__probe/approve')->assertForbidden();
});

// ── Row 3: Policy path ─────────────────────────────────────────────────────

it('row 3 — bypass ON short-circuits policies entirely: super_admin passes an ownership rule it fails on merit', function () {
    config(['auth.gate_before_superadmin' => true]);
    $sa = superAdmin();
    $export = Export::factory()->create(); // owned by someone else

    expect(Gate::forUser($sa)->allows('download', $export))->toBeTrue();
});

it('row 3 — bypass OFF lets the policy run: the ownership rule denies super_admin', function () {
    config(['auth.gate_before_superadmin' => false]);
    $sa = superAdmin();
    $export = Export::factory()->create();

    expect(Gate::forUser($sa)->allows('download', $export))->toBeFalse();
});

// ── Row 4: the FormRequest path ────────────────────────────────────────────
//
// C3 RESOLVED THE LOCKOUT THIS ROW FOUND. The probe recorded these requests
// denying super_admin in BOTH flag states, because hasRole() never consults the
// Gate. ADR 0044 step 2 replaced hasRole with can(), so the path now reaches
// the Gate like every other — and the cell becomes flag-dependent, which is the
// same load-bearing dependency C2 introduced for routes.

it('row 4 — a non-checker FormRequest now reaches the Gate: bypass ON passes super_admin (the probe lockout, resolved)', function () {
    $sa = superAdmin();
    $school = School::factory()->create();
    setPermissionsTeamId($school->id);

    $request = new PromoteStudentRequest;
    $request->setUserResolver(fn () => $sa);

    config(['auth.gate_before_superadmin' => true]);
    expect($request->authorize())->toBeTrue();

    // Flag off, the seeded absence decides — super_admin holds no enrollment
    // permission, so it is denied. Not a lockout bug: the same declared
    // flag dependency the C2 swap made load-bearing.
    config(['auth.gate_before_superadmin' => false]);
    expect($request->authorize())->toBeFalse();
});

it('row 4 — a CHECKER FormRequest denies super_admin in BOTH flag states (ADR 0040, and now by design)', function () {
    $sa = superAdmin();
    $school = School::factory()->create();
    setPermissionsTeamId($school->id);

    $request = new RejectSubjectResultRequest;
    $request->setUserResolver(fn () => $sa);

    // Same denial the probe observed, but for a different and now intended
    // reason: result.reject is excluded from the bypass, so platform authority
    // cannot reach a checker action regardless of the flag.
    config(['auth.gate_before_superadmin' => true]);
    expect($request->authorize())->toBeFalse();

    config(['auth.gate_before_superadmin' => false]);
    expect($request->authorize())->toBeFalse();
});

// ── Control rows: the instrument can say "denied" ──────────────────────────

it('control — a teacher is denied result.approve on every path, and allowed result.submit via its seeded row', function () {
    config(['auth.gate_before_superadmin' => true]);

    $school = School::factory()->create();
    setPermissionsTeamId($school->id);
    $teacher = User::factory()->create();
    $teacher->assignRole('teacher');

    expect($teacher->can('result.approve'))->toBeFalse() // instrument bites
        ->and($teacher->can('result.submit'))->toBeTrue(); // Spatie's own before, null-on-miss composition
});
