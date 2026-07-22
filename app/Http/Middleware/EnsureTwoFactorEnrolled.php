<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * C7 — a user holding any 2FA-required role must be enrolled before using
 * protected surfaces. Web redirects to the security settings; API answers
 * 403 TWO_FACTOR_REQUIRED (a JSON contract, never an HTML redirect).
 *
 * The requirement is GLOBAL to the account (c7-brief D1): it reads role
 * membership across ALL teams, so it does not depend on SetSchoolContext
 * having run and a middleware reorder cannot silently disable it. The read
 * goes straight to model_has_roles (D4) — NOT through the Gate — so the
 * super-admin Gate::before bypass is irrelevant here: super_admin is the
 * one place in this track constrained by a mechanism the bypass cannot
 * reach.
 *
 * EXEMPTIONS are the deadlock escape (c7-brief D2): the enrolment surface
 * itself, Fortify's 2FA endpoints, logout (both transports) and school
 * selection. Bite-proven as a loop (redirected user can reach enrolment,
 * enrol, and proceed; can log out unenrolled), not asserted as a list.
 */
class EnsureTwoFactorEnrolled
{
    /** Paths an unenrolled user must still reach (request-path patterns). */
    public const EXEMPT_PATTERNS = [
        'settings/security*',   // the enrolment page itself
        'user/two-factor*',     // Fortify enable/confirm/QR endpoints
        'user/confirm-password', 'user/confirmed-password-status',
        'two-factor-challenge*',
        'logout',
        'api/logout',
        'select-school',
    ];

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (! $user instanceof User
            || $user->two_factor_confirmed_at !== null
            || $request->is(...self::EXEMPT_PATTERNS)
            || ! $this->requiresTwoFactor($user)) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'message' => 'Two-factor authentication enrolment is required for your role.',
                'code' => 'TWO_FACTOR_REQUIRED',
            ], 403);
        }

        return redirect()->route('security.edit')
            ->with('warning', 'Your role requires two-factor authentication. Please enrol to continue.');
    }

    /**
     * Team-agnostic (D1) and Gate-free (D4): a 2FA-required role held in ANY
     * School's team — or globally — triggers the requirement.
     */
    private function requiresTwoFactor(User $user): bool
    {
        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', User::class)
            ->where('model_has_roles.model_id', $user->getKey())
            ->where('roles.two_factor_required', true)
            ->exists();
    }
}
