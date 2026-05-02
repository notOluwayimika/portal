<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ClassLevelArm extends Model
{
    protected $fillable = ['stream_id', 'class_level_id', 'arm_id'];
    protected static function booted(): void
    {
        static::creating(fn($model) => $model->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName()
    {
        return 'uuid';
    }

    public function classLevel()
    {
        return $this->belongsTo(ClassLevel::class);
    }

    public function arm()
    {
        return $this->belongsTo(Arm::class);
    }

    public function stream()
    {
        return $this->belongsTo(Stream::class);
    }
}
