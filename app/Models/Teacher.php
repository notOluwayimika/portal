<?php

namespace App\Models;

use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use App\Concerns\HasStaffNumber;
use App\Support\ActiveSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Teacher extends Model
{
    use AddUuid, SoftDeletes, HasStaffNumber, BelongsToSchool;

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
                ->orWhereIn('teachers.user_id', fn ($sub) => $sub
                    ->select('user_id')
                    ->from('school_user')
                    ->where('school_id', $schoolId));
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

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function photoFile(): BelongsTo
    {
        return $this->belongsTo(FileUpload::class, 'photo_id');
    }

    public function assignedCurriculumSubjects(): HasMany
    {
        return $this->hasMany(TeacherCurriculumSubject::class, 'teacher_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(TeacherCurriculumSubject::class, 'teacher_id');
    }

    public function classLevelArmAssignments(): HasMany
    {
        return $this->hasMany(ClassLevelArmTeacher::class);
    }
}
