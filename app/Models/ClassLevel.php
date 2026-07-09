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
    protected $fillable = ['school_id', 'name', 'order', 'level_type'];

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

    public function arms(): BelongsToMany
    {
        return $this->belongsToMany(Arm::class, 'class_level_arms');
    }

    public function classLevelArms(): HasMany
    {
        return $this->hasMany(ClassLevelArm::class);
    }

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
