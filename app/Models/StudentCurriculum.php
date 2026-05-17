<?php
// app/Models/StudentCurriculum.php

namespace App\Models;

use App\Concerns\AddUuid;
use App\Enums\StudentStatusEnum;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

class StudentCurriculum extends Model
{
    use AddUuid, LogsActivity;

    protected $fillable = [
        'student_id',
        'curriculum_id',
        'status',
        'promoted_to_id',
        'ended_at',
        'ended_by_user_id',
        'end_reason',
    ];

    protected $casts = [
        'status'    => StudentStatusEnum::class,
        'ended_at'  => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            $model->status ??= StudentStatusEnum::ACTIVE;
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
        return !is_null($this->ended_at);
    }

    /* ── Activity Log ────────────────────────────────────────────────────── */

    protected static $logName = 'academics';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'ended_at', 'end_reason'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
