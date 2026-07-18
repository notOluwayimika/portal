<?php

namespace App\Concerns;

use App\Support\ActiveSchool;
use App\Support\Sequences\Sequences;
use Illuminate\Database\Eloquent\Builder;

trait HasStaffNumber
{
    protected static function bootHasStaffNumber(): void
    {
        // Generate BEFORE insert (in `creating`, not `created`) so the number is
        // part of the atomic INSERT — closes the null-number window and uses the
        // atomic Sequences counter so concurrent creates never collide (1.4b).
        static::creating(function ($model) {
            $schoolId = $model->school_id ?: ActiveSchool::id();

            if (! empty($model->staff_number)) {
                // Manual entry — reject a duplicate up front (defence in depth;
                // the composite UNIQUE(school_id, staff_number) index is the
                // actual guarantee).
                $exists = static::withoutGlobalScopes()
                    ->where('school_id', $schoolId)
                    ->where('staff_number', $model->staff_number)
                    ->exists();

                if ($exists) {
                    throw new \InvalidArgumentException(
                        "Staff number {$model->staff_number} is already in use."
                    );
                }

                return;
            }

            $prefix = static::staffNumberPrefix();
            $next = Sequences::next(
                'teacher.staff_number',
                $schoolId.'|'.$prefix,
                fn () => static::currentStaffSuffixMax($schoolId, $prefix),
            );

            $model->staff_number = static::buildStaffNumber($next);
        });
    }

    /**
     * The current highest numeric suffix for this School + prefix, used to seed
     * the sequence on first use so the switch from the old max+1 scheme never
     * reissues an existing staff number.
     */
    protected static function currentStaffSuffixMax(int|string|null $schoolId, string $prefix): int
    {
        $latest = static::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('staff_number', 'like', $prefix.'%')
            ->orderByRaw('LENGTH(staff_number) DESC, staff_number DESC')
            ->value('staff_number');

        return $latest ? (int) substr($latest, strlen($prefix)) : 0;
    }

    protected static function buildStaffNumber(int $number): string
    {
        return static::staffNumberPrefix().str_pad((string) $number, 3, '0', STR_PAD_LEFT);
    }

    /**
     * The prefix portion only (e.g. STF/2025/). Override in the model to customise.
     */
    public static function staffNumberPrefix(): string
    {
        return 'STF/'.date('Y').'/';
    }

    public function scopeByStaffNumber(Builder $query, string $staffNumber): Builder
    {
        return $query->where('staff_number', $staffNumber);
    }
}
