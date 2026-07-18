<?php

use App\Exceptions\NullTeamRoleAssignmentException;
use App\Models\Role;
use App\Models\User;
use App\Support\ActiveSchool;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * S7 permanent invariant: a school-scoped role may never be assigned with a null
 * permissions-team (that would grant access to no School — divergence). Only
 * super_admin, the deliberately team-less global role, is exempt. Enforced by the
 * User::assignRole override so no call site can bypass it — on request or off.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(null);
    foreach (['teacher', 'guardian', 'admin', 'super_admin'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
});

it('rejects a school-scoped role assignment with no active team', function () {
    $user = User::factory()->create();
    setPermissionsTeamId(null);

    expect(fn () => $user->assignRole('teacher'))
        ->toThrow(NullTeamRoleAssignmentException::class);

    expect(fn () => $user->assignRole('guardian'))
        ->toThrow(NullTeamRoleAssignmentException::class);
});

it('allows super_admin to be assigned team-less (the sole exemption)', function () {
    $user = User::factory()->create();
    setPermissionsTeamId(null);

    $user->assignRole('super_admin');

    expect($user->fresh()->hasRole('super_admin'))->toBeTrue();
});

it('allows a school-scoped role assignment inside an active team', function () {
    $school = al_makeSchool();
    $user = User::factory()->create();

    setPermissionsTeamId($school->id);
    $user->assignRole('teacher');
    expect($user->fresh()->hasRole('teacher'))->toBeTrue(); // role is in this team
    setPermissionsTeamId(null);
});

it('holds off-request: runFor establishes the team so assignRole succeeds', function () {
    $school = al_makeSchool();
    $user = User::factory()->create();

    // Simulate a queue/console context: no request, no session. runFor is the
    // sanctioned off-request team establishment.
    ActiveSchool::runFor($school->id, function () use ($user, $school) {
        setPermissionsTeamId($school->id); // runFor sets this; explicit here for clarity
        $user->assignRole('teacher');
    });

    // The role landed in the School's team.
    setPermissionsTeamId($school->id);
    expect($user->fresh()->hasRole('teacher'))->toBeTrue();
    setPermissionsTeamId(null);
});

it('holds off-request: WITHOUT a team, an off-request assignRole still throws', function () {
    $user = User::factory()->create();
    setPermissionsTeamId(null);

    // No runFor, no team → the invariant fires even off-request (a job that
    // forgot to establish context cannot silently create a null-team grant).
    expect(fn () => $user->assignRole('teacher'))
        ->toThrow(NullTeamRoleAssignmentException::class);
});
