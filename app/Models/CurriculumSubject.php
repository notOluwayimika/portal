<?php
// app/Models/CurriculumSubject.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class CurriculumSubject extends Model
{
    protected $fillable = ['curriculum_id', 'subject_id', 'is_compulsory', 'display_order'];

    protected $casts = [
        'is_compulsory' => 'boolean',
        'display_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(fn ($model) => $model->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName()
    {
        return 'uuid';
    }

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
