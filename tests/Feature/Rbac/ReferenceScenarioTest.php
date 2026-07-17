<?php

use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * §24 reference scenario (§6.5 single login with School switching; §7.2 per-School
 * roles): one user holds a different role per School and cannot enter a School
 * where they hold none — asserted at the model AND the API layer.
 *
 * NOTE: the spec names the IFY role "Coordinator", which is not a currently
 * defined role (defined: admin, teacher, head_of_school, principal, form_teacher,
 * registrar, guardian, super_admin). This test uses `head_of_school` as the
 * distinct second role to prove the mechanism; substituting a real `coordinator`
 * role is a product decision (reported to the owner), not an isolation change.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    foreach (['teacher', 'head_of_school'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
});

function referenceUser(): array
{
    $secondary = al_makeSchool();
    $ifyAbuja = al_makeSchool();
    $primary = al_makeSchool();

    $user = al_makeUser($secondary->id);
    // grantSchoolAccess syncs the pivot AND assigns the per-team role, so the
    // scenario holds under both the legacy union and the single-source path.
    $user->grantSchoolAccess($secondary, 'teacher');
    $user->grantSchoolAccess($ifyAbuja, 'head_of_school');
    // Primary: no grant, no role.

    return compact('user', 'secondary', 'ifyAbuja', 'primary');
}

it('grants per-School access from roles: Secondary + IFY Abuja, never Primary', function () {
    ['user' => $user, 'secondary' => $secondary, 'ifyAbuja' => $ifyAbuja, 'primary' => $primary] = referenceUser();

    foreach ([false, true] as $singleSource) {
        config(['rbac.single_source_access' => $singleSource]);
        $user->flushSchoolAccessCache();

        expect($user->canAccessSchool($secondary->id))->toBeTrue()
            ->and($user->canAccessSchool($ifyAbuja->id))->toBeTrue()
            ->and($user->canAccessSchool($primary->id))->toBeFalse();
    }
});

it('resolves a different role per School and none in Primary', function () {
    ['user' => $user, 'secondary' => $secondary, 'ifyAbuja' => $ifyAbuja, 'primary' => $primary] = referenceUser();

    setPermissionsTeamId($secondary->id);
    $user->unsetRelation('roles');
    expect($user->hasRole('teacher'))->toBeTrue()
        ->and($user->hasRole('head_of_school'))->toBeFalse();

    setPermissionsTeamId($ifyAbuja->id);
    $user->unsetRelation('roles');
    expect($user->hasRole('head_of_school'))->toBeTrue()
        ->and($user->hasRole('teacher'))->toBeFalse();

    setPermissionsTeamId($primary->id);
    $user->unsetRelation('roles');
    expect($user->hasRole('teacher'))->toBeFalse()
        ->and($user->hasRole('head_of_school'))->toBeFalse();

    setPermissionsTeamId(null);
});

it('enforces the scenario at the API layer: switch into an accessible School, reject Primary', function () {
    ['user' => $user, 'secondary' => $secondary, 'primary' => $primary] = referenceUser();

    // Can switch into a School they hold a role in.
    $this->actingAs($user)
        ->postJson('/api/switch-school', ['school_uuid' => $secondary->uuid])
        ->assertOk();

    // Cannot switch into Primary (no role) — rejected at the API.
    $this->actingAs($user)
        ->postJson('/api/switch-school', ['school_uuid' => $primary->uuid])
        ->assertStatus(403);
});
