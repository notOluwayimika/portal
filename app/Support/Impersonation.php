<?php

namespace App\Support;

use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
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
    /**
     * The one session key. A single array rather than four loose keys so that
     * forgetting the session is one atomic operation — a half-cleared session
     * (target gone, operator left behind) is a state no caller can produce.
     */
    public const SESSION_KEY = 'impersonation';

    private static bool $active = false;

    /**
     * Begin an HTTP session: audit the entry, then record it for the middleware
     * to apply on this and every subsequent request.
     *
     * The gate is re-checked HERE rather than trusted from the route, for the
     * reason in operatorMayImpersonate().
     *
     * @throws AuthorizationException
     * @throws LogicException on an attempt to nest
     */
    public static function startSession(Request $request, User $operator, User $target, int $schoolId): void
    {
        if ($request->hasSession() && $request->session()->has(self::SESSION_KEY)) {
            throw new LogicException('Impersonation sessions do not nest.');
        }

        if (! self::operatorMayImpersonate($operator)) {
            throw new AuthorizationException('Impersonation is a platform-admin capability.');
        }

        self::logStarted($operator, $target->getKey(), $schoolId);

        $request->session()->put(self::SESSION_KEY, [
            'operator_id' => $operator->getKey(),
            'target_id' => $target->getKey(),
            'school_id' => $schoolId,
            'started_at' => now()->timestamp,
        ]);
    }

    /**
     * The explicit grant, resolved in the NULL team (super_admin's role row
     * is global) regardless of whatever team the caller's context has set —
     * and restored, so the check itself cannot leak context.
     */
    private static function holdsImpersonateGrant(User $operator): bool
    {
        $previousTeam = getPermissionsTeamId();
        setPermissionsTeamId(null);
        $operator->unsetRelation('roles')->unsetRelation('permissions');

        try {
            return $operator->hasPermissionTo('rbac.impersonate');
        } finally {
            setPermissionsTeamId($previousTeam);
            $operator->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    /**
     * The entry gate, usable on its own by the HTTP layer.
     *
     * Structural isSuperAdmin PLUS the explicit rbac.impersonate grant, checked
     * FLAG-INDEPENDENTLY via spatie's hasPermissionTo — which never consults
     * Gate::before. A can() gate would stay green under bypass-on with the
     * grant missing and flip super_admin to stranded the moment 0045-C removes
     * the bypass: the C1 flag-coupling failure, one layer down. The grant is
     * the MASTER KEY (ADR 0045 A3); its seeded presence is self-healed and its
     * absence is a dedicated lockout bite-proof.
     *
     * The route's `permission:rbac.impersonate` middleware CANNOT replace this:
     * it resolves through the Gate, which the bypass answers true for any
     * super_admin whether or not the grant is held.
     */
    public static function operatorMayImpersonate(User $operator): bool
    {
        return $operator->isSuperAdmin() && self::holdsImpersonateGrant($operator);
    }

    /** Whether a session is currently applied in this process. */
    public static function active(): bool
    {
        return self::$active;
    }

    /**
     * Audited, closure-bounded session: entry event → apply → exit event.
     *
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

        if (! self::operatorMayImpersonate($operator)) {
            throw new AuthorizationException('Impersonation is a platform-admin capability.');
        }

        self::logStarted($operator, $target->getKey(), $schoolId);

        try {
            return self::apply($operator, $target, $schoolId, $cb);
        } finally {
            self::logEnded($operator, $target->getKey(), $schoolId);
        }
    }

    /**
     * Apply the context for the duration of $cb — the SAME three-value swap and
     * restore as actAs(), with NO audit rows.
     *
     * This is what the per-request middleware uses. It cannot call actAs():
     * that logs entry AND exit on every invocation, so an impersonated browsing
     * session would write two rows per request and bury the real security
     * events under its own noise. Entry/exit are logged once, by the endpoints
     * that actually start and end the session.
     *
     * The gate lives in actAs()/the controller, not here: this is the mechanism,
     * and the middleware only reaches it for a session the gate already admitted.
     *
     * @template T
     *
     * @param  Closure(): T  $cb
     * @return T
     */
    public static function apply(User $operator, User $target, int $schoolId, Closure $cb): mixed
    {
        if (self::$active) {
            throw new LogicException('Impersonation sessions do not nest.');
        }

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

            // Un-pin AFTER restoring the guard. Callers that log an exit event
            // do so outside this method, explicitly as the operator — the
            // attribution of a security event is never left to a resolver
            // default.
            app(CauserResolver::class)->setCauser(null);
        }
    }

    /**
     * End the HTTP session: write the exit row AND clear the session keys.
     *
     * ONE helper because there are three ways out — the stop endpoint, expiry,
     * and logout — and each must do both halves. Three copies of that pairing
     * is three chances to clear the keys without writing the row, which is
     * exactly the "a start with no end" hole in the audit trail that the whole
     * non-repudiation posture (ADR 0045 §3) depends on not having. Breaking
     * this method must break all three exit proofs at once.
     *
     * Safe to call when no session is active: it returns false and writes
     * nothing, so callers (logout especially) need no precondition of their own.
     */
    public static function endSession(Request $request): bool
    {
        if (! $request->hasSession() || ! $request->session()->has(self::SESSION_KEY)) {
            return false;
        }

        $state = (array) $request->session()->get(self::SESSION_KEY);
        $request->session()->forget(self::SESSION_KEY);

        $operator = User::withoutGlobalScopes()->find($state['operator_id'] ?? null);

        if (! $operator) {
            // Keys are gone either way — the session must never survive a
            // missing operator — but an exit we cannot attribute is not one we
            // pretend to have logged.
            return false;
        }

        self::logEnded($operator, $state['target_id'] ?? null, $state['school_id'] ?? null);

        return true;
    }

    private static function logStarted(User $operator, mixed $targetId, ?int $schoolId): void
    {
        // Attributed BEFORE any context changes so its causer/team reflect the
        // operator's world.
        activity('rbac')
            ->causedBy($operator)
            ->event('impersonation_started')
            ->withProperties(['acted_as' => $targetId, 'school_id' => $schoolId])
            ->log('impersonation_started');
    }

    private static function logEnded(User $operator, mixed $targetId, ?int $schoolId): void
    {
        activity('rbac')
            ->causedBy($operator)
            ->event('impersonation_ended')
            ->withProperties(['acted_as' => $targetId, 'school_id' => $schoolId])
            ->log('impersonation_ended');
    }
}
