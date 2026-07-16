<?php

use App\Exceptions\MissingSchoolContextException;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

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

it('does not fail closed for a super admin without an active School', function () {
    config(['rbac.scope_fail_closed' => true]);
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $super = userWithoutSchool();
    $super->assignRole('super_admin');

    $this->actingAs($super);

    expect(fn () => Student::count())->not->toThrow(MissingSchoolContextException::class);
});

it('does not throw when an active School IS set, even with the flag on', function () {
    config(['rbac.scope_fail_closed' => true]);
    $school = al_makeSchool();
    $this->actingAs(al_makeUser($school->id)); // users.school_id provides context

    expect(fn () => Student::count())->not->toThrow(MissingSchoolContextException::class);
});
