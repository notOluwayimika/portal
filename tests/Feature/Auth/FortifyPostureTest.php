<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Fortify\Features;

/**
 * Locks the pre-enforcement Fortify security posture (§24 "enforceable 2FA").
 */
uses(RefreshDatabase::class);

it('requires confirmation and password confirmation for two-factor', function () {
    expect(Features::canManageTwoFactorAuthentication())->toBeTrue()
        ->and(Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm'))->toBeTrue()
        ->and(Features::optionEnabled(Features::twoFactorAuthentication(), 'confirmPassword'))->toBeTrue();
});

it('does not enable the email-verification feature', function () {
    expect(Features::enabled(Features::emailVerification()))->toBeFalse();

    // The verification routes are therefore not registered.
    expect(app('router')->getRoutes()->getByName('verification.notice'))->toBeNull();
});

it('does not register self-registration (no create-users action, no register route)', function () {
    expect(app('router')->getRoutes()->getByName('register'))->toBeNull();
});

it('retains email_verified_at and reset-on-email-change for future onboarding', function () {
    // Column retained.
    expect(Schema::hasColumn('users', 'email_verified_at'))->toBeTrue();

    // Reset-on-email-change behaviour still lives in the profile action.
    $user = singleSchoolUser(); // verified
    expect($user->email_verified_at)->not->toBeNull();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->put(route('user-profile-information.update'), [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => 'onboarding-'.Str::random(6).'@example.test',
        ])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->email_verified_at)->toBeNull();
});
