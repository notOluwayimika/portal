<?php

namespace App\Support;

use App\Models\School;
use Laravel\Sanctum\PersonalAccessToken;

class ActiveSchool
{
    /**
     * Resolve the active school id for the authenticated user.
     *
     * Resolution order:
     *  1. session('school_id')       — web / stateful SPA requests
     *  2. token school_id            — pure token API clients (mobile)
     *  3. the user's own school_id   — single-school users (never super admins:
     *                                  without an explicit selection they act globally)
     */
    public static function id(): ?int
    {
        $user = auth()->user();

        if (!$user) {
            return null;
        }

        $request = request();

        if ($request->hasSession() && ($id = $request->session()->get('school_id'))) {
            return (int) $id;
        }

        if (method_exists($user, 'currentAccessToken')) {
            $token = $user->currentAccessToken();

            if ($token instanceof PersonalAccessToken && $token->school_id) {
                return (int) $token->school_id;
            }
        }

        if (!$user->isSuperAdmin() && $user->school_id) {
            return (int) $user->school_id;
        }

        return null;
    }

    /**
     * The active School model, or abort when no school context exists.
     * Use in controllers instead of auth()->user()->school (which is the
     * user's OWN school and wrong for multi-school admins / super admins).
     */
    public static function getOrFail(): School
    {
        $school = School::find(static::id());

        abort_unless((bool) $school, 403, 'No active school selected.');

        return $school;
    }
}
