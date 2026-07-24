<?php

use Laravel\Fortify\Features;

return [
    'guard' => 'web',
    'middleware' => ['web'],
    'auth_middleware' => 'auth',
    'passwords' => 'users',
    'username' => 'email',
    'email' => 'email',
    'views' => true,
    'home' => '/dashboard',
    'prefix' => '',
    'domain' => null,
    'lowercase_usernames' => false,
    'limiters' => [
        'login' => null,
    ],
    'paths' => [
        'login' => null,
        'logout' => null,
        'password' => [
            'request' => null,
            'reset' => null,
            'email' => null,
            'update' => null,
            'confirm' => null,
            'confirmation' => null,
        ],
        'register' => null,
        'verification' => [
            'notice' => null,
            'verify' => null,
            'send' => null,
        ],
        'user-profile-information' => [
            'update' => null,
        ],
        'user-password' => [
            'update' => null,
        ],
        'two-factor' => [
            'login' => null,
            'enable' => null,
            'confirm' => null,
            'disable' => null,
            'qr-code' => null,
            'secret-key' => null,
            'recovery-codes' => null,
        ],
    ],
    'redirects' => [
        'login' => null,
        'logout' => null,
        'password-confirmation' => null,
        'register' => null,
        'email-verification' => null,
        'password-reset' => null,
    ],
    'features' => [
        // Public self-registration is disabled: super admins provision
        // schools and admins; admins provision everyone else.
        // Features::registration(),
        Features::resetPasswords(),
        // Email verification is intentionally NOT enabled: registration is
        // disabled and users are administrator-created, so MustVerifyEmail adds
        // little today. The email_verified_at column and the reset-on-email-change
        // behaviour are retained (see UpdateUserProfileInformation /
        // Settings\ProfileController) for future self-onboarding work.
        Features::updateProfileInformation(),
        // updatePasswords is NOT enabled: Settings\SecurityController owns password
        // updates (own PasswordUpdateRequest + throttle:6,1) and deliberately claims
        // Fortify's canonical `user-password.update` name. Enabling the feature
        // registers a SECOND route under that same name, which (a) makes
        // `route:cache` throw — so it fails only in production, never in tests — and
        // (b) exposes an UNTHROTTLED PUT /user/password that wayfinder actually bound
        // to. `route()` resolves to the last registration, so removing this preserves
        // today's behaviour rather than changing it.
        // Features::updatePasswords(),
        // confirm: users must prove a working authenticator before enrolment
        // completes. confirmPassword: a hijacked session cannot disable 2FA
        // without re-entering the password.
        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]),
    ],
];
