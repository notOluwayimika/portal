<?php

namespace App\Concerns;

use App\Models\Scopes\SchoolScope;

trait BelongsToSchool
{
     protected static function bootBelongsToSchool(): void
    {
        static::addGlobalScope(new SchoolScope());

        static::creating(function ($model) {
            if (auth()->check() &&
                \Illuminate\Support\Facades\Schema::hasColumn($model->getTable(), 'school_id') &&
                !$model->school_id) {
                $model->school_id = session('school_id') ?? auth()->user()->school_id;
            }
        });
    }
}
