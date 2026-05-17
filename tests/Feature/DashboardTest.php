<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

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
