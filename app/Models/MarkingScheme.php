<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class MarkingScheme extends Model
{
    protected $fillable = ['school_id', 'is_ccm', 'version', 'status'];

    protected $casts = ['is_ccm' => 'boolean', 'version' => 'integer'];

    protected static function booted(): void
    {
        static::creating(fn ($model) => $model->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function components(): HasMany
    {
        return $this->hasMany(MarkingComponent::class)->orderBy('id');
    }

    public function curricula(): HasMany
    {
        return $this->hasMany(Curriculum::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
