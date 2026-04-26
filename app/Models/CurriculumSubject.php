<?php
// app/Models/CurriculumSubject.php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CurriculumSubject extends Model
{
    use HasUuids;

    protected $fillable = ['curriculum_id', 'subject_id', 'is_compulsory', 'display_order'];

    protected $casts = [
        'is_compulsory' => 'boolean',
        'display_order' => 'integer',
    ];

    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
    public function markingComponents(): HasMany
    {
        return $this->hasMany(MarkingComponent::class);
    }
    public function scores(): HasMany
    {
        return $this->hasMany(Score::class);
    }
    public function studentResults(): HasMany
    {
        return $this->hasMany(StudentResult::class);
    }
    public function resultStatus(): HasOne
    {
        return $this->hasOne(SubjectResultStatus::class);
    }
    public function teacherAssignments(): HasMany
    {
        return $this->hasMany(TeacherCurriculumSubject::class);
    }

    public function isApproved(): bool
    {
        return $this->resultStatus && $this->resultStatus->status === 'approved';
    }
}
