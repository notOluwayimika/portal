<?php
// app/Models/Curriculum.php

namespace App\Models;

use App\Enums\CurriculaStatusEnum;
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
        'term_id',
        'class_level_arm_id',
        'exam_type_id',
        'min_subjects',
        'status',
    ];

    protected $casts = [
        'term_id' => 'integer',
        'min_subjects' => 'integer',
        'status' => 'string',
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
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }
    public function academicSession(): \Illuminate\Database\Eloquent\Relations\HasOneThrough
    {
        return $this->hasOneThrough(AcademicSession::class, Term::class, 'id', 'id', 'term_id', 'academic_session_id');
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
        return $this->term->start_date && now()->lessThanOrEqualTo($this->term->start_date);
    }

    public function areResultsVisible(): bool
    {
        return $this->term->end_date && now()->greaterThanOrEqualTo($this->term->end_date);
    }
}
