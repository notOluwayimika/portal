<?php

namespace App\Finance\Exceptions;

use RuntimeException;

/**
 * Defense-in-depth companion to the BEFORE UPDATE/DELETE database triggers on the
 * subledger (the 1.4c immutability pattern). The triggers are the load-bearing
 * guarantee — they hold against raw DB writes, tinker, and mass deletes — but a
 * model-level guard fails fast with a clear message on the ordinary Eloquent path.
 */
final class LedgerImmutableException extends RuntimeException
{
    public function __construct(string $operation)
    {
        parent::__construct(
            "Subledger rows are append-only (Constitution §15C / Engineering Invariant 9): {$operation} is denied. Corrections are reversing entries."
        );
    }
}
