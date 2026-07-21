<?php

use App\Models\Arm;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\ClassLevelArmTeacher;
use App\Models\School;
use App\Models\Teacher;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    (new RbacSeeder)->run();
});

// -------------------------------------------------------------------------
// Fixture helpers
// -------------------------------------------------------------------------

function tms_admin(School $school): User
{
    $user = al_makeUser($school->id);
    setPermissionsTeamId($school->id);
    $user->assignRole('admin');
    setPermissionsTeamId(null);
    $user->unsetRelation('roles');

    return $user;
}

function tms_teacher(School $school, bool $withUser = true): Teacher
{
    $user = null;

    if ($withUser) {
        $user = al_makeUser($school->id);
        setPermissionsTeamId($school->id);
        $user->assignRole('teacher');
        setPermissionsTeamId(null);
        $user->unsetRelation('roles');
    }

    return Teacher::create([
        'school_id' => $school->id,
        'user_id' => $user?->id,
        'first_name' => 'Teach',
        'last_name' => Str::random(6),
        'staff_number' => 'STF-' . Str::random(8),
    ]);
}

function tms_classLevelArm(School $school): ClassLevelArm
{
    $classLevel = ClassLevel::create([
        'school_id' => $school->id,
        'name' => 'JSS1-' . Str::random(4),
        'order' => 1,
    ]);

    $arm = Arm::create([
        'school_id' => $school->id,
        'label' => 'Gold-' . Str::random(4),
    ]);

    return ClassLevelArm::forceCreate([
        'school_id' => $school->id,
        'class_level_id' => $classLevel->id,
        'arm_id' => $arm->id,
    ]);
}

// -------------------------------------------------------------------------
// Sync endpoint
// -------------------------------------------------------------------------

it('lets an admin grant a teacher access to another school they manage', function () {
    $a = al_makeSchool();
    $b = al_makeSchool();
    $admin = tms_admin($a);
    $admin->grantSchoolAccess($b);
    $teacher = tms_teacher($a);

    $this->actingAs($admin)
        ->putJson("/api/teachers/{$teacher->uuid}/schools", ['schools' => [$b->uuid]])
        ->assertOk();

    $user = $teacher->user->fresh();
    expect($user->canAccessSchool($b->id))->toBeTrue();

    setPermissionsTeamId($b->id);
    $user->unsetRelation('roles');
    expect($user->hasRole('teacher'))->toBeTrue();
    setPermissionsTeamId(null);
});

it('rejects granting access to a school the admin cannot access', function () {
    $a = al_makeSchool();
    $c = al_makeSchool();
    $admin = tms_admin($a);
    $teacher = tms_teacher($a);

    $this->actingAs($admin)
        ->putJson("/api/teachers/{$teacher->uuid}/schools", ['schools' => [$c->uuid]])
        ->assertForbidden();

    expect($teacher->user->fresh()->canAccessSchool($c->id))->toBeFalse();
});

it('rejects managing school access for a teacher without a login account', function () {
    $a = al_makeSchool();
    $b = al_makeSchool();
    $admin = tms_admin($a);
    $admin->grantSchoolAccess($b);
    $teacher = tms_teacher($a, withUser: false);

    $this->actingAs($admin)
        ->putJson("/api/teachers/{$teacher->uuid}/schools", ['schools' => [$b->uuid]])
        ->assertStatus(422);
});

it('never revokes the home school and preserves grants outside the acting admin reach', function () {
    $a = al_makeSchool();
    $b = al_makeSchool();
    $c = al_makeSchool();
    $admin = tms_admin($a);
    $admin->grantSchoolAccess($b);

    $teacher = tms_teacher($a);
    $teacher->user->grantSchoolAccess($b, 'teacher');
    // Grant made by an admin of $c, whom the acting admin cannot see.
    $teacher->user->grantSchoolAccess($c, 'teacher');

    $this->actingAs($admin)
        ->putJson("/api/teachers/{$teacher->uuid}/schools", ['schools' => []])
        ->assertOk();

    $user = $teacher->user->fresh();
    expect($user->canAccessSchool($a->id))->toBeTrue()   // home fallback
        ->and($user->canAccessSchool($b->id))->toBeFalse() // revoked
        ->and($user->canAccessSchool($c->id))->toBeTrue(); // preserved

    setPermissionsTeamId($b->id);
    $user->unsetRelation('roles');
    expect($user->hasRole('teacher'))->toBeFalse();
    setPermissionsTeamId(null);
});

// -------------------------------------------------------------------------
// Teachers listing (widened SchoolScope)
// -------------------------------------------------------------------------

