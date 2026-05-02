<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class SchoolScope implements Scope
{
    private static $isApplying = false;

    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        if (static::$isApplying) return;

        static::$isApplying = true;

        try {
            if (auth()->check()) {
                /** @var \App\Models\User $user */
                $user = auth()->user();

                if ($user->isSuperAdmin()) {
                    return;
                }

                $schoolId = session('school_id') ?? ($user?->school_id ?? null);

                if ($schoolId) {
                    // Only apply if the table actually has the school_id column
                    $builder->where($model->getTable() . '.school_id', $schoolId);
                }
            }
        } catch (\Throwable $th) {
            \Illuminate\Support\Facades\Log::error('SchoolScope error: ' . $th->getMessage());
        } finally {
            static::$isApplying = false;
        }
    }
}
