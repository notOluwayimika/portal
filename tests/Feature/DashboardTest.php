<?php

use App\Models\Role;
use App\Models\Teacher;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// C2 (role:->permission: swap): routes now authorize by GRANTS, not role
// names, so the locally-fabricated roles need the canonical grant map to
// reach the code under test.
beforeEach(fn () => (new RbacSeeder)->run());

beforeEach(function () {
    foreach (['admin', 'head_of_school', 'teacher', 'guardian'] as $role) {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    }
});

test('guests are redirected to the login page', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('authenticated admin can visit the dashboard', function () {
    $school = al_makeSchool();
    $user = al_makeUser($school->id);
    setPermissionsTeamId($school->id);
    $user->assignRole('admin');

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk();
});

test('an admin who is also a teacher reaches the dashboard, not the teacher redirect', function () {
    $school = al_makeSchool();
    $user = al_makeUser($school->id);
    setPermissionsTeamId($school->id);
    $user->assignRole('admin');
    $user->assignRole('teacher');
    Teacher::create([
        'school_id' => $school->id,
        'user_id' => $user->id,
        'first_name' => 'Both',
        'last_name' => Str::random(6),
        'staff_number' => 'STF-'.Str::random(6),
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk();
});

test('a teacher-only user is redirected to their teacher page', function () {
    $school = al_makeSchool();
    $user = al_makeUser($school->id);
    setPermissionsTeamId($school->id);
    $user->assignRole('teacher');
    $teacher = Teacher::create([
        'school_id' => $school->id,
        'user_id' => $user->id,
        'first_name' => 'Teach',
        'last_name' => Str::random(6),
        'staff_number' => 'STF-'.Str::random(6),
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect('/setup/teacher/'.$teacher->uuid);
});