it('lists teachers with pivot access to the active school and hides them after revoke', function () {
    $a = al_makeSchool();
    $b = al_makeSchool();
    $adminB = tms_admin($b);

    $teacher = tms_teacher($a);
    $teacher->user->grantSchoolAccess($b, 'teacher');

    $this->actingAs($adminB)
        ->getJson('/api/teachers')
        ->assertOk()
        ->assertJsonFragment(['id' => $teacher->uuid]);

    $teacher->user->revokeSchoolAccess($b, 'teacher');

    $this->actingAs($adminB)
        ->getJson('/api/teachers')
        ->assertOk()
        ->assertJsonMissing(['id' => $teacher->uuid]);
});

// -------------------------------------------------------------------------
// Editing / deleting from a pivot school
// -------------------------------------------------------------------------

it('does not re-home a teacher edited from a pivot school', function () {
    $a = al_makeSchool();
    $b = al_makeSchool();
    $adminB = tms_admin($b);

    $teacher = tms_teacher($a);
    $teacher->user->grantSchoolAccess($b, 'teacher');

    $this->actingAs($adminB)
        ->patchJson("/api/teachers/{$teacher->uuid}", [
            'first_name' => 'Renamed',
            'last_name' => $teacher->last_name,
        ])
        ->assertOk();

    $fresh = Teacher::withoutGlobalScopes()->find($teacher->id);
    expect($fresh->first_name)->toBe('Renamed')
        ->and((int) $fresh->school_id)->toBe((int) $a->id);
});

it('blocks deleting a teacher from a pivot school but allows it from home', function () {
    $a = al_makeSchool();
    $b = al_makeSchool();
    $adminA = tms_admin($a);
    $adminB = tms_admin($b);

    $teacher = tms_teacher($a);
    $teacher->user->grantSchoolAccess($b, 'teacher');

    $this->actingAs($adminB)
        ->deleteJson("/api/teachers/{$teacher->uuid}")
        ->assertForbidden();

    $this->actingAs($adminA)
        ->deleteJson("/api/teachers/{$teacher->uuid}")
        ->assertNoContent();
});

// -------------------------------------------------------------------------
// Login / switching
// -------------------------------------------------------------------------

it('sends a multi-school teacher to the school selection page after login', function () {
    $a = al_makeSchool();
    $b = al_makeSchool();

    $teacher = tms_teacher($a);
    $teacher->user->grantSchoolAccess($b, 'teacher');

    $this->post('/login', [
        'email' => $teacher->user->email,
        'password' => 'password',
    ])->assertRedirect(route('school.select'));
});

it('lets a multi-school teacher switch into a pivot school and reach their profile', function () {
    $a = al_makeSchool();
    $b = al_makeSchool();

    $teacher = tms_teacher($a);
    $teacher->user->grantSchoolAccess($b, 'teacher');

    $this->actingAs($teacher->user)
        ->post('/select-school', ['school' => $b->uuid])
        ->assertRedirect();

    expect(session('school_id'))->toEqual($b->id);

    // The dashboard resolves the teacher record through the widened scope
    // and redirects to their profile page.
    $this->get('/dashboard')
        ->assertRedirect('/setup/teacher/' . $teacher->uuid);
});

it('logs a single-school teacher straight in via their school_id fallback', function () {
    $a = al_makeSchool();
    $teacher = tms_teacher($a);

    $this->post('/login', [
        'email' => $teacher->user->email,
        'password' => 'password',
    ])->assertRedirect(config('fortify.home', '/dashboard'));

    expect(session('school_id'))->toEqual($a->id);
});

it('requires school selection on api login for a multi-school teacher', function () {
    $a = al_makeSchool();
    $b = al_makeSchool();

    $teacher = tms_teacher($a);
    $teacher->user->grantSchoolAccess($b, 'teacher');

    $this->postJson('/api/login', [
        'email' => $teacher->user->email,
        'password' => 'password',
    ])
        ->assertStatus(409)
        ->assertJsonPath('requires_school_selection', true)
        ->assertJsonCount(2, 'schools');

    $this->postJson('/api/login', [
        'email' => $teacher->user->email,
        'password' => 'password',
        'school_uuid' => $b->uuid,
    ])
        ->assertOk()
        ->assertJsonPath('school.uuid', $b->uuid);
});

// -------------------------------------------------------------------------
// Assignment isolation between schools
// -------------------------------------------------------------------------

it('does not leak home-school assignments into a pivot school assignment list', function () {
    $a = al_makeSchool();
    $b = al_makeSchool();
    $adminB = tms_admin($b);

    $teacher = tms_teacher($a);
    $teacher->user->grantSchoolAccess($b, 'teacher');

    $armA = tms_classLevelArm($a);
    ClassLevelArmTeacher::create([
        'class_level_arm_id' => $armA->id,
        'teacher_id' => $teacher->id,
        'role' => 'form_teacher',
    ]);

    $this->actingAs($adminB)
        ->getJson('/api/teacher-assignments')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});
