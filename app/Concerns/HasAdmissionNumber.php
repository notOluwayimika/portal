<?php

namespace App\Concerns;

use App\Support\ActiveSchool;
use App\Support\Sequences\Sequences;
use Illuminate\Database\Eloquent\Builder;

trait HasAdmissionNumber
{
    protected static function bootHasAdmissionNumber(): void
    {
        // Generate BEFORE insert (in `creating`, not `created`) so the number is
        // part of the atomic INSERT — this closes the null-number window the old
        // post-insert UPDATE left open, and the value comes from the atomic
        // Sequences counter so concurrent creates never collide (1.4b).
        static::creating(function ($model) {
            $schoolId = $model->school_id ?: ActiveSchool::id();

            if (! empty($model->admission_number)) {
                // Manual entry — reject a duplicate up front (defence in depth;
                // the composite UNIQUE(school_id, admission_number) index is the
                // actual guarantee).
                $exists = static::withoutGlobalScopes()
                    ->where('school_id', $schoolId)
                    ->where('admission_number', $model->admission_number)
                    ->exists();

                if ($exists) {
                    throw new \InvalidArgumentException(
                        "Admission number {$model->admission_number} is already in use."
                    );
                }

                return;
            }

            $prefix = static::admissionNumberPrefix();
            $next = Sequences::next(
                'student.admission_number',
                $schoolId.'|'.$prefix,
                fn () => static::currentAdmissionSuffixMax($schoolId, $prefix),
            );

            $model->admission_number = static::buildAdmissionNumber($next);
        });
    }

    /**
     * The current highest numeric suffix for this School + prefix, used to seed
     * the sequence on first use so the switch from the old max+1 scheme never
     * reissues an existing admission number.
     */
    protected static function currentAdmissionSuffixMax(int|string|null $schoolId, string $prefix): int
    {
        $latest = static::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('admission_number', 'like', $prefix.'%')
            ->orderByRaw('LENGTH(admission_number) DESC, admission_number DESC')
            ->value('admission_number');

        return $latest ? (int) substr($latest, strlen($prefix)) : 0;
    }

    protected static function buildAdmissionNumber(int $number): string
    {
        return static::admissionNumberPrefix().str_pad((string) $number, 3, '0', STR_PAD_LEFT);
    }

    /**
     * The prefix portion only (e.g. GFA/2025/). Override in the model to customise.
     */
    public static function admissionNumberPrefix(): string
    {
        return 'GFA/'.date('Y').'/';
    }

    public function scopeByAdmissionNumber(Builder $query, string $admissionNumber): Builder
    {
        return $query->where('admission_number', $admissionNumber);
    }
}
