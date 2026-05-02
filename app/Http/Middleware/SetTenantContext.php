<?php

namespace App\Http\Middleware;

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

        $user = auth()->user();

        // Always check role in GLOBAL context first
        setPermissionsTeamId(null);

        $isSuperAdmin = $user->isSuperAdmin();

        if ($isSuperAdmin) {
            $activeSchoolId = session('school_id');
        } else {
            $activeSchoolId = session('school_id') ?? $user->school_id;
        }

        if ($activeSchoolId) {
            setPermissionsTeamId($activeSchoolId);
            $user->unsetRelation('roles');
        }

        // Optional restriction logic
        if (!$isSuperAdmin && !$activeSchoolId) {
            abort(403, 'No active school context');
        }

        return $next($request);
    }
}
