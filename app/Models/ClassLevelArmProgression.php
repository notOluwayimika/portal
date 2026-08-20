<?php

namespace App\Models;

use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * The EXPLICIT source-arm -> target-arm map for end-of-year promotion.
 *
 * Consulted FIRST, before any label matching, so a school can always override the automatic answer.
 * UNIQUE on the source arm, so the map can never answer a question twice.
 *
 * WHY LABEL MATCHING ALONE IS NOT ENOUGH (the fallback this map overrides). Matching 7B -> 8B by
 * label assumes a label identifies one arm within a class level. `class_level_arms` is UNIQUE on
 * (class_level_id, arm_id, stream_id), so arm "B" may legitimately appear more than once in a class
 * level, differing only by stream. Every stream_id is NULL across both schools today, so label
 * matching happens to be unambiguous and will remain so until the first school configures streams —
 * a latent ambiguity, not a live bug. Resolution belongs to the job; this map is the escape hatch
 * that lets a school answer it by hand in the meantime.
 */
class ClassLevelArmProgression extends Model
{
    use AddUuid, BelongsToSchool, LogsActivity;

    protected $fillable = [
        'school_id',
        'source_class_level_arm_id',
        'target_class_level_arm_id',
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

    /** @return BelongsTo<ClassLevelArm, $this> */
    public function sourceClassLevelArm(): BelongsTo
    {
        return $this->belongsTo(ClassLevelArm::class, 'source_class_level_arm_id');
    }

    /** @return BelongsTo<ClassLevelArm, $this> */
    public function targetClassLevelArm(): BelongsTo
    {
        return $this->belongsTo(ClassLevelArm::class, 'target_class_level_arm_id');
    }

    protected static $logName = 'academics';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['source_class_level_arm_id', 'target_class_level_arm_id'])
            ->logOnlyDirty();
    }
}
