<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

/**
 * D1 — the approvals PAGE route gates VISIBILITY (who may open the queue), which is a different
 * question from AUTHORITY (who may approve a given ROW). Ph3b answered the second per-row and let
 * the first inherit `finance.credit-note.approve`, so a void-only checker got a full-page 403 and
 * the per-feed 403-tolerance never ran in a browser.
 *
 * These assertions live at the PAGE ROUTE — the layer the API acceptance test was blind to. The
 * gate now admits any holder of a finance CHECKER ability (derived from the ApprovalAbility
 * convention), so both one-sided checkers reach the page; a user with neither still 403s.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => (new RbacSeeder)->run());

/**
 * A web-session user in $school holding EXACTLY $permissions (via a dedicated role), ready for
 * actingAs + withSession(school_id).
 *
 * @param  list<string>  $permissions
 */
function pageGateUser(School $school, array $permissions): User
{
    $roleName = 'pgtest_'.substr(md5(implode(',', $permissions)), 0, 10);
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

function getApprovalsPage(User $user, School $school)
{
    return test()->actingAs($user)->withSession(['school_id' => $school->id])->get('/finance/approvals');
}

it('D1 — a VOID-ONLY checker (void approve, no credit-note approve) reaches the approvals page (200)', function () {
    $school = School::factory()->create();
    $user = pageGateUser($school, ['finance.access', 'finance.invoice.void-request.approve', 'finance.invoice.void-request.reject']);

    getApprovalsPage($user, $school)->assertOk();
});

it('D1 mirror — a CREDIT-ONLY checker (credit-note approve, no void approve) reaches the approvals page (200)', function () {
    $school = School::factory()->create();
    $user = pageGateUser($school, ['finance.access', 'finance.credit-note.approve', 'finance.credit-note.reject']);

    getApprovalsPage($user, $school)->assertOk();
});

it('a user holding NEITHER checker ability (finance.access only) is refused the approvals page (403)', function () {
    $school = School::factory()->create();
    $user = pageGateUser($school, ['finance.access']);

    getApprovalsPage($user, $school)->assertForbidden();
});

it('the full checker (accounts_supervisor, both sides) reaches the page (200)', function () {
    $school = School::factory()->create();
    $user = User::factory()->create(['school_id' => $school->id]);
    $user->grantSchoolAccess($school, 'accounts_supervisor');
    $user->flushSchoolAccessCache();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    getApprovalsPage($user, $school)->assertOk();
});

it('super_admin stays EXCLUDED from the approvals page — checker abilities are never bypassed (ADR 0040/0045)', function () {
    // The decision (argued against Decision 1): a super_admin is deliberately off every checker
    // surface. The derived gate carries the same exclusion because each listed ability is a
    // checker action — so super_admin holds none and the bypass does not apply.
    config(['auth.gate_before_superadmin' => true]);
    $school = School::factory()->create();

    setPermissionsTeamId(null);
    $super = User::factory()->create(['school_id' => $school->id]);
    $super->assignRole('super_admin');
    $super->flushSchoolAccessCache();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    getApprovalsPage($super, $school)->assertForbidden();
});
