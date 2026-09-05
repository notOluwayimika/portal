<?php

namespace App\Support;

use App\Models\Role;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Route;

/**
 * Derives, per route, the set of roles the route-level middleware admits —
 * the semantic layer above RbacDeriveRouteBaseline's raw middleware stacks.
 *
 * The committed snapshot (tests/fixtures/route-access-map.json, generated
 * pre-swap via `rbac:derive-access`) is the access oracle the C2
 * role:→permission: swap must preserve: RouteAccessParityTest re-derives this
 * map live and diffs it against the fixture, so any change to any route's
 * effective role set — on either middleware mechanism — is a red test until
 * the fixture is regenerated as an explicit, reviewed diff.
 *
 * Admission rules mirror the real middleware:
 *  - `role:a|b`      → listed roles, plus super_admin unconditionally
 *                      (EnsureRole bypasses every role: gate).
 *  - `permission:p|q`→ web-guard global roles holding any listed permission,
 *                      plus super_admin only while auth.gate_before_superadmin
 *                      is on (Gate::before — the probe's tested dependency;
 *                      flag-off admits only actual holders).
 *  - stacked entries intersect; routes with neither admit every role.
 */
class RouteAccessMap
{
    /**
     * "METHODS /uri" → ['auth' => bool, 'roles' => sorted list<string>]
     *
     * @return array<string, array{auth: bool, roles: list<string>}>
     */
    public static function derive(): array
    {
        $holders = null; // lazy — only permission: entries need the DB
        $map = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $middleware = array_values($route->gatherMiddleware());
            $allowed = RbacSeeder::ROLES;

            foreach ($middleware as $entry) {
                if (str_starts_with($entry, 'role:')) {
                    $listed = explode('|', substr($entry, strlen('role:')));
                    $allowed = array_intersect($allowed, [...$listed, 'super_admin']);
                }

                if (str_starts_with($entry, 'permission:')) {
                    $holders ??= self::holders();
                    $listed = explode('|', substr($entry, strlen('permission:')));
                    $admitted = collect($listed)->flatMap(fn ($p) => $holders[$p] ?? [])->unique()->all();

                    // The bypass admits super_admin only while the flag is on AND
                    // no listed ability is a checker action — ADR 0040 exclusions
                    // are never bypassed, so a `permission:*.approve` route admits
                    // only genuine holders.
                    //
                    // THIS BRANCH IS LOAD-BEARING, NOT HYPOTHETICAL. It used to say
                    // "no route carries one today". Measured 2026-09-05: 20 of the
                    // 437 registered routes carry a checker-segment permission — 16
                    // finance (five pending queues, ten decision endpoints, and the
                    // /finance/approvals page) plus internal audit's FOUR. So this is
                    // the branch deciding super_admin's absence from 20 rows of the
                    // committed access map, not a case waiting to arrive.
                    //
                    // THE COUNT IS ITEMISED BECAUSE THE FIRST VERSION OF THIS COMMENT
                    // SAID "THREE" AND DID NOT SUM. Internal audit's fourth is
                    // `GET /internal-audit/review-queue`, the Inertia page declared in
                    // routes/web.php rather than under /api — which is exactly why it
                    // is the one a reader drops. It had already produced one false
                    // statement elsewhere by being overlooked (see the docblock in
                    // routes/endpoints/internal-audit.php), so a total here without a
                    // breakdown that reconciles to it is not worth writing.
                    $bypassable = collect($listed)
                        ->every(fn ($p) => ! ApprovalAbility::isExcludedFromSuperAdminBypass($p));

                    if (config('auth.gate_before_superadmin') && $bypassable) {
                        $admitted[] = 'super_admin';
                    }

                    $allowed = array_intersect($allowed, $admitted);
                }
            }

            sort($allowed);

            $methods = implode('|', array_values(array_diff($route->methods(), ['HEAD'])));
            $map[$methods.' /'.ltrim($route->uri(), '/')] = [
                'auth' => collect($middleware)->contains(fn ($m) => str_starts_with($m, 'auth')),
                'roles' => $allowed,
            ];
        }

        ksort($map);

        return $map;
    }

    /**
     * permission name → web-guard global roles holding it.
     *
     * @return array<string, list<string>>
     */
    private static function holders(): array
    {
        $holders = [];

        Role::with('permissions')
            ->where('guard_name', RbacSeeder::GUARD)
            ->whereNull('school_id')
            ->get()
            ->each(function (Role $role) use (&$holders) {
                foreach ($role->permissions as $permission) {
                    $holders[$permission->name][] = $role->name;
                }
            });

        return $holders;
    }
}
