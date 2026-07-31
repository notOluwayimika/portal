<?php

// app/Models/Score.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property string $uuid
 * @property string $score Cast decimal:2, so it is a STRING. Weighted, not the teacher's percentage.
 * @property int $curriculum_subject_id
 * @property int $student_id
 * @property int $marking_component_id
 * @property-read MarkingComponent $markingComponent
 */
class Score extends Model
{
    use LogsActivity;

    protected $fillable = [
        'student_id',
        'curriculum_subject_id',
        'marking_component_id',
        'score',
        'created_by',
    ];

    protected $casts = ['score' => 'decimal:2'];

    /**
     * Guard against editing scores on approved subjects.
     * The Postgres trigger is the hard stop; this is the service-layer guard.
     */
    protected static function booted(): void
    {
        static::creating(function ($model) {
            $model->uuid ??= (string) Str::uuid();
        });
        static::saving(function (Score $score) {
            $score->guardApproved();
        });

        // DELETING WAS NEVER GUARDED. Only `saving` was, so `$score->delete()` bypassed the
        // service-layer protection entirely and relied on the endpoint remembering to check —
        // which the old "post a 0 to clear" path did not, since it deleted from inside the write
        // handler. Removing a score from an approved subject changes that subject's result just as
        // much as editing one does.
        static::deleting(function (Score $score) {
            $score->guardApproved();
        });
    }

    public function getRouteKeyName()
    {
        return 'uuid';
    }

    /**
     * Refuse any write or removal once the subject's result is approved.
     *
     * Shared by the `saving` and `deleting` hooks so the two can never drift apart — the reason
     * deletion went unprotected in the first place is that it was a separate, forgotten branch.
     */
    protected function guardApproved(): void
    {
        $status = SubjectResultStatus::where('curriculum_subject_id', $this->curriculum_subject_id)
            ->value('status');

        if ($status === 'approved') {
            throw new \DomainException('Cannot modify scores: subject result is approved.');
        }
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<CurriculumSubject, $this> */
    public function curriculumSubject(): BelongsTo
    {
        return $this->belongsTo(CurriculumSubject::class);
    }

    /** @return BelongsTo<MarkingComponent, $this> */
    public function markingComponent(): BelongsTo
    {
        return $this->belongsTo(MarkingComponent::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected static $logName = 'results';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['score'])
            ->logOnlyDirty();
    }
}
