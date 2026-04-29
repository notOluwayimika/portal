<?php
// app/Models/ClassLevel.php

namespace App\Models;

use App\Models\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class ClassLevel extends Model
{
    protected $fillable = ['school_id', 'name', 'order'];

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

    public function arms(): BelongsToMany
    {
        return $this->belongsToMany(Arm::class, 'class_level_arms');
    }
}
