<?php

namespace App\Finance\Models\Concerns;

use App\Finance\Exceptions\LedgerImmutableException;

/**
 * Model-level defense-in-depth for append-only Finance tables. The DB triggers
 * (1.4c pattern) are the real guarantee; this fails fast on the Eloquent path with
 * a clear message. Block closures (not arrow fns) because updating/deleting are
 * halting events — enforced by bin/ci-boundary-lint.php.
 */
trait AppendOnly
{
    protected static function bootAppendOnly(): void
    {
        static::updating(function () {
            throw new LedgerImmutableException('UPDATE');
        });
        static::deleting(function () {
            throw new LedgerImmutableException('DELETE');
        });
    }
}
