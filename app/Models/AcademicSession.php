<?php

namespace App\Models;

use App\Models\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicSession extends Model
{
    use HasUuids;
    protected $fillable = ['school_id', 'name', 'slug', 'is_current'];

    protected $casts = ['is_current' => 'boolean'];

    protected static function booted(): void
    {
        static::addGlobalScope(new SchoolScope());
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
