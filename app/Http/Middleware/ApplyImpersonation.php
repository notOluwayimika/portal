<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies a started impersonation session to THIS request (ADR 0045 A1).
 *
 * The session state lives in the session; the context swap is per-request and
 * always unwound, so nothing leaks into the next request — the bounded-session
 * claim (ADR 0045 §4, the NoTeamLeakBetweenJobs family) holds by construction
 * rather than by remembering to clean up.
 *
 * THE SLOT IS A SECURITY DECISION, not a detail. This must run IMMEDIATELY
 * AFTER EnsureTwoFactorEnrolled:
 *
 *   SetSchoolContext → EnsureTwoFactorEnrolled → ApplyImpersonation → HandleAppearance → Inertia
 *
 *  - AFTER EnsureTwoFactorEnrolled: that middleware reads $request->user(). If
 *    the user were already swapped, it would evaluate the TARGET — so
 *    impersonating an unenrolled `admin` would redirect the operator to that
 *    user's 2FA enrolment page and let them enrol 2FA on someone else's
 *    account. 2FA belongs to the human at the keyboard, i.e. the operator.
 *  - BEFORE HandleInertiaRequests: shared auth.user/permissions must resolve as
 *    the target, so the app genuinely looks like the target's.
 *  - Route middleware (permission:, role:) runs after the group, so it sees the
 *    target. That is the point.
 */
class ApplyImpersonation
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || ! $request->session()->has(Impersonation::SESSION_KEY)) {
            return $next($request);
        }

        $state = (array) $request->session()->get(Impersonation::SESSION_KEY);

        if ($this->hasExpired($state)) {
            // endSession() writes the exit row AND clears the keys — the same
            // single helper the stop endpoint and logout use, so an expiry can
            // never be the one path that forgets the audit row.
            Impersonation::endSession($request);

            return $this->bail($request, 'Your impersonation session expired.');
        }

        // withoutGlobalScopes: users are school-scoped, and the operator is a
        // super_admin with no school of their own, so a scoped lookup would
        // resolve neither reliably.
        $operator = User::withoutGlobalScopes()->find($state['operator_id'] ?? null);
        $target = User::withoutGlobalScopes()->find($state['target_id'] ?? null);
        $schoolId = isset($state['school_id']) ? (int) $state['school_id'] : null;

        // A deleted operator or target, or a session written by an older shape.
        // Fail CLOSED: end the session rather than continue in a half-known
        // identity.
        if (! $operator || ! $target || ! $schoolId) {
            Impersonation::endSession($request);
            $request->session()->forget(Impersonation::SESSION_KEY);

            return $this->bail($request, 'Your impersonation session ended.');
        }

        return Impersonation::apply($operator, $target, $schoolId, fn () => $next($request));
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function hasExpired(array $state): bool
    {
        $maxMinutes = (int) config('impersonation.max_minutes');

        if ($maxMinutes <= 0) {
            return false;
        }

        $startedAt = (int) ($state['started_at'] ?? 0);

        // A session with no timestamp is treated as expired, not as eternal:
        // the unknown case resolves toward ending the session.
        return $startedAt <= 0 || now()->timestamp - $startedAt > $maxMinutes * 60;
    }

    private function bail(Request $request, string $message): Response
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['message' => $message, 'code' => 'IMPERSONATION_ENDED'], 409);
        }

        return redirect()->back()->with('status', $message);
    }
}
