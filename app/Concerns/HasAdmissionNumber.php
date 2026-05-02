<?php

namespace App\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait HasAdmissionNumber
{
    /**
     * Boot the trait and add creating event
     */
    protected static function bootHasAdmissionNumber()
    {
        static::created(function ($model) {
            if (empty($model->admission_number)) {
                $postfixNumber = str_pad($model->id, 3, '0', STR_PAD_LEFT);
                $admissionNumber = "{$model->generateAdmissionNumber()}/{$postfixNumber}";

                static::where('id', $model->id)
                    ->update(['admission_number' => $admissionNumber]);

                // Sync current instance
                $model->setAttribute('admission_number', $admissionNumber);
                $model->syncOriginalAttribute('admission_number');
            }
        });
    }

    /**
     * Generate a new staff number
     */
    public static function generateAdmissionNumber(): string
    {
        // GFA/2025
        $prefix = 'GFA';
        $year = date('Y');

        return "{$prefix}/{$year}";
    }

    /**
     * Scope to find by staff number
     */
    public function scopeByAdmissionNumber(Builder $query, string $admissionNumber): ?self
    {
        return $query->where('admission_number', $admissionNumber)->first();
    }
}
