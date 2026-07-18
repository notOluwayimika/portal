<?php

namespace App\Support\Sequences;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Shared Kernel atomic sequence generator (1.4b). Returns the next value for a
 * (scope, key) counter, incremented under a pessimistic row lock so concurrent
 * callers never receive the same value — the fix for the racy read-then-write in
 * the old HasAdmissionNumber/HasStaffNumber generators.
 *
 * Contract:
 *  - GAP-TOLERANT ONLY. A caller whose surrounding transaction rolls back after
 *    calling next() leaves a consumed value (a gap). That is fine for admission
 *    and staff numbers. It is NOT a gap-free ledger sequence: Finance
 *    receipt/invoice numbering (§12.5) is legally gap-free, needs a signed
 *    accounting policy that does not yet exist, and MUST get its own design/ADR —
 *    do not reuse this service on the assumption that "a number generator is a
 *    number generator".
 *  - Atomicity requires a transaction. next() opens its own (DB::transaction), so
 *    it is correct whether or not the caller is already inside one. When the
 *    caller wraps generation + the domain insert in ONE transaction, generation
 *    and insert are fully atomic (the row lock is held to the outer commit); when
 *    it does not, a failed insert simply burns the value (gap-tolerant).
 *  - Shared Kernel: this depends on nothing in any Module and no Module owns it
 *    (ADR 0033 §8.1/§8.2). Consumers (Student/Teacher) depend on it, never the
 *    reverse, and never on each other.
 *
 * @param  Closure(): int|null  $seed  Computes the initial counter value when the
 *                                     (scope, key) row does not yet exist — used to adopt the current domain
 *                                     maximum on first use so the switch from max+1 never collides with
 *                                     existing identifiers. Evaluated at most once per (scope, key).
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
