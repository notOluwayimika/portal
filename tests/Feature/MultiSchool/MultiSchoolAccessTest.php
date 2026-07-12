<?php

use App\Models\Role;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function ms_makeSuperAdmin(): User
{
    setPermissionsTeamId(null);
    foreach (['web', 'api'] as $guard) {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => $guard]);
    }

    $user = User::forceCreate([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'first_name' => 'Super',
        'last_name' => 'Admin',
        'email' => \Illuminate\Support\Str::uuid() . '@super.test',
        'password' => bcrypt('password'),
    ]);

    $user->assignRole('super_admin');

    return $user;
}

function ms_makeAdminRole(): void
{
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
}

// -------------------------------------------------------------------------
// Access resolution
// -------------------------------------------------------------------------

it('grants a super admin access to every school', function () {
    $a = al_makeSchool();
    $b = al_makeSchool();
    $super = ms_makeSuperAdmin();

    expect($super->isSuperAdmin())->toBeTrue()
        ->and($super->accessibleSchoolIds()->all())
        ->toContain($a->id, $b->id);
});

it('resolves a regular user to only their own school', function () {
    $a = al_makeSchool();
    $b = al_makeSchool();
    $user = al_makeUser($a->id);

    expect($user->canAccessSchool($a->id))->toBeTrue()
        ->and($user->canAccessSchool($b->id))->toBeFalse();
});

it('derives guardian school access from their guardian records across schools', function () {
    $a = al_makeSchool();
    $b = al_makeSchool();

    $user = al_makeUser($a->id);
    al_makeGuardian($a->id, $user->id);
    al_makeGuardian($b->id, $user->id);

    expect($user->canAccessSchool($a->id))->toBeTrue()
        ->and($user->canAccessSchool($b->id))->toBeTrue();
});

it('grants and revokes explicit school access with the admin role', function () {
    ms_makeAdminRole();

    $a = al_makeSchool();
    $b = al_makeSchool();
    $admin = al_makeUser($a->id);

    $admin->grantSchoolAccess($b);

    expect($admin->canAccessSchool($b->id))->toBeTrue();

    setPermissionsTeamId($b->id);
    $admin->unsetRelation('roles');
    expect($admin->hasRole('admin'))->toBeTrue();

    setPermissionsTeamId(null);
    $admin->revokeSchoolAccess($b);

    expect($admin->fresh()->canAccessSchool($b->id))->toBeFalse();

    setPermissionsTeamId($b->id);
    expect($admin->fresh()->hasRole('admin'))->toBeFalse();
    setPermissionsTeamId(null);
});

// -------------------------------------------------------------------------
// School selection / switching (web)
// -------------------------------------------------------------------------

it('lets a user switch to a school they can access', function () {
    $a = al_makeSchool();
    $user = al_makeUser($a->id);

    $response = $this->actingAs($user)->post('/select-school', ['school' => $a->uuid]);

    $response->assertRedirect();
    expect(session('school_id'))->toEqual($a->id);
});

it('rejects switching to a school the user cannot access', function () {
    $a = al_makeSchool();
    $b = al_makeSchool();
    $user = al_makeUser($a->id);

    $this->actingAs($user)
        ->post('/select-school', ['school' => $b->uuid])
        ->assertForbidden();
});

it('shows only accessible schools on the selection page', function () {
    $a = al_makeSchool();
    $b = al_makeSchool();

    $user = al_makeUser($a->id);
    al_makeGuardian($b->id, $user->id);

    $this->actingAs($user)
        ->get('/select-school')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('auth/select-school')
            ->has('schools', 2));
});

// -------------------------------------------------------------------------
// Super admin area
// -------------------------------------------------------------------------

it('lets a super admin create and update schools', function () {
    $super = ms_makeSuperAdmin();

    $this->actingAs($super)
        ->post('/super-admin/schools', ['name' => 'Multi Test School'])
        ->assertRedirect();

    $school = School::where('name', 'Multi Test School')->first();
    expect($school)->not->toBeNull()
        ->and($school->active)->toBeTrue();

    $this->actingAs($super)
        ->put("/super-admin/schools/{$school->uuid}", [
            'name' => 'Multi Test School Renamed',
            'address' => '1 Test Road',
            'active' => false,
        ])
        ->assertRedirect();

    $school->refresh();
    expect($school->name)->toBe('Multi Test School Renamed')
        ->and($school->active)->toBeFalse();
});

it('blocks non super admins from the super admin area', function () {
    $a = al_makeSchool();
    $user = al_makeUser($a->id);

    $this->actingAs($user)
        ->get('/super-admin/schools')
        ->assertForbidden();
});

it('lets a super admin create an admin with school access', function () {
    ms_makeAdminRole();

    $a = al_makeSchool();
    $b = al_makeSchool();
    $super = ms_makeSuperAdmin();

    $this->actingAs($super)
        ->post('/super-admin/admins', [
            'first_name' => 'New',
            'last_name' => 'Admin',
            'email' => 'new-admin@example.test',
            'password' => 'secret-password',
            'schools' => [$a->uuid, $b->uuid],
        ])
        ->assertRedirect();

    $admin = User::withoutGlobalScopes()->where('email', 'new-admin@example.test')->first();

    expect($admin)->not->toBeNull()
        ->and($admin->canAccessSchool($a->id))->toBeTrue()
        ->and($admin->canAccessSchool($b->id))->toBeTrue();
});

// -------------------------------------------------------------------------
// Regression: stale school context must never break authentication
// (SchoolScope used to scope the User model itself, so a session
// school_id from another school broke login + session user retrieval)
// -------------------------------------------------------------------------

it('logs in successfully even with a stale school_id from another school in the session', function () {
    $a = al_makeSchool();
    $b = al_makeSchool();
    $user = al_makeUser($a->id);

    $this->withSession(['school_id' => $b->id])
        ->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect();

    $this->assertAuthenticatedAs($user);
});

it('keeps a super admin authenticated after switching into a school', function () {
    $a = al_makeSchool();
    $super = ms_makeSuperAdmin();

    // Real session login (exercises the session guard's retrieveById).
    $this->post('/login', ['email' => $super->email, 'password' => 'password']);
    $this->assertAuthenticatedAs($super);

    $this->post('/select-school', ['school' => $a->uuid])->assertRedirect();
    expect(session('school_id'))->toEqual($a->id);

    // Follow-up request with the school context set: the super admin
    // (school_id = null) must still be resolvable from the session.
    $this->get('/super-admin/schools')->assertOk();
    $this->assertAuthenticatedAs($super);
});

// -------------------------------------------------------------------------
// API login
// -------------------------------------------------------------------------

it('requires school selection on api login when a user has multiple schools', function () {
    $a = al_makeSchool();
    $b = al_makeSchool();

    $user = al_makeUser($a->id);
    al_makeGuardian($b->id, $user->id);

    $this->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'password',
    ])
        ->assertStatus(409)
        ->assertJsonPath('requires_school_selection', true)
        ->assertJsonCount(2, 'schools');
});

it('rejects api login into an unauthorized school', function () {
    $a = al_makeSchool();
    $b = al_makeSchool();
    $user = al_makeUser($a->id);

    $this->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'password',
        'school_uuid' => $b->uuid,
    ])->assertForbidden();
});

it('logs a single-school user straight in via the api', function () {
    $a = al_makeSchool();
    $user = al_makeUser($a->id);

    $this->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'password',
    ])
        ->assertOk()
        ->assertJsonPath('school.uuid', $a->uuid);
});
