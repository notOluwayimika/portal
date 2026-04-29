<?php
// app/Models/StudentCurriculum.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class StudentCurriculum extends Model
{
    protected $fillable = ['student_id', 'curriculum_id'];

    protected static function booted(): void
    {
        static::creating(fn ($model) => $model->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName()
    {
        return 'uuid';
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }
    public function studentSubjects(): HasMany
    {
        return $this->hasMany(StudentSubject::class);
    }
}
