<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ClassLevelArm extends Model
{
    protected static function booted(): void
    {
        static::creating(fn ($model) => $model->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName()
    {
        return 'uuid';
    }

    public function classLevel()
    {
        return $this->belongsTo(ClassLevel::class);
    }
}
