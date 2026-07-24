<?php

use App\Models\School;
use App\Models\User;
use App\Support\RouteAccessMap;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The C2 parity contract: the role:→permission: swap changed the MECHANISM of
 * route authorization, not its OUTCOME. tests/fixtures/route-access-map.json
 * was derived and committed BEFORE the swap (from the role: groups); this test
 * re-derives the same map from the live permission:-based routes plus the
 * seeded grants and demands equality, route by route, role by role.
 *
 * Deliberate asymmetry (same as RouteMiddlewareBaselineTest): only fixture
 * routes are asserted, so NEW routes — Finance additions included — are never
 * blocked here. An access change to an EXISTING route stays red until the
 * fixture is regenerated (`php artisan rbac:derive-access` against a synced
 * DB) as an explicit, reviewed diff.
 */

// C2's single declared access deviation. Every other route's role set is
// asserted identical to the pre-swap fixture.
const ACCESS_DEVIATIONS = [
    // Was inside the admin|head_of_school|form_teacher group, locking every
    // other role out of ending its own session; now plain auth:sanctum.
    'POST /api/logout' => [
        'admin', 'boarding_parent', 'form_teacher', 'guardian', 'head_of_school',
        'principal', 'registrar', 'super_admin', 'teacher',
    ],
];

it('preserves the pre-swap allowed-role set for every baselined route', function () {
    $this->seed(DatabaseSeeder::class);

    $live = RouteAccessMap::derive();
    $fixture = json_decode(
        file_get_contents(base_path('tests/fixtures/route-access-map.json')),
        true,
    );

    $problems = [];

    foreach ($fixture as $key => $expected) {
        if (! array_key_exists($key, $live)) {
            $problems[] = "REMOVED/RENAMED: {$key}";

            continue;
        }

        $wantRoles = ACCESS_DEVIATIONS[$key] ?? $expected['roles'];

        if ($live[$key]['roles'] !== $wantRoles || $live[$key]['auth'] !== $expected['auth']) {
            $problems[] = "ACCESS CHANGED: {$key}\n"
                .'    expected: ['.implode(', ', $wantRoles).'] auth='.var_export($expected['auth'], true)."\n"
                .'    live:     ['.implode(', ', $live[$key]['roles']).'] auth='.var_export($live[$key]['auth'], true);
        }
    }

    expect($problems)->toBeEmpty(
        "Per-role route access drifted from the pre-swap oracle — if intended, regenerate via `php artisan rbac:derive-access` and review the diff:\n"
            .implode("\n", $problems),
    );
});

it('keeps the deviation list honest — each entry differs from the fixture', function () {
    $fixture = json_decode(
        file_get_contents(base_path('tests/fixtures/route-access-map.json')),
        true,
    );

    foreach (ACCESS_DEVIATIONS as $key => $roles) {
        expect($fixture)->toHaveKey($key);
        expect($roles)->not->toEqual(
            $fixture[$key]['roles'],
            "'{$key}' is listed as a deviation but matches the fixture — stale entry, remove it.",
        );
    }
});

// ---------------------------------------------------------------------------
// Live per-role HTTP smokes: the static map above proves the middleware
// EXPRESSIONS are equivalent; these prove the wired middleware actually
// admits/denies real authenticated requests (team context, guard resolution,
// Gate::before composition — the parts a static derivation could get wrong).
// ---------------------------------------------------------------------------

function rap_userWithRole(string $role): User
{
    $user = singleSchoolUser();
    $school = School::findOrFail($user->school_id);

    if ($role !== 'admin') {
        // singleSchoolUser grants 'admin'; replace it with the target role.
        setPermissionsTeamId($school->id);
        $user->syncRoles([$role]);
        setPermissionsTeamId(null);
        $user->flushSchoolAccessCache();
    }

    return $user;
}

dataset('route smokes', [
    // [role, method, uri, expected status]
    'principal reads student index' => ['principal', 'get', '/students', 200],
    'teacher denied student index' => ['teacher', 'get', '/students', 403],
    'guardian reaches parent portal' => ['guardian', 'get', '/parent/wards', 200],
    'admin reaches principal setup' => ['admin', 'get', '/setup/principals', 200],
    'teacher denied principal setup' => ['teacher', 'get', '/setup/principals', 403],
    'head manages result signature' => ['head_of_school', 'get', '/result-signature', 200],
    'admin denied result signature (pre-swap oddity preserved)' => ['admin', 'get', '/result-signature', 403],
    'admin denied super-admin area (role:super_admin untouched)' => ['admin', 'get', '/super-admin/schools', 403],
    'boarding parent reaches assessments page' => ['boarding_parent', 'get', '/boarding-parent/behavioral-assessments', 200],
    'form teacher reads own api surface' => ['form_teacher', 'get', '/api/form-teacher/students', 200],
    'teacher denied form-teacher api' => ['teacher', 'get', '/api/form-teacher/students', 403],
    'guardian reads own notices api' => ['guardian', 'get', '/api/guardian/notices', 200],
    'teacher denied guardian notices api' => ['teacher', 'get', '/api/guardian/notices', 403],
    'teacher denied finance api' => ['teacher', 'post', '/api/v1/finance/invoices', 403],
    'guardian can log out (declared deviation)' => ['guardian', 'post', '/api/logout', 200],
]);

it('admits and denies real requests per role', function (string $role, string $method, string $uri, int $status) {
    $this->seed(DatabaseSeeder::class);

    $response = $this->actingAs(rap_userWithRole($role))->{$method}($uri);

    expect($response->status())->toBe(
        $status,
        "{$role} {$method} {$uri}: expected {$status}, got {$response->status()}",
    );
})->with('route smokes');
