<?php

namespace App\Http\Middleware;

use App\Support\ActiveSchool;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetTenantContext
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!auth()->check()) {
            return $next($request);
        }

        /** @var \App\Models\User $user */
        $user = auth()->user();

        // Always check role in GLOBAL context first
        setPermissionsTeamId(null);

        $isSuperAdmin = $user->isSuperAdmin();
        $activeSchoolId = ActiveSchool::id();

        // Access may have been revoked mid-session: clear the stale context.
        if ($activeSchoolId && !$user->canAccessSchool($activeSchoolId)) {
            if ($request->hasSession()) {
                $request->session()->forget('school_id');
            }

            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'You are not authorized to login to this school.'], 403);
            }

            return redirect()->route('school.select');
        }

        if ($activeSchoolId) {
            setPermissionsTeamId($activeSchoolId);
            $user->unsetRelation('roles');
        }

        if (!$isSuperAdmin && !$activeSchoolId) {
            if ($this->allowsMissingSchoolContext($request)) {
                return $next($request);
            }

            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'No active school selected.'], 403);
            }

            return redirect()->route('school.select');
        }

        return $next($request);
    }

    /**
     * Routes that must stay reachable while no school is selected
     * (otherwise selecting/switching school would be impossible).
     */
    private function allowsMissingSchoolContext(Request $request): bool
    {
        if ($request->routeIs('school.select', 'school.switch', 'logout')) {
            return true;
        }

        return $request->is('api/switch-school', 'api/logout', 'select-school', 'logout');
    }
}
