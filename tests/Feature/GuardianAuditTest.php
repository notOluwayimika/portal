<?php

use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function ga_makeAdmin(int $schoolId): \App\Models\User
{
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

    $admin = al_makeUser($schoolId);

    setPermissionsTeamId($schoolId);
    $admin->assignRole('admin');
    setPermissionsTeamId(null);
    $admin->unsetRelation('roles');

    return $admin;
}

it('includes linked user actions (logins, password changes) in the guardian audit history', function () {
    $school = al_makeSchool();
    $admin = ga_makeAdmin($school->id);

    $guardianUser = al_makeUser($school->id);
    $guardian = al_makeGuardian($school->id, $guardianUser->id);

    // 1. activity on the guardian record itself
    activity('guardian')->performedOn($guardian)->event('updated')->log('Guardian updated');
    // 2. action performed BY the linked account (login)
    activity('auth')->causedBy($guardianUser)->event('login')->log('Guardian logged in successfully');
    // 3. action ON the linked account (password changed by an admin)
    activity()->performedOn($guardianUser)->causedBy($admin)->event('password_changed')->log('Password changed');

    $response = $this->actingAs($admin)
        ->withSession(['school_id' => $school->id])
        ->getJson("/api/guardians/{$guardian->uuid}/audit")
        ->assertOk();

    $events = collect($response->json('data'))->pluck('event');

    expect($events)->toContain('updated')
        ->toContain('login')
        ->toContain('password_changed');
});

it('does not leak another user\'s activities into a guardian audit history', function () {
    $school = al_makeSchool();
    $admin = ga_makeAdmin($school->id);

    $guardianUser = al_makeUser($school->id);
    $guardian = al_makeGuardian($school->id, $guardianUser->id);

    $otherUser = al_makeUser($school->id);
    activity('auth')->causedBy($otherUser)->event('login')->log('Someone else logged in');

    $response = $this->actingAs($admin)
        ->withSession(['school_id' => $school->id])
        ->getJson("/api/guardians/{$guardian->uuid}/audit")
        ->assertOk();

    expect(collect($response->json('data'))->pluck('event'))->not->toContain('login');
});

it('still supports event filtering across the combined audit feed', function () {
    $school = al_makeSchool();
    $admin = ga_makeAdmin($school->id);

    $guardianUser = al_makeUser($school->id);
    $guardian = al_makeGuardian($school->id, $guardianUser->id);

    activity('guardian')->performedOn($guardian)->event('updated')->log('Guardian updated');
    activity('auth')->causedBy($guardianUser)->event('login')->log('Guardian logged in successfully');

    $response = $this->actingAs($admin)
        ->withSession(['school_id' => $school->id])
        ->getJson("/api/guardians/{$guardian->uuid}/audit?event=login")
        ->assertOk();

    $events = collect($response->json('data'))->pluck('event')->unique();

    expect($events->all())->toBe(['login']);
});
