<?php

use App\Models\Role;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach (['teacher', 'guardian', 'super_admin'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
});

/** @return array{0: list<int>, 1: list<int>} [legacy, roleBased] */
function bothAccessSources(User $user): array
{
    config(['rbac.single_source_access' => false]);
    $legacy = $user->accessibleSchoolIds()->sort()->values()->all();

    config(['rbac.single_source_access' => true]);
    $roleBased = $user->accessibleSchoolIds()->sort()->values()->all();

    return [$legacy, $roleBased];
}

it('role-based access equals legacy for a single-school staff user', function () {
    $school = al_makeSchool();
    $user = al_makeUser($school->id);
    setPermissionsTeamId($school->id);
    $user->assignRole('teacher');

    [$legacy, $roleBased] = bothAccessSources($user);

    expect($roleBased)->toEqual($legacy)
        ->and($roleBased)->toEqual([(int) $school->id]);
});

it('role-based access equals legacy for a multi-school user granted via roles + pivot', function () {
    $a = al_makeSchool();
    $b = al_makeSchool();
    $user = al_makeUser($a->id);

    // The app grants access by assigning a role per team AND the school_user pivot.
    setPermissionsTeamId($a->id);
    $user->assignRole('teacher');
    setPermissionsTeamId($b->id);
    $user->assignRole('teacher');
    $user->schools()->syncWithoutDetaching([$a->id, $b->id]);

    [$legacy, $roleBased] = bothAccessSources($user);

    expect($roleBased)->toEqual($legacy)
        ->and($roleBased)->toEqual(collect([$a->id, $b->id])->map(fn ($id) => (int) $id)->sort()->values()->all());
});

it('super admin resolves to every school under both sources', function () {
    $a = al_makeSchool();
    $b = al_makeSchool();
    $user = al_makeUser($a->id);
    setPermissionsTeamId(null);
    $user->assignRole('super_admin');

    $all = School::query()->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();

    [$legacy, $roleBased] = bothAccessSources($user);
    expect($legacy)->toEqual($all)->and($roleBased)->toEqual($all);
});
