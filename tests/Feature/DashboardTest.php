<?php

use App\Models\Role;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
