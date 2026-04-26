<?php
// app/Models/StudentResult.php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentResult extends Model
{
    use HasUuids;

    protected $fillable = [
        'student_id',
        'curriculum_subject_id',
        'total_score',
        'grade',
        'status',
        'approved_by',
        'approved_at',
        'computed_at',
    ];

    protected $casts = [
        'total_score' => 'decimal:2',
        'approved_at' => 'datetime',
        'computed_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
    public function curriculumSubject(): BelongsTo
    {
        return $this->belongsTo(CurriculumSubject::class);
    }
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
