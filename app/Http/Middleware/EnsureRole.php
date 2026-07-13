<?php

namespace App\Http\Middleware;

use Closure;
use Spatie\Permission\Middleware\RoleMiddleware;

/**
 * Wraps Spatie's RoleMiddleware so that super admins (a global role,
 * checked outside any team/school context) pass every role gate.
 */
class EnsureRole extends RoleMiddleware
{
    public function handle($request, Closure $next, $role, $guard = null)
    {
        $user = auth($guard)->user();

        if ($user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return $next($request);
        }

        return parent::handle($request, $next, $role, $guard);
    }
}
