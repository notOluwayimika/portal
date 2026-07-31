<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class GradingScheme extends Model
{
    protected $fillable = ['school_id', 'family_uuid', 'name', 'mode', 'version', 'status'];

    protected static function booted(): void
    {
        static::creating(function (self $scheme): void {
            $scheme->uuid ??= (string) Str::uuid();
            $scheme->family_uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return HasMany<GradingSchemeItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(GradingSchemeItem::class)->orderBy('display_order');
    }

    /** @return HasMany<Curriculum, $this> */
    public function curricula(): HasMany
    {
        return $this->hasMany(Curriculum::class);
    }

    /** @return HasMany<ClassLevel, $this> */
    public function classLevels(): HasMany
    {
        return $this->hasMany(ClassLevel::class);
    }
}
