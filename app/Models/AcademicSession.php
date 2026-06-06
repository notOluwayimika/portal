<?php

namespace App\Models;

use App\Models\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class AcademicSession extends Model
{
    use LogsActivity;
    protected $fillable = ['school_id', 'name', 'slug', 'is_current'];

    protected $casts = ['is_current' => 'boolean'];

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
    public function terms(): HasMany
    {
        return $this->hasMany(Term::class);
    }

    public function curricula(): \Illuminate\Database\Eloquent\Relations\HasManyThrough
    {
        return $this->hasManyThrough(Curriculum::class, Term::class);
    }

    protected static $logName = 'academics';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'slug', 'is_current'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
