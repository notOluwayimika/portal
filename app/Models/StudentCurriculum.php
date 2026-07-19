<?php

// app/Models/StudentCurriculum.php

namespace App\Models;

use App\Concerns\AddUuid;
use App\Enums\GenderTypeEnum;
use App\Enums\StudentStatusEnum;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class StudentCurriculum extends Model
{
    use AddUuid, LogsActivity;

    protected $fillable = [
        'student_id',
        'school_id',
        'curriculum_id',
        'status',
        'promoted_to_id',
        'ended_at',
        'ended_by_user_id',
        'end_reason',
        'form_teacher_comment',
        'head_of_school_comment',
        'principal_approval',
    ];

    protected $casts = [
        'status' => StudentStatusEnum::class,
        'ended_at' => 'datetime',
        'principal_approval' => 'boolean',
    ];

    /**
     * NOTE: `creating` is a HALTING event — returning a non-null value silently
     * stops the rest of the chain, which is why this is a block closure and never
     * an arrow fn (enforced by the halting-event-arrow-fn boundary lint).
     */
    protected static function booted(): void
    {
        static::creating(function ($model) {
            $model->status ??= StudentStatusEnum::ACTIVE;

            // school_id is the episode's own tenant anchor (slice (i)). Its value is
            // not free: it MUST equal the student's, and the composite FK
            // student_curricula (student_id, school_id) -> students (id, school_id)
            // enforces exactly that. So it is DERIVED here rather than pushed onto
            // every caller — the same shape BelongsToSchool::creating uses.
            //
            // Scopes are bypassed deliberately: the student may be soft-deleted
            // (Finance reads use withTrashed) or resolved outside an active School
            // context (jobs, console, backfills). This lookup is a convenience that
            // supplies the value; the FK is the guarantee that it is correct — an
            // explicitly-passed wrong school_id is NOT overridden here and is
            // rejected by the database.
            if ($model->school_id === null && $model->student_id !== null) {
                $model->school_id = Student::withoutGlobalScopes()
                    ->withTrashed()
                    ->whereKey($model->student_id)
                    ->value('school_id');
            }
        });
    }

    public function getRouteKeyName()
    {
        return 'uuid';
    }

    /* ── Relationships ───────────────────────────────────────────────────── */

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }

    public function promotedTo(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class, 'promoted_to_id');
    }

    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by_user_id');
    }

    public function studentSubjects(): HasMany
    {
        return $this->hasMany(StudentSubject::class);
    }

    public function behavioralAssessments(): HasMany
    {
        return $this->hasMany(BehavioralAssessment::class);
    }

    public function psychomotorSkills(): HasMany
    {
        return $this->hasMany(PsychomotorSkill::class);
    }

    /* ── Query helpers ───────────────────────────────────────────────────── */

    public function activeSubjects(): HasMany
    {
        return $this->hasMany(StudentSubject::class)
            ->active()
            ->with('curriculumSubject.subject');
    }

    public function droppedSubjects(): HasMany
    {
        return $this->hasMany(StudentSubject::class)
            ->dropped()
            ->with('curriculumSubject.subject');
    }

    public function availableOptionalSubjects(): Collection
    {
        $alreadyAttachedIds = $this->studentSubjects()->pluck('curriculum_subject_id');

        return $this->curriculum
            ->curriculumSubjects()
            ->active()
            ->where('is_compulsory', false)
            ->whereNotIn('id', $alreadyAttachedIds)
            ->with('subject')
            ->get();
    }

    /* ── Helpers ─────────────────────────────────────────────────────────── */

    public function isActive(): bool
    {
        return is_null($this->ended_at);
    }

    public function isEnded(): bool
    {
        return ! is_null($this->ended_at);
    }

    public function formTeacher(): ?Teacher
    {
        $classLevelArm = $this->curriculum ? $this->curriculum->classLevelArm : null;

        return $classLevelArm ? $classLevelArm->formTeacher() : null;
    }

    public function maleBoardingParent(): ?Teacher
    {
        $classLevelArm = $this->curriculum ? $this->curriculum->classLevelArm : null;

        return $classLevelArm ? $classLevelArm->maleBoardingParent() : null;
    }

    public function femaleBoardingParent(): ?Teacher
    {
        $classLevelArm = $this->curriculum ? $this->curriculum->classLevelArm : null;

        return $classLevelArm ? $classLevelArm->femaleBoardingParent() : null;
    }

    public function boardingParent(): ?Teacher
    {
        return match ($this->student ? $this->student->gender : null) {
            GenderTypeEnum::MALE->value => $this->maleBoardingParent(),
            GenderTypeEnum::FEMALE->value => $this->femaleBoardingParent(),
            default => null,
        };
    }

    public function headOfSchool(): ?Teacher
    {
        $classLevelArm = $this->curriculum ? $this->curriculum->classLevelArm : null;

        return $classLevelArm ? $classLevelArm->headOfSchool() : null;
    }

    /* ── Activity Log ────────────────────────────────────────────────────── */

    protected static $logName = 'academics';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'ended_at', 'end_reason', 'principal_approval'])
            ->logOnlyDirty();
    }
}
