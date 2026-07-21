<?php

use App\Console\Commands\RbacDeriveRouteBaseline;

/**
 * The pre-swap route oracle (C1, brief D5). The fixture snapshots every
 * route's FULL middleware stack in order, so the coming role:→permission:
 * swap is a reviewable per-route diff and an unintended change to a second
 * route cannot ride along invisibly. Complementary to
 * AuthorizationOrderingTest (which fixes the ADR 0043 §3 ordering contract);
 * this pins the concrete per-route stacks.
 *
 * Deliberate asymmetry for parallel work: a NEW route is allowed without
 * touching the fixture as long as it carries an auth middleware — Finance
 * route additions never go red here. A new UNGUARDED route fails until it is
 * added to the fixture as an explicit, reviewed entry (regenerate via
 * `php artisan rbac:derive-map`).
 */
it('matches the committed middleware stack, in order, for every baselined route', function () {
    $live = RbacDeriveRouteBaseline::snapshot();
    $fixture = json_decode(
        file_get_contents(base_path('tests/fixtures/route-middleware-baseline.json')),
        true,
    );

    $problems = [];

    foreach ($fixture as $key => $stack) {
        if (! array_key_exists($key, $live)) {
            $problems[] = "REMOVED/RENAMED: {$key}";

            continue;
        }

        if ($live[$key] !== $stack) {
            $problems[] = "CHANGED: {$key}\n    fixture: [".implode(', ', $stack)."]\n    live:    [".implode(', ', $live[$key]).']';
        }
    }

    expect($problems)->toBeEmpty(
        "Route middleware drifted from the reviewed baseline — if intended, regenerate via `php artisan rbac:derive-map`:\n"
            .implode("\n", $problems),
    );
});

it('rejects new routes that carry no auth middleware (guarded additions pass freely)', function () {
    $live = RbacDeriveRouteBaseline::snapshot();
    $fixture = json_decode(
        file_get_contents(base_path('tests/fixtures/route-middleware-baseline.json')),
        true,
    );

    $unguardedNew = collect($live)
        ->reject(fn ($stack, $key) => array_key_exists($key, $fixture))
        ->reject(fn ($stack) => collect($stack)->contains(fn ($m) => str_starts_with($m, 'auth')))
        ->keys()
        ->all();

    expect($unguardedNew)->toBeEmpty(
        'New routes with NO auth middleware — guard them, or add them to the fixture as explicit reviewed entries: '
            .implode(' · ', $unguardedNew),
    );
});
