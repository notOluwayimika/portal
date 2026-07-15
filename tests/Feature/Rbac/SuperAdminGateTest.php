<?php

use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
});

function makeSuperAdmin($school)
{
    $user = al_makeUser($school->id);
    // super_admin is team-less: assign it in a null-team context.
    setPermissionsTeamId(null);
    $user->assignRole('super_admin');

    return $user;
}

it('lets a super admin pass a permission check inside a school context', function () {
    $school = al_makeSchool();
    $super = makeSuperAdmin($school);

    // Act inside a school. super_admin holds no granted permissions, so only the
    // Gate::before bypass can make this pass — including for an ability it was
    // never explicitly granted.
    setPermissionsTeamId($school->id);

    expect($super->can('guardian.update'))->toBeTrue()
        ->and($super->can('activity_log.export'))->toBeTrue();
});

it('removes the bypass when the flag is disabled', function () {
    config(['auth.gate_before_superadmin' => false]);

    $school = al_makeSchool();
    $super = makeSuperAdmin($school);
    setPermissionsTeamId($school->id);

    expect($super->can('guardian.update'))->toBeFalse();
});

it('does not grant abilities to a non-super-admin via the bypass', function () {
    $school = al_makeSchool();
    $user = al_makeUser($school->id);
    setPermissionsTeamId($school->id);

    expect($user->can('guardian.update'))->toBeFalse();
});
