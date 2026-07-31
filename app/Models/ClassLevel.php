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

class ClassLevel extends Model
{
    use LogsActivity;

    protected $fillable = ['school_id', 'name', 'order', 'level_type', 'grading_scheme_id'];

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

    protected static $logName = 'academics';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'order'])
            ->logOnlyDirty();
    }
}
