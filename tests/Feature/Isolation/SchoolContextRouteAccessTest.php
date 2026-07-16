<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/**
 * Route-level proof of the super-admin isolation boundary (companion to the
 * model-level SchoolScopeFailsClosedTest).
 *
 * The rule under test: the team-less super_admin bypasses *authorization*
 * (EnsureRole, Gate::before) but NOT *School isolation*. So with fail-closed
 * scoping enabled:
 *   - PLATFORM routes (that touch no School-owned model) stay globally
 *     reachable without an active School — this is what makes platform support
 *     work (e.g. picking a School).
 *   - SCHOOL-SCOPED routes (that read a BelongsToSchool model) require an
 *     active School even for a super admin — 409, never a silent unscoped read.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    config(['rbac.scope_fail_closed' => true]);
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
});

function routeSuperAdminWithoutSchool(): User
{
    // A super admin is team-less and holds no School: users.school_id is null,
    // so ActiveSchool::id() cannot fall back to it and there is no context.
    $super = User::forceCreate([
        'uuid' => (string) Str::uuid(),
        'first_name' => 'Super',
        'last_name' => 'Admin',
        'email' => Str::uuid().'@example.test',
        'password' => bcrypt('password'),
        'school_id' => null,
    ]);

    setPermissionsTeamId(null);
    $super->assignRole('super_admin');

    return $super;
}

it('keeps a platform route globally reachable for a super admin with no active School', function () {
    // GET /api/user returns the authenticated identity — it touches no
    // School-owned model, so it must stay reachable without a School context.
    $this->actingAs(routeSuperAdminWithoutSchool())
        ->withSession([]) // no school_id: no active School
        ->getJson('/api/user')
        ->assertOk();
});

it('blocks a School-scoped route for a super admin with no active School', function () {
    // GET /api/notices lists Notices (a BelongsToSchool model). The super admin
    // clears EnsureRole (authorization) but the fail-closed scope still demands
    // an active School — so this is a clean 409, not an unscoped read.
    $this->actingAs(routeSuperAdminWithoutSchool())
        ->withSession([]) // no school_id: no active School
        ->getJson('/api/notices')
        ->assertStatus(409)
        ->assertJson(['message' => 'No active school selected.']);
});

it('allows the same School-scoped route once an active School is present', function () {
    // Positive control: the route itself works — the 409 above is specifically
    // about missing context, not a broken endpoint.
    $school = al_makeSchool();
    setPermissionsTeamId($school->id);
    $admin = al_makeUser($school->id);
    $admin->assignRole('admin');

    $this->actingAs($admin)
        ->withSession(['school_id' => $school->id])
        ->getJson('/api/notices')
        ->assertOk();
});
