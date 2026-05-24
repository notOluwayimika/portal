<?php
// app/Models/Subject.php

namespace App\Models;

use App\Models\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Subject extends Model
{
    use LogsActivity;
    protected $fillable = ['school_id', 'name', 'code'];

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
    public function curriculumSubjects(): HasMany
    {
        return $this->hasMany(CurriculumSubject::class);
    }
    protected static $logName = 'academics';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'code'])
            ->logOnlyDirty();
    }
}
