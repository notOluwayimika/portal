<?php

namespace App\Support\Sequences;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Shared Kernel sequencing primitive: a per-`(scope, key)` monotonic counter.
 * `next()` returns the successor value, incremented under a pessimistic row lock
 * so concurrent callers never receive the same value.
 *
 * This is generic infrastructure — it carries no domain meaning. Any module may
 * use it; it depends on nothing in any module and no module owns it (ADR 0033
 * §8.1/§8.2). It makes no guarantee beyond the two below; a caller needing a
 * stronger property (e.g. strict contiguity) must establish that itself.
 *
 * Guarantees:
 *  - Uniqueness under concurrency. The `SELECT … FOR UPDATE` row lock serialises
 *    concurrent increments of the SAME `(scope, key)`, so no two committed
 *    callers receive the same value. The lock is on that one counter row only —
 *    distinct keys never contend (no global lock).
 *  - Transactional atomicity of allocation with the caller's work. When `next()`
 *    runs inside the caller's transaction, the increment is nested as a savepoint
 *    and the row lock is held until the OUTER transaction commits; if the caller
 *    rolls back, the allocation is rolled back with it and does NOT survive as a
 *    committed value. Called outside any transaction, `next()`'s own transaction
 *    commits the allocation immediately (standalone use).
 *
 * Values are monotonic but not guaranteed contiguous: a value allocated and then
 * abandoned (a committed row later deleted) is not reclaimed.
 *
 * @param  Closure(): int|null  $seed  Initial value when the `(scope, key)` row
 *                                     does not yet exist — used to adopt an existing maximum on first use so a
 *                                     switch onto this counter never reissues a value already in use.
 *                                     Evaluated at most once per `(scope, key)`.
 */
class Sequences
{
    public static function next(string $scope, string $key, ?Closure $seed = null): int
    {
        return DB::transaction(function () use ($scope, $key, $seed) {
            // Initialise the counter on first use only (adopting the domain max),
            // so pre-existing identifiers are never reissued. insertOrIgnore is
            // atomic on the unique (scope, key), so a concurrent first-use race
            // resolves to a single row.
            $exists = DB::table('sequences')->where('scope', $scope)->where('key', $key)->exists();
            if (! $exists) {
                DB::table('sequences')->insertOrIgnore([
                    'scope' => $scope,
                    'key' => $key,
                    'value' => $seed ? (int) $seed() : 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Serialise concurrent increments on the counter row.
            $current = (int) DB::table('sequences')
                ->where('scope', $scope)->where('key', $key)
                ->lockForUpdate()
                ->value('value');

            $next = $current + 1;

            DB::table('sequences')
                ->where('scope', $scope)->where('key', $key)
                ->update(['value' => $next, 'updated_at' => now()]);

            return $next;
        });
    }
}
