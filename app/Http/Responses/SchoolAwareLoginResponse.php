<?php

namespace App\Http\Responses;

use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;

/**
 * Post-login school resolution (used for both password and 2FA logins):
 *  - super admins go to the super admin area (global context)
 *  - users with exactly one accessible school are logged straight in
 *  - users with several accessible schools pick one first
 *  - users with none are rejected
 */
class SchoolAwareLoginResponse implements LoginResponse, TwoFactorLoginResponse
{
    public function toResponse($request)
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        setPermissionsTeamId(null);

        if ($user->isSuperAdmin()) {
            $request->session()->forget('school_id');

            return redirect()->intended('/super-admin');
        }

        $schools = $user->accessibleSchools();

        if ($schools->isEmpty()) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                'email' => 'You are not authorized to log in to any school.',
            ]);
        }

        if ($schools->count() === 1) {
            $request->session()->put('school_id', $schools->first()->id);

            return redirect()->intended(config('fortify.home', '/dashboard'));
        }

        // Multiple schools: let the user pick which one to enter.
        $request->session()->forget('school_id');

        return redirect()->route('school.select');
    }
}
