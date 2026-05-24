<?php
// app/Models/MarkingComponent.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class MarkingComponent extends Model
{
    use LogsActivity;
    protected $fillable = ['curriculum_subject_id', 'name', 'weight', 'school_id'];

    protected $casts = ['weight' => 'decimal:3'];

    protected static function booted(): void
    {
        static::creating(fn($model) => $model->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName()
    {
        return 'uuid';
    }

    public function curriculumSubject(): BelongsTo
    {
        return $this->belongsTo(CurriculumSubject::class);
    }
    public function scores(): HasMany
    {
        return $this->hasMany(Score::class);
    }

    public function scopeGlobal($query)
    {
        return $query->whereNull('curriculum_subject_id');
    }

    protected static $logName = 'setup';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'weight'])
            ->logOnlyDirty();
    }
}
