<?php

namespace App\Concerns;

use App\Support\ActiveSchool;
use Illuminate\Database\Eloquent\Builder;

trait HasStaffNumber
{
    protected static function bootHasStaffNumber()
    {
        // Reject duplicate manual entries before insert
        static::creating(function ($model) {
            if (!empty($model->staff_number)) {
                $schoolId = $model->school_id ?: ActiveSchool::id();
                $exists = static::withoutGlobalScopes()
                    ->where('school_id', $schoolId)
                    ->where('staff_number', $model->staff_number)
                    ->exists();
                if ($exists) {
                    throw new \InvalidArgumentException(
                        "Staff number {$model->staff_number} is already in use."
                    );
                }
            }
        });

        // Auto-generate when not provided
        static::created(function ($model) {
            if (empty($model->staff_number)) {
                $staffNumber = $model->nextStaffNumber();

                static::withoutGlobalScopes()
                    ->where('school_id', $model->school_id)
                    ->where('id', $model->id)
                    ->update(['staff_number' => $staffNumber]);

                $model->setAttribute('staff_number', $staffNumber);
                $model->syncOriginalAttribute('staff_number');
            }
        });
    }

    /**
     * Build the next staff number by finding the highest existing
     * suffix for the current prefix and incrementing it.
     */
    protected function nextStaffNumber(): string
    {
        $prefix = static::staffNumberPrefix();

        // Pull the highest existing staff_number that matches this prefix
        $latest = static::withoutGlobalScopes()
            ->where('school_id', $this->school_id)
            ->where('staff_number', 'like', $prefix . '%')
            ->orderByRaw('LENGTH(staff_number) DESC, staff_number DESC')
            ->value('staff_number');

        $lastNumber = 0;
        if ($latest) {
            // Strip the prefix to get just the numeric suffix
            $suffix = substr($latest, strlen($prefix));
            $lastNumber = (int) $suffix;
        }

        return $this->buildStaffNumber($lastNumber + 1);
    }

    /**
     * Build a full staff number from a numeric suffix.
     */
    protected function buildStaffNumber(int $number): string
    {
        return static::staffNumberPrefix() . str_pad($number, 3, '0', STR_PAD_LEFT);
    }

    /**
     * The prefix portion only (e.g. STF/2025/).
     * Override in the model to customize.
     */
    public static function staffNumberPrefix(): string
    {
        return 'STF/' . date('Y') . '/';
    }

    public function scopeByStaffNumber(Builder $query, string $staffNumber): Builder
    {
        return $query->where('staff_number', $staffNumber);
    }
}
