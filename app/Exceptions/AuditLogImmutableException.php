<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when code attempts to UPDATE or DELETE a row in the audit log
 * (activity_log). The audit log is append-only and permanent (Constitution
 * §15C): no record may ever be edited or deleted by any user. Enforced in depth —
 * this model-level guard, plus BEFORE UPDATE/DELETE database triggers that deny
 * the same at the storage layer for raw/mass writes that never touch the model.
 */
class AuditLogImmutableException extends RuntimeException
{
    public function __construct(string $operation)
    {
        parent::__construct(
            "The audit log (activity_log) is append-only and immutable (§15C): {$operation} is denied. "
            .'No audit record may be edited or deleted.'
        );
    }
}
