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
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property string $uuid
 */
class Teacher extends Model
{
    use AddUuid, BelongsToSchool, HasStaffNumber, LogsActivity, SoftDeletes;

    /**
     * Audited fields — identity and contact, not cosmetics.
     *
     * `phone` is the security-critical one: it is a delivery address, so a change
     * to it is an account-security event in the same class as an email change, and
     * the notification layer's Tier-1 signal reads exactly these rows.
     *
     * DELIBERATELY OMITTED: `photo_id` and `qualification`. Neither carries identity
     * or access, and logging them would add volume to the audit trail with nothing
     * to answer for it.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'first_name',
                'last_name',
                'gender',
                'date_of_birth',
                'phone',
                'address',
                'staff_number',
                'status',
            ])
            ->logOnlyDirty()
            // Without this, changing a NON-audited column still writes a row with an
            // empty attribute set — pure volume with nothing recorded. On the two
            // highest-cardinality models in the app that is the difference between an
            // audit trail and a write amplifier.
            ->dontSubmitEmptyLogs()
            ->useLogName('teacher');
    }

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
