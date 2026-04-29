<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Teacher extends Model
{
    protected static function booted(): void
    {
        static::creating(fn ($model) => $model->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName()
    {
        return 'uuid';
    }

    public function assignedCurriculumSubjects()
    {
        return $this->hasMany(TeacherCurriculumSubject::class, 'teacher_id');
    }
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
