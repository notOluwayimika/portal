<?php

namespace App\Concerns;

use Illuminate\Support\Str;

trait AddUuid
{
    protected static function bootAddUuid(): void
    {
        static::creating(fn($model) => $model->uuid ??= (string) Str::orderedUuid());
    }
}
