<?php

namespace App\Support;

use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;
use Spatie\Activitylog\CauserResolver;

/**
 * The sanctioned super-admin impersonation session (ADR 0045 A1, as corrected
 * by 0045-B1 step 0; §5.6/ADR 0026 carve-out in CONTRIBUTING.md).
 *
 * OPERATOR ATTRIBUTION IS THE INVARIANT. The session sets the impersonated
 * principal on the guard — the only way C2's permission: middleware, policies
 * and FormRequests (all reading $authGuard->user()) resolve as the target —
 * while the audit causer is pinned to the OPERATOR session-wide via spatie's
 * CauserResolver, so every audited action names the super_admin behind the
 * acted-as identity. This is what distinguishes it from the banned ADR 0026
 * hack on every axis: bounded, entry/exit audited, context set EXPLICITLY
 * from (user, school) — never set-the-user-to-obtain-context — and
 * attribution follows the operator, never the swapped identity.
 *
 * Three things are set and ALL THREE are restored in a finally, to the
 * CAPTURED prior values (runFor's shape — never a hardcoded baseline, so a
 * mid-session throw cannot strand the wrong context): guard user, ActiveSchool
 * override, permissions-team. Nesting is refused outright — it is the
 * strand-the-wrong-context hazard with no legitimate use.
 */
class Impersonation
{
    private static bool $active = false;

    /**
     * @template T
     *
     * @param  Closure(): T  $cb
     * @return T
     */
    public static function actAs(User $operator, User $target, int $schoolId, Closure $cb): mixed
    {
        if (self::$active) {
            throw new LogicException('Impersonation sessions do not nest.');
        }

        // Entry gate: STRUCTURAL (isSuperAdmin), deliberately not
        // can('rbac.impersonate') yet — before B2 seeds the explicit grant,
        // a can() gate resolves through the Gate::before bypass and couples
        // session entry to AUTH_GATE_BEFORE_SUPERADMIN (flag-off would lock
        // even super_admin out of a capability the flag does not govern).
        // B2 tightens this to the explicit, flag-independent grant.
        if (! $operator->isSuperAdmin()) {
            throw new AuthorizationException('Impersonation is a platform-admin capability.');
        }

        // Entry is itself an audited security event, attributed BEFORE any
        // context changes so its causer/team reflect the operator's world.
        activity('rbac')
            ->causedBy($operator)
            ->event('impersonation_started')
            ->withProperties(['acted_as' => $target->getKey(), 'school_id' => $schoolId])
            ->log('impersonation_started');

        $guard = auth();
        $previousUser = $guard->user();
        $previousOverride = ActiveSchool::override();
        $previousTeam = getPermissionsTeamId();

        self::$active = true;

        // The mechanical attribution pin (claim 2): every activity row written
        // inside the session resolves its causer to the operator, regardless
        // of the guard's acting user — per-session, not per-write-site.
        app(CauserResolver::class)->setCauser($operator);

        $guard->setUser($target);
        ActiveSchool::overrideWith($schoolId);
        setPermissionsTeamId($schoolId);
        $target->unsetRelation('roles')->unsetRelation('permissions');

        try {
            return $cb();
        } finally {
            // Restore the three CAPTURED values independently — a partial
            // restore is a silent team leak (NoTeamLeakBetweenJobs family).
            self::$active = false;

            if ($previousUser !== null) {
                $guard->setUser($previousUser);
            }
            ActiveSchool::overrideWith($previousOverride);
            setPermissionsTeamId($previousTeam);
            $previousUser?->unsetRelation('roles');

            // Un-pin AFTER restoring the guard, then write the exit event as
            // the operator explicitly (the resolver default would also find
            // the operator now, but the attribution of a security event is
            // never left to a default).
            app(CauserResolver::class)->setCauser(null);

            activity('rbac')
                ->causedBy($operator)
                ->event('impersonation_ended')
                ->withProperties(['acted_as' => $target->getKey(), 'school_id' => $schoolId])
                ->log('impersonation_ended');
        }
    }
}
