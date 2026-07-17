<?php

// app/Models/TeacherCurriculumSubject.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class TeacherCurriculumSubject extends Model
{
    use LogsActivity;

    protected $fillable = ['teacher_id', 'curriculum_subject_id'];

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

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'teacher_id');
    }

    public function curriculumSubject(): BelongsTo
    {
        return $this->belongsTo(CurriculumSubject::class);
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
        $teacherName = $this->teacher->full_name ?? 'Unknown student';
        $subjectName = optional($this->curriculumSubject?->subject)->name ?? 'Unknown subject';
        $curriculum = optional($this->curriculumSubject?->curriculum)->full_name ?? '';

        $activity->description = match ($eventName) {
            'created' => "{$teacherName} has been assigned to {$subjectName} for {$curriculum}",
            'deleted' => "{$teacherName} has been unassigned from {$subjectName} for {$curriculum}",
            default => "{$eventName} {$subjectName} for {$teacherName}",
        };

        $activity->properties = $activity->properties->merge([
            'subject_name' => $subjectName,
            'student_name' => $teacherName,
            'curriculum_name' => $curriculum,
            'actor_role' => optional(auth()->user())->roles?->first()?->name,
        ]);

    }
}
