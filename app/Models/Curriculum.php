<?php
// app/Models/Curriculum.php

namespace App\Models;

use App\Models\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Curriculum extends Model
{
    protected $table = 'curricula';

    protected $fillable = [
        'school_id',
        'academic_session_id',
        'class_level_arm_id',
        'exam_type_id',
        'term',
        'min_subjects',
        'registration_deadline',
        'result_visible_at',
        'status',
    ];

    protected $casts = [
        'registration_deadline' => 'datetime',
        'result_visible_at' => 'datetime',
        'term' => 'integer',
        'min_subjects' => 'integer',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new SchoolScope());
        static::creating(fn($model) => $model->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName()
    {
        return 'uuid';
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
    public function academicSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'academic_session_id');
    }
    public function classLevelArm(): BelongsTo
    {
        return $this->belongsTo(ClassLevelArm::class, 'class_level_arm_id');
    }
    public function examType(): BelongsTo
    {
        return $this->belongsTo(ExamType::class);
    }
    public function curriculumSubjects(): HasMany
    {
        return $this->hasMany(CurriculumSubject::class);
    }
    public function studentCurricula(): HasMany
    {
        return $this->hasMany(StudentCurriculum::class);
    }

    public function isRegistrationOpen(): bool
    {
        return now()->lessThanOrEqualTo($this->registration_deadline);
    }

    public function areResultsVisible(): bool
    {
        return $this->result_visible_at && now()->greaterThanOrEqualTo($this->result_visible_at);
    }
}
