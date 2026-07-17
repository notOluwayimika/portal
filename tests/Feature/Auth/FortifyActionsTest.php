<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Fortify\Features;

/**
 * Regression coverage for the runtime defects S4 uncovered and this slice fixed:
 *  - the recreated Fortify actions (UpdateUserPassword / UpdateUserProfileInformation),
 *  - the restored RedirectIfTwoFactorAuthenticatable pipeline step.
 */
uses(RefreshDatabase::class);

function twoFactorUser(): User
{
    $user = singleSchoolUser();
    $user->forceFill([
        'two_factor_secret' => encrypt('test-secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code1', 'code2'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $user;
}

// --- §1 Fortify password action ------------------------------------------------

it('updates the password via the Fortify action (no 500)', function () {
    $user = singleSchoolUser();

    $this->actingAs($user)
        ->from(route('security.edit'))
        ->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('security.edit'));

    expect(Hash::check('new-password', $user->refresh()->password))->toBeTrue();
});

it('rejects a wrong current password', function () {
    $user = singleSchoolUser();

    $this->actingAs($user)
        ->from(route('security.edit'))
        ->put(route('user-password.update'), [
            'current_password' => 'wrong-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->assertSessionHasErrors('current_password');

    expect(Hash::check('password', $user->refresh()->password))->toBeTrue();
});

// --- §1 Fortify profile action (first_name/last_name, email verification) ------

it('updates profile via the Fortify action using first_name/last_name (no 500)', function () {
    $user = singleSchoolUser(['first_name' => 'Old', 'last_name' => 'Name']);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->put(route('user-profile-information.update'), [
            'first_name' => 'New',
            'last_name' => 'Person',
            'email' => $user->email,
        ])
        ->assertSessionHasNoErrors();

    $user->refresh();
    expect($user->first_name)->toBe('New')->and($user->last_name)->toBe('Person');
});

it('resets email verification when the email changes via the profile action', function () {
    $user = singleSchoolUser(); // verified by default

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->put(route('user-profile-information.update'), [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => 'changed-'.Str::random(6).'@example.test',
        ])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->email_verified_at)->toBeNull();
});

// --- §2 Two-factor challenge pipeline -----------------------------------------

it('challenges an enrolled user at login instead of logging them in', function () {
    Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]);
    $user = twoFactorUser();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'));

    $this->assertGuest(); // NOT logged in until the challenge is passed
});

it('does not challenge a user without two-factor enrolment', function () {
    Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]);
    $user = singleSchoolUser();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
});

it('cannot bypass the two-factor challenge by navigating directly', function () {
    Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]);
    $user = twoFactorUser();

    // Password step only — user is pending the 2FA challenge, still a guest.
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    // Direct navigation to a protected page does not let them in.
    $this->get('/dashboard')->assertRedirect(route('login'));
    $this->assertGuest();
});
