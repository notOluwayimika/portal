<?php
// app/Models/GradeBoundary.php

namespace App\Models;

use App\Models\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class GradeBoundary extends Model
{
    use LogsActivity;
    protected $fillable = ['school_id', 'exam_type_id', 'min_score', 'max_score', 'grade', 'label', 'grade_point'];

    protected $casts = [
        'min_score' => 'decimal:2',
        'max_score' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new SchoolScope());
        static::creating(fn($model) => $model->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName()
    {
        return 'uuid';
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
    public function examType(): BelongsTo
    {
        return $this->belongsTo(ExamType::class);
    }

    /**
     * Resolve grade for a total score.
     * Prefers exam_type-specific boundary over school default.
     */
    public static function resolveGrade(string $schoolId, ?string $examTypeId, float $totalScore): ?string
    {
        return static::where('school_id', $schoolId)
            ->where(fn($q) => $q->where('exam_type_id', $examTypeId)->orWhereNull('exam_type_id'))
            ->where('min_score', '<=', $totalScore)
            ->where('max_score', '>', $totalScore)
            ->orderByRaw('exam_type_id IS NULL ASC') // exam-type-specific first
            ->value('grade');
    }

    protected static $logName = 'academics';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['min_score', 'max_score', 'grade', 'grade_point', 'label'])
            ->logOnlyDirty();
    }
}
