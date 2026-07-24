<?php

namespace App\Support;

use App\Models\School;
use Closure;
use Laravel\Sanctum\PersonalAccessToken;

class ActiveSchool
{
    /**
     * Explicit off-request context set by runFor(). Takes precedence over every
     * request-derived source so queued jobs and scheduled commands resolve the
     * School they were told to run for, not whatever the worker last held.
     */
    private static ?int $override = null;

    /**
     * Resolve the active school id.
     *
     * Resolution order:
     *  0. runFor() override          — explicit off-request context
     *  1. session('school_id')       — web / stateful SPA requests
     *  2. token school_id            — pure token API clients (mobile)
     *  3. the user's own school_id   — single-school users (never super admins:
     *                                  without an explicit selection they act globally)
     */
    public static function id(): ?int
    {
        if (static::$override !== null) {
            return static::$override;
        }

        $user = auth()->user();

        if (! $user) {
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

        if (! $user->isSuperAdmin() && $user->school_id) {
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

    /**
     * Establish an explicit School context for the duration of $cb, then ALWAYS
     * restore the previous context (finally). This is the only sanctioned way to
     * set School context off-request (queued jobs, scheduled commands).
     *
     * The finally-restore prevents the spatie PermissionRegistrar singleton
     * leaking a team id into the next job on a long-running worker.
     *
     * @template T
     *
     * @param  Closure(): T  $cb
     * @return T
     */
    /** Read/set the raw override — Impersonation restores CAPTURED values. */
    public static function override(): ?int
    {
        return self::$override;
    }

    public static function overrideWith(?int $schoolId): void
    {
        self::$override = $schoolId;
    }

    public static function runFor(int $schoolId, Closure $cb): mixed
    {
        $previousOverride = static::$override;
        $previousTeam = getPermissionsTeamId();

        static::$override = $schoolId;
        setPermissionsTeamId($schoolId);
        auth()->user()?->unsetRelation('roles');

        try {
            return $cb();
        } finally {
            static::$override = $previousOverride;
            setPermissionsTeamId($previousTeam);
            auth()->user()?->unsetRelation('roles');
        }
    }
}
