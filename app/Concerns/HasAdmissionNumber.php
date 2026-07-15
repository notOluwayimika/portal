<?php

namespace App\Concerns;

use App\Support\ActiveSchool;
use Illuminate\Database\Eloquent\Builder;

trait HasAdmissionNumber
{
    protected static function bootHasAdmissionNumber()
    {
        // Reject duplicate manual entries before insert
        static::creating(function ($model) {
            if (!empty($model->admission_number)) {
                $schoolId = $model->school_id ?: ActiveSchool::id();
                $exists = static::withoutGlobalScopes()
                    ->where('school_id', $schoolId)
                    ->where('admission_number', $model->admission_number)
                    ->exists();
                if ($exists) {
                    throw new \InvalidArgumentException(
                        "Admission number {$model->admission_number} is already in use."
                    );
                }
            }
        });

        // Auto-generate when not provided
        static::created(function ($model) {
            if (empty($model->admission_number)) {
                $admissionNumber = $model->nextAdmissionNumber();

                static::withoutGlobalScopes()
                    ->where('school_id', $model->school_id)
                    ->where('id', $model->id)
                    ->update(['admission_number' => $admissionNumber]);

                $model->setAttribute('admission_number', $admissionNumber);
                $model->syncOriginalAttribute('admission_number');
            }
        });
    }

    /**
     * Build the next admission number by finding the highest existing
     * suffix for the current prefix and incrementing it.
     */
    protected function nextAdmissionNumber(): string
    {
        $prefix = static::admissionNumberPrefix();

        $latest = static::withoutGlobalScopes()
            ->where('school_id', $this->school_id)
            ->where('admission_number', 'like', $prefix . '%')
            ->orderByRaw('LENGTH(admission_number) DESC, admission_number DESC')
            ->value('admission_number');

        $lastNumber = 0;
        if ($latest) {
            $suffix = substr($latest, strlen($prefix));
            $lastNumber = (int) $suffix;
        }

        return $this->buildAdmissionNumber($lastNumber + 1);
    }

    /**
     * Build a full admission number from a numeric suffix.
     */
    protected function buildAdmissionNumber(int $number): string
    {
        return static::admissionNumberPrefix() . str_pad($number, 3, '0', STR_PAD_LEFT);
    }

    /**
     * The prefix portion only (e.g. GFA/2025/).
     * Override in the model to customize.
     */
    public static function admissionNumberPrefix(): string
    {
        return 'GFA/' . date('Y') . '/';
    }

    /**
     * Proper scope — returns the builder so it stays chainable.
     */
    public function scopeByAdmissionNumber(Builder $query, string $admissionNumber): Builder
    {
        return $query->where('admission_number', $admissionNumber);
    }
}
