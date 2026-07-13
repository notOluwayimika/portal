<?php

namespace App\Concerns;

use App\Models\Scopes\SchoolScope;
use App\Support\ActiveSchool;
use Illuminate\Support\Facades\Schema;

trait BelongsToSchool
{
     protected static function bootBelongsToSchool(): void
    {
        static::addGlobalScope(new SchoolScope());

        static::creating(function ($model) {
            if (auth()->check() &&
                Schema::hasColumn($model->getTable(), 'school_id') &&
                !$model->school_id
            ) {
                $model->school_id = ActiveSchool::id();
            }
        });
    }
}
