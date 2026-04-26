<?php
// app/Models/StudentSubject.php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentSubject extends Model
{
    use HasUuids;

    protected $fillable = ['student_curriculum_id', 'curriculum_subject_id'];

    public function studentCurriculum(): BelongsTo
    {
        return $this->belongsTo(StudentCurriculum::class);
    }
    public function curriculumSubject(): BelongsTo
    {
        return $this->belongsTo(CurriculumSubject::class);
    }
}
