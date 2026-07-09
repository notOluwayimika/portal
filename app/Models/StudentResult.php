<?php
// app/Models/StudentResult.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;

class StudentResult extends Model
{
    use LogsActivity;
    protected $fillable = [
        'student_id',
        'curriculum_subject_id',
        'total_score',
        'grade',
        'status',
        'approved_by',
        'approved_at',
        'computed_at',
    ];

    protected $casts = [
        'total_score' => 'decimal:2',
        'approved_at' => 'datetime',
        'computed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn($model) => $model->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName()
    {
        return 'uuid';
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
    public function curriculumSubject(): BelongsTo
    {
        return $this->belongsTo(CurriculumSubject::class);
    }
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }


    protected static $logName = 'results';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'total_score', 'grade'])
            ->logOnlyDirty();
    }
    public function beforeActivityLogged(Activity $activity, string $eventName): void
    {
        $studentName = $this->student->full_name ?? 'Unknown student';
        $subjectName = optional($this->curriculumSubject?->subject)->name ?? 'Unknown subject';
        $curriculum = optional($this->curriculumSubject?->curriculum)->full_name ?? '';
        $status = $this->status ?? 'Unknown';

        $activity->description = match ($eventName) {
            'created' => "{$status} {$subjectName} result for {$studentName}'s {$curriculum}",
            'updated' => "{$status} {$subjectName} result for {$studentName}'s {$curriculum}",
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
