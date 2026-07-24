<?php

use App\Models\Role;
use App\Models\Scopes\SchoolScope;
use App\Models\Teacher;
use App\Models\User;
use App\Notifications\TeacherAccountCreatedNotification;
use App\Services\TeacherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'teacher', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
});

function al_importRow(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Imported',
        'last_name' => 'Teacher',
        'email' => 'imported.teacher@example.test',
    ], $overrides);
}

function al_teacherFor(User $user): ?Teacher
{
    return Teacher::withoutGlobalScope(SchoolScope::class)->where('user_id', $user->id)->first();
}

it('creates a user and teacher record for an unknown email', function () {
    $school = al_makeSchool();

    $result = app(TeacherService::class)->import([al_importRow()], $school->id);

    expect($result)->toMatchArray(['saved' => 1, 'linked' => 0, 'skipped' => 0, 'errors' => []]);

    $user = User::where('email', 'imported.teacher@example.test')->firstOrFail();
    expect((int) $user->school_id)->toBe((int) $school->id)
        ->and((int) al_teacherFor($user)->school_id)->toBe((int) $school->id);

    Notification::assertSentTo($user, TeacherAccountCreatedNotification::class);
});

it('makes an admin of another school a teacher here without touching their home school', function () {
    $schoolA = al_makeSchool();
    $schoolB = al_makeSchool();

    $admin = al_makeUser($schoolA->id);
    $admin->grantSchoolAccess($schoolA, 'admin');

    $result = app(TeacherService::class)->import(
        [al_importRow(['email' => $admin->email, 'staff_number' => 'STF-1'])],
        $schoolB->id,
    );

    expect($result)->toMatchArray(['saved' => 0, 'linked' => 1, 'skipped' => 0, 'errors' => []])
        ->and(User::where('email', $admin->email)->count())->toBe(1);

    $admin->refresh();

    // Home school untouched; teacher record and access created in School B.
    expect((int) $admin->school_id)->toBe((int) $schoolA->id)
        ->and((int) al_teacherFor($admin)->school_id)->toBe((int) $schoolB->id)
        ->and($admin->accessibleSchoolIds()->all())
        ->toEqualCanonicalizing([(int) $schoolA->id, (int) $schoolB->id]);

    setPermissionsTeamId($schoolB->id);
    $admin->unsetRelation('roles');
    expect($admin->hasRole('teacher'))->toBeTrue();

    setPermissionsTeamId($schoolA->id);
    $admin->unsetRelation('roles');
    expect($admin->hasRole('admin'))->toBeTrue()
        ->and($admin->hasRole('teacher'))->toBeFalse();
});

it('grants access without a second teacher record when the user teaches another school', function () {
    $schoolA = al_makeSchool();
    $schoolB = al_makeSchool();

    $user = al_makeUser($schoolA->id);
    $user->grantSchoolAccess($schoolA, 'teacher');
    Teacher::withoutGlobalScopes()->create([
        'school_id' => $schoolA->id,
        'user_id' => $user->id,
        'first_name' => 'Home',
        'last_name' => 'Teacher',
        'staff_number' => 'HOME-1',
        'status' => 'active',
    ]);

    // The CSV's per-teacher columns are deliberately ignored for this case.
    $result = app(TeacherService::class)->import(
        [al_importRow(['email' => $user->email, 'staff_number' => 'IGNORED-1', 'qualification' => 'Ignored'])],
        $schoolB->id,
    );

    expect($result)->toMatchArray(['saved' => 0, 'linked' => 1, 'skipped' => 0, 'errors' => []])
        ->and(Teacher::withoutGlobalScope(SchoolScope::class)->where('user_id', $user->id)->count())->toBe(1);

    $teacher = al_teacherFor($user);
    expect((int) $teacher->school_id)->toBe((int) $schoolA->id)
        ->and($teacher->staff_number)->toBe('HOME-1')
        ->and($teacher->qualification)->toBeNull()
        ->and(DB::table('school_user')->where('user_id', $user->id)->where('school_id', $schoolB->id)->exists())
        ->toBeTrue();
});

it('skips a user who already teaches this school', function () {
    $school = al_makeSchool();

    $user = al_makeUser($school->id);
    $user->grantSchoolAccess($school, 'teacher');
    Teacher::withoutGlobalScopes()->create([
        'school_id' => $school->id,
        'user_id' => $user->id,
        'first_name' => 'Existing',
        'last_name' => 'Teacher',
        'staff_number' => 'EX-1',
        'status' => 'active',
    ]);

    $result = app(TeacherService::class)->import([al_importRow(['email' => $user->email])], $school->id);

    expect($result)->toMatchArray(['saved' => 0, 'linked' => 0, 'skipped' => 1, 'errors' => []])
        ->and(Teacher::withoutGlobalScope(SchoolScope::class)->where('user_id', $user->id)->count())->toBe(1);

    Notification::assertNothingSent();
});

it('still rejects a staff number already used in this school', function () {
    $school = al_makeSchool();

    $existing = al_makeUser($school->id);
    Teacher::withoutGlobalScopes()->create([
        'school_id' => $school->id,
        'user_id' => $existing->id,
        'first_name' => 'Existing',
        'last_name' => 'Teacher',
        'staff_number' => 'DUP-1',
        'status' => 'active',
    ]);

    $result = app(TeacherService::class)->import([al_importRow(['staff_number' => 'DUP-1'])], $school->id);

    expect($result['saved'])->toBe(0)
        ->and($result['errors'][0][0])->toContain("Staff number 'DUP-1' already exists.");
});
