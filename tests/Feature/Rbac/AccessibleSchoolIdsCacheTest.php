<?php

use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * §24: accessibleSchoolIds() must be cached (no per-request re-derivation).
 * Proven by query count, not by reading the code.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Role::firstOrCreate(['name' => 'teacher', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
});

it('re-derives accessibleSchoolIds with queries when the memo is cold', function () {
    $school = al_makeSchool();
    $user = al_makeUser($school->id);

    $user->flushSchoolAccessCache();
    DB::enableQueryLog();
    $user->accessibleSchoolIds();
    $cold = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Cold derivation touches the DB (isSuperAdmin role check + pivot etc.).
    expect($cold)->toBeGreaterThan(0);
});

it('serves repeated accessibleSchoolIds / canAccessSchool from the memo (zero queries)', function () {
    $school = al_makeSchool();
    $user = al_makeUser($school->id);
    setPermissionsTeamId($school->id);
    $user->assignRole('teacher');
    $user->flushSchoolAccessCache();

    // Warm the memo (isSuperAdmin + accessibleSchoolIds).
    $user->accessibleSchoolIds();

    DB::enableQueryLog();
    $user->accessibleSchoolIds();
    $user->accessibleSchoolIds();
    $user->canAccessSchool($school->id);
    $warm = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($warm)->toBe(0);
});

it('invalidates the memo when access changes (grantSchoolAccess)', function () {
    $a = al_makeSchool();
    $b = al_makeSchool();
    $user = al_makeUser($a->id);

    expect($user->canAccessSchool($b->id))->toBeFalse(); // warms memo without B

    $user->grantSchoolAccess($b, 'teacher'); // flushes the memo

    expect($user->canAccessSchool($b->id))->toBeTrue(); // recomputed, sees B
});
