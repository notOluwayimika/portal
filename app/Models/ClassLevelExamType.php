<?php

namespace App\Models;

use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * The SET of exam types a class level runs.
 *
 * Set-valued because the data is: in school 1 today, Year 10 and Year 11 each run both BSS Grading
 * and WAEC Grading, while Year 12 runs WAEC alone. A single `exam_type_id` column on class_levels
 * could not express that, which is why this table exists rather than a column.
 *
 * Read together with `class_levels.default_exam_type_id`: at end of year, carry the pupil's current
 * exam type if the target class level runs it (a row here), otherwise fall back to the target's
 * default. The fallback is the case membership alone cannot answer — a Year 11 BSS pupil moving into
 * a Year 12 that has no BSS.
 */
class ClassLevelExamType extends Model
{
    use AddUuid, BelongsToSchool, LogsActivity;

    protected $fillable = [
        'school_id',
        'class_level_id',
        'exam_type_id',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<School, $this> */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /** @return BelongsTo<ClassLevel, $this> */
    public function classLevel(): BelongsTo
    {
        return $this->belongsTo(ClassLevel::class);
    }

    /** @return BelongsTo<ExamType, $this> */
    public function examType(): BelongsTo
    {
        return $this->belongsTo(ExamType::class);
    }

    protected static $logName = 'academics';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['class_level_id', 'exam_type_id'])
            ->logOnlyDirty();
    }
}
