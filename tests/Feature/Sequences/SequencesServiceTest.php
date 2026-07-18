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
