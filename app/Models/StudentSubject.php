<?php
// app/Models/StudentSubject.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class StudentSubject extends Model
{
    protected $fillable = ['student_curriculum_id', 'curriculum_subject_id'];

    protected static function booted(): void
    {
        static::creating(fn ($model) => $model->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName()
    {
        return 'uuid';
    }

    public function studentCurriculum(): BelongsTo
    {
        return $this->belongsTo(StudentCurriculum::class);
    }
    public function curriculumSubject(): BelongsTo
    {
        return $this->belongsTo(CurriculumSubject::class);
    }
}
