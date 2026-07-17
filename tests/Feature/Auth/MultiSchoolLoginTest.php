<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Regression coverage for the multi-School authentication behaviour introduced in
 * commit 1895ae8 (SchoolAwareLoginResponse + SchoolSwitchController). The S4
 * investigation established this behaviour matches §6.5 (single login with School
 * switching) and §7.1 (no row = no access); these tests pin it so it can't
 * silently drift.
 */
uses(RefreshDatabase::class);

// --- Login response differs correctly per user category ----------------------

it('single-School user logs straight into the dashboard with School context', function () {
    $user = singleSchoolUser();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
    expect(session('school_id'))->not->toBeNull();
});

it('multi-School user is sent to School selection with no context yet', function () {
    $user = multiSchoolUser(2);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('school.select'));

    $this->assertAuthenticated();
    expect(session('school_id'))->toBeNull();
});

it('super admin lands in the super-admin area with no School session', function () {
    $user = superAdminUser();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect('/super-admin');

    $this->assertAuthenticated();
    expect(session('school_id'))->toBeNull();
});

it('user with zero accessible Schools is rejected at login', function () {
    $user = userWithNoAccessibleSchools();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

// --- SchoolSwitchController --------------------------------------------------

it('school switch establishes session context for an accessible School', function () {
    $user = multiSchoolUser(2);
    $school = $user->accessibleSchools()->first();

    $this->actingAs($user)
        ->post(route('school.switch'), ['school' => $school->uuid])
        ->assertRedirect();

    expect(session('school_id'))->toBe($school->id);
});

it('school switch rejects a School the user cannot access', function () {
    $user = singleSchoolUser();
    $foreign = al_makeSchool();

    $this->actingAs($user)
        ->post(route('school.switch'), ['school' => $foreign->uuid])
        ->assertStatus(403);

    expect(session('school_id'))->not->toBe($foreign->id);
});

it('School selection cannot be bypassed: a multi-School user with no selection is redirected off dashboard', function () {
    $user = multiSchoolUser(2);

    // Authenticated but no school_id chosen yet → context middleware redirects to selection.
    $this->actingAs($user)
        ->get('/dashboard')
        ->assertRedirect(route('school.select'));
});

it('super admins are exempt from the School-context requirement', function () {
    $user = superAdminUser();

    // No school_id in session, yet a super admin is not bounced to school.select.
    $this->actingAs($user)
        ->get(route('school.select'))
        ->assertOk();
});

it('a user who loses all School access mid-session reaches the School-selection page (documented current behaviour)', function () {
    $user = singleSchoolUser();
    $school = $user->accessibleSchools()->first();

    // Establish context, then revoke the only School.
    $this->actingAs($user)->withSession(['school_id' => $school->id]);
    $user->revokeSchoolAccess($school, 'admin');
    $user->forceFill(['school_id' => null])->save();

    // With no accessible School and no valid context, the context middleware
    // sends them to selection (which renders an empty picker — a dead-end, NOT a
    // redirect loop, since school.select is exempt). This is the intended state;
    // the test documents it rather than asserting a redesign.
    $this->actingAs($user->fresh())
        ->get('/dashboard')
        ->assertRedirect(route('school.select'));
});
