<?php

namespace App\Models;

use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use App\Concerns\HasStaffNumber;
use App\Support\ActiveSchool;
use App\Support\SchoolAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Teacher extends Model
{
    use AddUuid, BelongsToSchool, HasStaffNumber, SoftDeletes;

    protected $fillable = [
        'school_id',
        'user_id',
        'staff_number',
        'first_name',
        'last_name',
        'gender',
        'date_of_birth',
        'phone',
        'address',
        'qualification',
        'hire_date',
        'status',
        'photo_id',
    ];

    public $appends = ['full_name', 'name'];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Custom SchoolScope filter: a teacher is visible in their home school
     * (teachers.school_id) and in any school their linked user has been
     * granted access to via the school_user pivot. Raw subquery (not a
     * relation) so the scope cannot recurse.
     */
    public function applySchoolScope(Builder $builder, int $schoolId): void
    {
        $builder->where(function ($q) use ($schoolId) {
            $q->where('teachers.school_id', $schoolId)
                ->orWhereIn('teachers.user_id', SchoolAccess::userIdsWithAccessTo($schoolId));
        });
    }

    public function isHomeSchool(?int $schoolId = null): bool
    {
        return (int) $this->school_id === (int) ($schoolId ?? ActiveSchool::id());
    }

    public function getFullNameAttribute(): string
    {
        return "{$this->last_name} {$this->first_name}";
    }

    public function getNameAttribute(): string
    {
        return $this->full_name;
    }

    public function getPhotoAttribute(): ?string
    {
        return $this->photoFile?->url;
    }

    /** @return BelongsTo<School, $this> */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<FileUpload, $this> */
    public function photoFile(): BelongsTo
    {
        return $this->belongsTo(FileUpload::class, 'photo_id');
    }

    /** @return HasMany<TeacherCurriculumSubject, $this> */
    public function assignedCurriculumSubjects(): HasMany
    {
        return $this->hasMany(TeacherCurriculumSubject::class, 'teacher_id');
    }

    /** @return HasMany<TeacherCurriculumSubject, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(TeacherCurriculumSubject::class, 'teacher_id');
    }

    /** @return HasMany<ClassLevelArmTeacher, $this> */
    public function classLevelArmAssignments(): HasMany
    {
        return $this->hasMany(ClassLevelArmTeacher::class);
    }
}
