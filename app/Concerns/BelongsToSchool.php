<?php

namespace App\Concerns;

use App\Models\Scopes\SchoolScope;
use App\Support\ActiveSchool;
use Illuminate\Support\Facades\Schema;

trait BelongsToSchool
{
    protected static function bootBelongsToSchool(): void
    {
        static::addGlobalScope(new SchoolScope);

        static::creating(function ($model) {
            // Fill from the active School context wherever one exists: request
            // (session/token/auth) or off-request via ActiveSchool::runFor()
            // (SchoolAware jobs). Jobs no longer impersonate a causer, so the
            // fill must not be gated on auth()->check(); with no context at
            // all, ActiveSchool::id() is null and nothing is filled (unchanged).
            if (Schema::hasColumn($model->getTable(), 'school_id') &&
                ! $model->school_id &&
                ($schoolId = ActiveSchool::id())
            ) {
                $model->school_id = $schoolId;
            }
        });
    }
}
