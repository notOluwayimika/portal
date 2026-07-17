<?php

namespace App\Concerns;

use Illuminate\Support\Str;

trait AddUuid
{
    protected static function bootAddUuid(): void
    {
        // Block closure (NOT an arrow fn): `creating` is a HALTING event
        // (dispatched via until()), so a listener that RETURNS a non-null value
        // stops the rest of the chain. `fn($m) => $m->uuid ??= ...` returns the
        // uuid, which silently halted later creating hooks (BelongsToSchool's
        // school_id auto-fill, HasAdmissionNumber's duplicate check). Returning
        // nothing lets the full chain run. Enforced by bin/ci-boundary-lint.php.
        static::creating(function ($model) {
            $model->uuid ??= (string) Str::orderedUuid();
        });
    }
}
