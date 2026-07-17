<?php

// app/Models/StudentSubject.php

namespace App\Models;

use App\Enums\StudentSubjectStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;

class StudentSubject extends Model
{
    use LogsActivity;

    protected $fillable = [
        'student_curriculum_id',
        'curriculum_subject_id',
        'status',
        'dropped_by_user_id',
        'dropped_at',
        'drop_reason',
        'restored_by_user_id',
        'restored_at',
        'comment',
        'commented_by',
    ];

    protected $casts = [
        'status' => StudentSubjectStatus::class,
        'dropped_at' => 'datetime',
        'restored_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName()
    {
        return 'uuid';
    }

    /* ── Relationships ───────────────────────────────────────────────────── */

    public function studentCurriculum(): BelongsTo
    {
        return $this->belongsTo(StudentCurriculum::class);
    }

    public function curriculumSubject(): BelongsTo
    {
        return $this->belongsTo(CurriculumSubject::class);
    }

    public function droppedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dropped_by_user_id');
    }

    public function restoredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'restored_by_user_id');
    }

    /* ── Scopes ──────────────────────────────────────────────────────────── */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', StudentSubjectStatus::Active);
    }

    public function scopeDropped(Builder $query): Builder
    {
        return $query->where('status', StudentSubjectStatus::Dropped);
    }

    /* ── Helpers ─────────────────────────────────────────────────────────── */

    public function isOptional(): bool
    {
        return $this->curriculumSubject && ! $this->curriculumSubject->is_compulsory;
    }

    public function isCompulsory(): bool
    {
        return $this->curriculumSubject && $this->curriculumSubject->is_compulsory;
    }

    public function canBeDropped(): bool
    {
        return $this->isOptional() && $this->status === StudentSubjectStatus::Active;
    }

    public function canBeRestored(): bool
    {
        return $this->status === StudentSubjectStatus::Dropped;
    }

    public function commentedBy(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'commented_by');
    }

    /* ── Activity Log ────────────────────────────────────────────────────── */

    protected static $logName = 'academics';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'drop_reason', 'dropped_at', 'restored_at'])
            ->logOnlyDirty();
    }

    public function beforeActivityLogged(Activity $activity, string $eventName): void
    {
        $subjectName = optional($this->curriculumSubject?->subject)->name ?? 'Unknown subject';
        $studentName = optional($this->studentCurriculum?->student)->full_name ?? 'Unknown student';
        $curriculum = optional($this->studentCurriculum?->curriculum)->full_name ?? '';

        $activity->description = match ($eventName) {
            'created' => "Added {$subjectName} to {$studentName}'s curriculum",
            'updated' => $this->status === StudentSubjectStatus::Dropped
            ? "Dropped {$subjectName} from {$studentName}'s curriculum"
            : "Restored {$subjectName} for {$studentName}",
            default => "{$eventName} {$subjectName} for {$studentName}",
        };

        $activity->properties = $activity->properties->merge([
            'subject_name' => $subjectName,
            'student_name' => $studentName,
            'curriculum_name' => $curriculum,
            'actor_role' => optional(auth()->user())->roles?->first()?->name,
        ]);
    }
}
