<?php

// app/Models/Curriculum.php

namespace App\Models;

use App\Models\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property string $uuid
 * @property int|null $class_level_arm_id Larastan otherwise infers string|null, which made a
 *                                        strict comparison against ClassLevelArmTeacher::\$class_level_arm_id look
 *                                        always-false. Verified integer at runtime on both sides.
 */
class Curriculum extends Model
{
    use HasFactory, LogsActivity;

    protected $table = 'curricula';

    protected $append = ['full_name'];

    protected $fillable = [
        'school_id',
        'marking_scheme_id',
        'grading_scheme_id',
        'term_id',
        'class_level_arm_id',
        'exam_type_id',
        'min_subjects',
        'status',
        'is_ccm',
    ];

    protected $casts = [
        'term_id' => 'integer',
        'min_subjects' => 'integer',
        'status' => 'string',
        'is_ccm' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new SchoolScope);
        static::creating(function ($model) {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName()
    {
        return 'uuid';
    }

    /** @return BelongsTo<School, $this> */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /** @return BelongsTo<MarkingScheme, $this> */
    public function markingScheme(): BelongsTo
    {
        return $this->belongsTo(MarkingScheme::class);
    }

    /** @return BelongsTo<GradingScheme, $this> */
    public function gradingScheme(): BelongsTo
    {
        return $this->belongsTo(GradingScheme::class);
    }

    public function usesCategoricalGrading(): bool
    {
        return $this->grading_scheme_id !== null;
    }

    /** @return BelongsTo<Term, $this> */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    /** @return HasOneThrough<AcademicSession, Term, $this> */
    public function academicSession(): HasOneThrough
    {
        return $this->hasOneThrough(AcademicSession::class, Term::class, 'id', 'id', 'term_id', 'academic_session_id');
    }

    /** @return BelongsTo<ClassLevelArm, $this> */
    public function classLevelArm(): BelongsTo
    {
        return $this->belongsTo(ClassLevelArm::class, 'class_level_arm_id');
    }

    /**
     * How an OPERATOR names this class — "Year 9 A", not `curriculum#4`.
     *
     * ── ONE CONSTRUCTION, THREE CALLERS, AND IT WAS TWO THAT DISAGREED ───────────────────────────
     * `RolloverPlanner::describe()` and `RolloverController::describe()` both built this label, and
     * they had already DRIFTED: the planner trims, falls back to `curriculum#{id}` when there is no
     * arm, and falls back AGAIN when the assembled label is empty; the controller does none of those
     * and returns an em dash. So the name an operator saw depended on which path produced it. A third
     * copy — which is what naming the class in the fold refusal would otherwise have needed — would
     * have made three definitions with two known disagreements.
     *
     * ── IT RETURNS null RATHER THAN A FALLBACK, DELIBERATELY ─────────────────────────────────────
     * The two existing callers want DIFFERENT fallbacks and both are defensible: a plan that cannot
     * name a class still has to identify it (`curriculum#{id}`), while a table cell reads better with
     * an em dash than with an id. Baking either one in here would silently change a screen. This
     * shares the part that drifts — the assembly — and leaves the part that legitimately differs to
     * the caller.
     *
     * Requires `classLevelArm.classLevel`, `.arm` and `.stream`; callers that use it in a loop should
     * eager-load them.
     */
    public function operatorLabel(): ?string
    {
        $arm = $this->classLevelArm;

        if ($arm === null) {
            return null;
        }

        $label = trim(implode(' ', array_filter([
            $arm->classLevel?->name,
            $arm->arm?->label,
            $arm->stream?->name,
        ])));

        return $label !== '' ? $label : null;
    }

    /** @return BelongsTo<ExamType, $this> */
    public function examType(): BelongsTo
    {
        return $this->belongsTo(ExamType::class);
    }

    /** @return HasMany<CurriculumSubject, $this> */
    public function curriculumSubjects(): HasMany
    {
        return $this->hasMany(CurriculumSubject::class);
    }

    /** @return HasMany<StudentCurriculum, $this> */
    public function studentCurricula(): HasMany
    {
        return $this->hasMany(StudentCurriculum::class);
    }

    public function isRegistrationOpen(): bool
    {
        // `terms.start_date` is NOT NULL, so the old truthiness guard could never
        // be false — Larastan reports it as an always-true left side.
        return now()->lessThanOrEqualTo($this->term->start_date);
    }

    public function areResultsVisible(): bool
    {
        // As above: `terms.end_date` is NOT NULL.
        return now()->greaterThanOrEqualTo($this->term->end_date);
    }

    public function getFullNameAttribute()
    {
        return $this->classLevelArm->classLevel->name.' '.$this->classLevelArm->arm->label.($this->classLevelArm->stream ? ' '.$this->classLevelArm->stream->name : '').' '.$this->examType->name.' '.($this->is_ccm ? '(CCM)' : '');
    }

    protected static $logName = 'academics';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['min_subjects', 'status', 'is_ccm'])
            ->logOnlyDirty();
    }
}
