<?php
// app/Models/ClassLevel.php

namespace App\Models;

use App\Models\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ClassLevel extends Model
{
    use HasUuids;

    protected $fillable = ['school_id', 'name', 'order'];

    protected static function booted(): void
    {
        static::addGlobalScope(new SchoolScope());
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
