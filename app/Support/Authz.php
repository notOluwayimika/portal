<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Authorization rollout gate (S5). A single call site expresses a check and runs
 * in one of two modes selected by config('authz.enforce'):
 *
 *   observe (default) — evaluate the check, RECORD a would-be denial to the
 *                       authz_observations table, and CONTINUE. Never blocks.
 *   enforce           — abort(403) on failure (the future enforcement slice).
 *
 * This lets every dormant check be restored as live code (clearing the
 * commented-authz debt) while its real-traffic impact is measured before any
 * user is actually denied. `abilityCheck()` covers `->can()` permissions;
 * `ensure()` covers boolean ownership/role/business-rule guards.
 */
class Authz
{
    /** Permission check: $user->can($ability). */
    public static function abilityCheck(?User $user, string $ability, string $controllerAction, int $status = 403): void
    {
        self::gate((bool) $user?->can($ability), $ability, 'permission', $controllerAction, $status);
    }

    /**
     * Boolean guard (ownership / role / business rule). $passes is the condition
     * that must hold; $name identifies it in the evidence.
     */
    public static function ensure(bool $passes, string $name, string $checkType, string $controllerAction, int $status = 403): void
    {
        self::gate($passes, $name, $checkType, $controllerAction, $status);
    }

    private static function gate(bool $passes, string $ability, string $checkType, string $controllerAction, int $status): void
    {
        if ($passes) {
            return;
        }

        if (config('authz.enforce', false)) {
            abort($status);
        }

        self::record($ability, $checkType, $controllerAction);
    }

    private static function record(string $ability, string $checkType, string $controllerAction): void
    {
        try {
            $request = request();
            $user = $request?->user();

            DB::table('authz_observations')->insert([
                'user_id' => $user?->getKey(),
                'school_id' => ActiveSchool::id(),
                'ability' => $ability,
                'check_type' => $checkType,
                'controller_action' => $controllerAction,
                'route' => $request?->route()?->getName(),
                'request_uri' => $request ? substr($request->fullUrl(), 0, 1024) : null,
                'method' => $request?->method(),
                'transport' => self::transport($request),
                'roles' => json_encode($user ? $user->getRoleNames()->values()->all() : []),
                'occurred_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Observe mode must never break a request: a failed observation is
            // logged, not raised.
            Log::warning('authz-observe: failed to record observation', [
                'ability' => $ability,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private static function transport($request): string
    {
        // An HTTP request is bound in web/api (and feature tests) — classify by path.
        if ($request && $request->route()) {
            return $request->is('api/*') ? 'api' : 'http';
        }

        // No request bound → off-request. Queue workers and artisan both report as
        // console here; the authz checks live in controllers, so off-request rows
        // would only appear if a command/job ever calls Authz.
        return app()->runningInConsole() ? 'console' : 'queue';
    }
}
