<?php

// app/Models/StudentCurriculum.php

namespace App\Models;

use App\Concerns\AddUuid;
use App\Enums\GenderTypeEnum;
use App\Enums\StudentStatusEnum;
use App\Models\Scopes\SchoolScope;
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
     * SCOPE — slice (ii). The episode is School-scoped for READS: every Eloquent
     * query is filtered to the Active School, so a foreign-School episode simply
     * does not resolve. This is what closes the read-side holes slice (i) left
     * open by design: the `{studentCurriculum:uuid}` route bindings and the
     * `where('uuid')->firstOrFail()` assessment lookups, all of which previously
     * resolved ANY School's row because this model carried no scope at all.
     *
     * WHY THE BARE SCOPE AND NOT `BelongsToSchool`. The trait bundles the scope
     * with a `creating` hook that fills school_id from the AMBIENT ActiveSchool,
     * and that hook registers first — it would win over the student-derived fill
     * below and make an episode's School a function of WHO IS LOGGED IN. That is
     * precisely the coupling slice (i) removed (an invoice's School once came from
     * request context rather than the episode being billed). The rule this follows,
     * shared with Curriculum / ClassLevel / Arm / Subject / ExamType /
     * AcademicSession / GradeBoundary: use the trait when ambient context is the
     * right SOURCE of school_id; use the bare scope when the value is DERIVED from
     * a parent.
     *
     * Note the scope is a global scope, not an event listener, so the halting-event
     * class (1.3b.1) cannot silently disable it — that defect only ever affected
     * `creating` chains. The fill below is still a block closure for that reason.
     *
     * NOT enabled here: `rbac.fail_closed_models`. The scope FILTERS whenever a
     * context exists (which closes the holes); the flag only decides whether a
     * MISSING context throws rather than reading unscoped. That is an independent
     * behaviour change over every context-less path, and Debt 7 gates the throw on
     * auth()->check() anyway — see docs/roadmap.md.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new SchoolScope);

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
