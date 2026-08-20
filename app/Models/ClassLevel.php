<?php

// app/Models/ClassLevel.php

namespace App\Models;

use App\Models\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property string $uuid
 */
class ClassLevel extends Model
{
    use LogsActivity;

    protected $fillable = [
        'school_id',
        'name',
        'order',
        'level_type',
        'grading_scheme_id',
        'next_class_level_id',
        'default_exam_type_id',
        'arm_distribution_strategy',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new SchoolScope);
        static::creating(function ($model) {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName()
    {
        return 'uuid';
    }

    /** @return BelongsTo<School, $this> */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /** @return BelongsTo<GradingScheme, $this> */
    public function gradingScheme(): BelongsTo
    {
        return $this->belongsTo(GradingScheme::class);
    }

    /** @return BelongsToMany<Arm, $this> */
    public function arms(): BelongsToMany
    {
        return $this->belongsToMany(Arm::class, 'class_level_arms');
    }

    /** @return HasMany<ClassLevelArm, $this> */
    public function classLevelArms(): HasMany
    {
        return $this->hasMany(ClassLevelArm::class);
    }

    /** @return HasMany<Stream, $this> */
    public function streams(): HasMany
    {
        return $this->hasMany(Stream::class);
    }

    /**
     * End-of-year progression target. NULL is meaningful and is the terminal/graduating year — nobody
     * is promoted out of it. Explicit rather than `order + 1`: `order` is a sort field with no
     * uniqueness or contiguity guarantee, so arithmetic would silently jump a deleted level.
     *
     * @return BelongsTo<ClassLevel, $this>
     */
    public function nextClassLevel(): BelongsTo
    {
        return $this->belongsTo(ClassLevel::class, 'next_class_level_id');
    }

    /**
     * Fallback exam type when the pupil's current one is not in this level's set — see examTypes().
     *
     * @return BelongsTo<ExamType, $this>
     */
    public function defaultExamType(): BelongsTo
    {
        return $this->belongsTo(ExamType::class, 'default_exam_type_id');
    }

    /**
     * Which term SLOTS this level runs, and whether each is CCM. No row for a slot means the level
     * does not run it, and the end-of-term job no-ops.
     *
     * @return HasMany<ClassLevelTermParticipation, $this>
     */
    public function termParticipation(): HasMany
    {
        return $this->hasMany(ClassLevelTermParticipation::class);
    }

    /**
     * The SET of exam types this level runs — several is normal (Year 10 runs both BSS and WAEC in
     * school 1 today), which is why this is a relation and not a column.
     *
     * @return HasMany<ClassLevelExamType, $this>
     */
    public function examTypes(): HasMany
    {
        return $this->hasMany(ClassLevelExamType::class);
    }

    protected static $logName = 'academics';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'order'])
            ->logOnlyDirty();
    }
}
