<?php

use App\Console\Commands\RbacDeriveRouteBaseline;
use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * THE PROPERTY `admin_viewer` EXISTS TO HAVE: nothing it holds unlocks a write.
 *
 * Stated against the LIVE ROUTE TABLE, not against permission names, because the defect this test
 * was written for is invisible to names. `admin_area.access` reads like an entry gate and was the
 * SOLE guard on 18 write routes — POST/PUT `/students`, POST `/api/guardians/{guardian}/password`,
 * POST|DELETE `/setup/principals` and `/api/heads-of-schools`, the notice CRUD, POST|DELETE
 * `/api/teacher-assignments`, PUT `/api/teachers/{teacher}/schools`, and the two curriculum
 * migrations. No convention over permission strings can see that. The route table can, and a
 * seat's read-only-ness is a claim about routes rather than about vocabulary.
 *
 * PROPERTY, NOT CASE. It does not enumerate the writes anyone thought of; it asks the complement
 * question — which non-GET routes does this role reach — and demands the answer be a set that was
 * READ AND SIGNED OFF. So a write route placed behind a read permission tomorrow reds here whether
 * or not it was imagined today.
 *
 * ─── COVERAGE IS PART OF THE RESULT ──────────────────────────────────────────────────────────────
 *
 * A green here means nothing unless it also says what it looked at. The run reports three numbers
 * and asserts the third is zero:
 *
 *   EXAMINED    — non-GET routes carrying at least one `permission:` clause.
 *   EXCLUDED    — with a stated reason: GET/HEAD-only (not a write), `role:`-gated (admin_viewer is
 *                 in no role list, and RoleMiddleware does not read permissions), or carrying no
 *                 authorization middleware at all (reachable by every authenticated user — such a
 *                 route is not something THIS role's grants unlock, and is a separate concern).
 *   UNRECOGNISED— an authorization-shaped middleware the parser cannot classify. Asserted ZERO, so
 *                 a new gate mechanism reds here instead of quietly shrinking the denominator.
 */

/** Middleware prefixes that are authorization decisions this parser must be able to read. */
const AV_AUTHZ_PREFIXES = ['permission', 'role', 'can'];

/**
 * Non-authorization middleware. Listed EXPLICITLY rather than defaulted past, so an unfamiliar
 * entry lands in UNRECOGNISED and reds rather than being silently assumed harmless.
 */
const AV_NON_AUTHZ = [
    'web', 'api', 'auth', 'auth:sanctum', 'guest', 'tenant', 'verified',
    'password.confirm', 'throttle', 'guardian_ward', 'guardian_no_bulk', 'guardian_self',
];

/**
 * The REVIEWED exceptions: non-GET routes `admin_viewer` legitimately reaches.
 *
 * All three predate this role and are reachable by every holder of the read permission that gates
 * them — `teacher` holds `activity_log.view`, `guardian` holds `dashboard.view` — so none is
 * introduced here. Each was opened and read rather than waved through:
 *
 *  - the two saved-filter routes write a row scoped to `user_id = $request->user()->id` and the
 *    caller's current school (SavedActivityFilterController :50-56, and destroy() narrows by
 *    school explicitly at :68-71). That is a viewer's own UI state, not domain data.
 *  - `POST /dashboard/refresh` recomputes a cached dashboard analysis and returns a timestamp
 *    (DashboardController::refresh :50-64). It is throttled to 1/minute and mutates no record a
 *    user could otherwise not read.
 *
 * The set is asserted EXACTLY, in both directions. A fourth entry reds because the property broke;
 * a missing entry reds because a route was removed or re-gated and this note has gone stale.
 */
const AV_REVIEWED_WRITE_EXCEPTIONS = [
    'DELETE /api/activity-logs/saved-filters/{savedActivityFilter}',
    'POST /api/activity-logs/saved-filters',
    'POST /dashboard/refresh',
];

/** @return list<string> the abilities admin_viewer holds, read from the seeded database */
function av_heldAbilities(): array
{
    setPermissionsTeamId(null);

    return Role::with('permissions')
        ->where('name', 'admin_viewer')
        ->where('guard_name', 'web')
        ->whereNull('school_id')
        ->firstOrFail()
        ->permissions->pluck('name')->all();
}

it('unlocks no write route beyond the reviewed exceptions, and says what it examined', function () {
    $this->seed(DatabaseSeeder::class);

    $held = av_heldAbilities();
    expect(count($held))->toBeGreaterThan(0, 'an empty grant set would make every arm below vacuous');

    $examined = 0;
    $excluded = 0;
    $unrecognised = [];
    $reached = [];

    foreach (RbacDeriveRouteBaseline::snapshot() as $key => $stack) {
        foreach ($stack as $entry) {
            $prefix = explode(':', $entry, 2)[0];

            if (in_array($entry, AV_NON_AUTHZ, true) || in_array($prefix, AV_NON_AUTHZ, true)) {
                continue;
            }

            if (! in_array($prefix, AV_AUTHZ_PREFIXES, true)) {
                $unrecognised[] = "{$key} :: {$entry}";
            }
        }

        $methods = array_diff(explode('|', explode(' ', $key)[0]), ['HEAD', 'OPTIONS']);

        if ($methods === [] || array_values($methods) === ['GET']) {
            $excluded++;

            continue;
        }

        // `role:` gates read roles, never permissions, and admin_viewer appears in no role list —
        // so such a route is not reachable through anything this role HOLDS.
        if (collect($stack)->contains(fn ($m) => str_starts_with($m, 'role:'))) {
            $excluded++;

            continue;
        }

        $clauses = collect($stack)
            ->filter(fn ($m) => str_starts_with($m, 'permission:'))
            ->map(fn ($m) => explode('|', substr($m, strlen('permission:'))))
            ->values();

        if ($clauses->isEmpty()) {
            // No authorization gate: reachable by every authenticated user. Not unlocked by this
            // role's grants, and narrowing it is a different piece of work.
            $excluded++;

            continue;
        }

        $examined++;

        // Stacked clauses intersect (AND); alternatives within one clause are an OR — exactly how
        // PermissionMiddleware reads them.
        if ($clauses->every(fn (array $any) => array_intersect($any, $held) !== [])) {
            $reached[] = $key;
        }
    }

    sort($reached);

    expect($unrecognised)->toBeEmpty(
        'authorization-shaped middleware this test cannot classify — it would silently shrink the '
        ."denominator, so teach the parser about it rather than letting it pass:\n"
            .implode("\n", $unrecognised),
    );

    expect($examined)->toBeGreaterThan(50, 'a collapsed denominator would make the arm below vacuous');
    expect($excluded)->toBeGreaterThan(0);

    expect($reached)->toEqual(
        collect(AV_REVIEWED_WRITE_EXCEPTIONS)->sort()->values()->all(),
        "admin_viewer reaches a non-GET route that is not in the reviewed set (examined {$examined} "
        ."gated write routes, excluded {$excluded} with reason, 0 unrecognised).\n"
        .'If the new route is genuinely harmless, read it, then add it to '
        .'AV_REVIEWED_WRITE_EXCEPTIONS with the reason — do not widen this assertion.',
    );
});

/**
 * The known-NEGATIVE arm. Without it, a parser that admitted nothing — a typo in the ability
 * lookup, an empty `$held` — would pass the arm above by refusing everything, which is the
 * broken-closed failure a gate cannot distinguish from strictness by looking at its green.
 */
it('does credit admin_viewer with the reads, so the parser is not simply refusing everything', function () {
    $this->seed(DatabaseSeeder::class);

    $held = av_heldAbilities();
    $reachableGets = 0;

    foreach (RbacDeriveRouteBaseline::snapshot() as $key => $stack) {
        if (! str_starts_with($key, 'GET ')) {
            continue;
        }

        $clauses = collect($stack)
            ->filter(fn ($m) => str_starts_with($m, 'permission:'))
            ->map(fn ($m) => explode('|', substr($m, strlen('permission:'))))
            ->values();

        if ($clauses->isNotEmpty() && $clauses->every(fn (array $any) => array_intersect($any, $held) !== [])) {
            $reachableGets++;
        }
    }

    expect($reachableGets)->toBeGreaterThan(20,
        'admin_viewer should reach a substantial set of GET routes; a near-zero count means the '
        .'parser or the grant set is broken, and the write arm above is passing for the wrong reason');
});

/** The exceptions must be real routes — a stale entry would mask a genuine regression forever. */
it('keeps the reviewed exception list honest', function () {
    $live = RbacDeriveRouteBaseline::snapshot();

    $stale = array_values(array_filter(
        AV_REVIEWED_WRITE_EXCEPTIONS,
        fn (string $key) => ! array_key_exists($key, $live),
    ));

    expect($stale)->toBeEmpty(
        'these reviewed exceptions are no longer live routes; a stale entry masks a real regression '
        .'forever, so remove it: '.implode(', ', $stale),
    );
});
