<?php

namespace App\Models;

use App\Models\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AcademicSession extends Model
{
    protected $fillable = ['school_id', 'name', 'slug', 'is_current'];

    protected $casts = ['is_current' => 'boolean'];

    protected static function booted(): void
    {
        static::addGlobalScope(new SchoolScope());
        static::creating(fn ($model) => $model->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName()
    {
        return 'uuid';
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
    public function curricula(): HasMany
    {
        return $this->hasMany(Curriculum::class, 'academic_session_id');
    }
}
