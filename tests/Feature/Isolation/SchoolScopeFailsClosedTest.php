<?php

use App\Exceptions\MissingSchoolContextException;
use App\Models\Role;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

function superAdminWithoutSchool(): User
{
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $super = userWithoutSchool();
    $super->assignRole('super_admin');

    return $super;
}

uses(RefreshDatabase::class);

function userWithoutSchool(): User
{
    return User::forceCreate([
        'uuid' => (string) Str::uuid(),
        'first_name' => 'No',
        'last_name' => 'School',
        'email' => Str::uuid().'@example.test',
        'password' => bcrypt('password'),
        'school_id' => null,
    ]);
}

it('stays fail-open (no throw) when the flag is off — the default', function () {
    config(['rbac.scope_fail_closed' => false]);
    $this->actingAs(userWithoutSchool());

    expect(fn () => Student::count())->not->toThrow(MissingSchoolContextException::class);
});

it('throws when the flag is on and there is no active School context', function () {
    config(['rbac.scope_fail_closed' => true]);
    $this->actingAs(userWithoutSchool());

    expect(fn () => Student::count())->toThrow(MissingSchoolContextException::class);
});

it('requires School context for School-owned data even for a super admin (isolation, not authority)', function () {
    config(['rbac.scope_fail_closed' => true]);
    $this->actingAs(superAdminWithoutSchool());

    // A super admin bypasses authorization (Gate::before) but NOT isolation:
    // School-owned data still needs an active School.
    expect(fn () => Student::count())->toThrow(MissingSchoolContextException::class);
});

it('keeps platform models globally reachable without School context (super admin)', function () {
    config(['rbac.scope_fail_closed' => true]);
    $this->actingAs(superAdminWithoutSchool());

    // Platform models are not School-scoped (BelongsToSchool), so the scope never
    // applies and they remain globally accessible for platform operations.
    expect(fn () => User::count())->not->toThrow(MissingSchoolContextException::class)
        ->and(fn () => School::count())->not->toThrow(MissingSchoolContextException::class);
});

it('does not throw when an active School IS set, even with the flag on', function () {
    config(['rbac.scope_fail_closed' => true]);
    $school = al_makeSchool();
    $this->actingAs(al_makeUser($school->id)); // users.school_id provides context

    expect(fn () => Student::count())->not->toThrow(MissingSchoolContextException::class);
});
