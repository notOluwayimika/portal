<?php

use App\Support\Sequences\Sequences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * App\Support\Sequences\Sequences — the Shared Kernel atomic counter. MySQL.
 */
uses(RefreshDatabase::class);

it('increments per (scope, key) and isolates different keys', function () {
    expect(Sequences::next('s', 'a'))->toBe(1)
        ->and(Sequences::next('s', 'a'))->toBe(2)
        ->and(Sequences::next('s', 'a'))->toBe(3)
        ->and(Sequences::next('s', 'b'))->toBe(1)   // different key → own counter
        ->and(Sequences::next('t', 'a'))->toBe(1);  // different scope → own counter
});

it('seeds from the domain max on FIRST use only, then ignores the seed', function () {
    // First call adopts the seed (e.g. existing max 41 → returns 42).
    expect(Sequences::next('scope', 'k', fn () => 41))->toBe(42);
    // Subsequent calls increment the stored value and never re-read the seed,
    // even if the seed would now report something different.
    expect(Sequences::next('scope', 'k', fn () => 999))->toBe(43)
        ->and(Sequences::next('scope', 'k', fn () => 999))->toBe(44);
});

it('never returns a duplicate across many rapid calls (deterministic reproduction)', function () {
    // NOTE: this is a DETERMINISTIC reproduction, not OS-level parallelism. It
    // drives the same atomic path a concurrent burst would; true parallel safety
    // rests on the SELECT … FOR UPDATE row lock exercised here (each call opens a
    // transaction, locks the counter row, increments, commits). Under real
    // concurrency that lock serialises callers, yielding the same distinct,
    // contiguous values this asserts.
    $values = collect(range(1, 200))->map(fn () => Sequences::next('load', 'k'));

    expect($values->unique())->toHaveCount(200)         // zero duplicates
        ->and($values->min())->toBe(1)
        ->and($values->max())->toBe(200)                // contiguous 1..200
        ->and(DB::table('sequences')->where('scope', 'load')->where('key', 'k')->value('value'))->toBe(200);
});

it('concurrent FIRST-use cannot double-seed — UNIQUE(scope,key) index + INSERT IGNORE', function () {
    // First-use is a DISTINCT guarantee from steady-state. Steady-state is
    // protected by SELECT … FOR UPDATE on the existing counter row; but on first
    // use no row exists, and FOR UPDATE on a non-existent row locks nothing. What
    // serialises two concurrent first callers is the UNIQUE (scope, key) index
    // together with INSERT IGNORE (insertOrIgnore): exactly one seed row can
    // exist, the second insert is silently ignored — no exception, so no retry
    // path that could "work by luck".
    //
    // This is a DETERMINISTIC reproduction of that interleaving (both callers
    // observed exists()=false and both seed). The asserted guarantee is the
    // index/IGNORE behaviour, not observed OS parallelism; under real concurrency
    // InnoDB makes the second INSERT IGNORE wait on the first's uncommitted row,
    // then ignore it.
    $scope = 'firstuse';
    $key = 'school|GFA/';
    $n = 5; // domain max (deploy-day: existing records up to 5, no counter row)

    $seed = fn () => DB::table('sequences')->insertOrIgnore([
        'scope' => $scope, 'key' => $key, 'value' => $n,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $seed(); // caller A seeds the row at N
    $seed(); // caller B — the UNIQUE index makes INSERT IGNORE a no-op (no throw, no reset)

    expect(DB::table('sequences')->where('scope', $scope)->where('key', $key)->count())->toBe(1)  // exactly one row
        ->and((int) DB::table('sequences')->where('scope', $scope)->where('key', $key)->value('value'))->toBe($n); // seeded at N, not reset

    // Now both callers proceed to the increment; the FOR UPDATE lock on the
    // (now-existing) row serialises them → unique N+1 and N+2, never two of N+1.
    $a = Sequences::next($scope, $key, fn () => $n);
    $b = Sequences::next($scope, $key, fn () => $n);

    expect([$a, $b])->toBe([$n + 1, $n + 2]);
});

it('a duplicate INSERT IGNORE neither throws nor resets the counter', function () {
    DB::table('sequences')->insertOrIgnore(['scope' => 's', 'key' => 'k', 'value' => 9, 'created_at' => now(), 'updated_at' => now()]);
    // Second seed attempt with a DIFFERENT value must be ignored, not applied.
    DB::table('sequences')->insertOrIgnore(['scope' => 's', 'key' => 'k', 'value' => 0, 'created_at' => now(), 'updated_at' => now()]);

    expect((int) DB::table('sequences')->where('scope', 's')->where('key', 'k')->value('value'))->toBe(9);
});

it('holds the counter row under a lock during increment (FOR UPDATE present)', function () {
    // Assert the mechanism, not just the outcome: the increment path issues a
    // locking read so concurrent transactions serialise rather than both reading
    // the same value (the defect the old max+1 generator had).
    $sawLock = false;
    DB::listen(function ($q) use (&$sawLock) {
        if (str_contains(strtolower($q->sql), 'for update') && str_contains($q->sql, 'sequences')) {
            $sawLock = true;
        }
    });

    Sequences::next('lockcheck', 'k');

    expect($sawLock)->toBeTrue();
});
