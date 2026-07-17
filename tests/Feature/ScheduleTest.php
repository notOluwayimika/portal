<?php

use Illuminate\Console\Scheduling\Schedule;

/**
 * Locks the codebase's scheduling registration point (routes/console.php) and
 * the first scheduled task. Phase 2+ adds School-scoped scheduled work (revenue
 * recognition, dunning, ledger drift verification) on top of this point.
 */
it('schedules authz:prune daily', function () {
    $events = app(Schedule::class)->events();

    $prune = collect($events)->first(fn ($e) => str_contains($e->command ?? '', 'authz:prune'));

    expect($prune)->not->toBeNull()
        ->and($prune->expression)->toBe('0 0 * * *'); // daily
});
