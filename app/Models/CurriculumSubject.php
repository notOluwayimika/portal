<?php
// app/Models/CurriculumSubject.php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class CurriculumSubject extends Model
{
    use LogsActivity;
    protected $fillable = [
        'curriculum_id',
        'subject_id',
        'is_compulsory',
        'display_order',
        'active',
        'archived_at',
        'archived_by_user_id',
    ];

    protected $casts = [
        'is_compulsory' => 'boolean',
        'display_order' => 'integer',
        'active' => 'boolean',
        'archived_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn($model) => $model->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName()
    {
        return 'uuid';
    }

    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
    public function markingComponents(): HasMany
    {
        return $this->hasMany(MarkingComponent::class);
    }
    public function scores(): HasMany
    {
        return $this->hasMany(Score::class);
    }
    public function studentResults(): HasMany
    {
        return $this->hasMany(StudentResult::class);
    }
    public function resultStatus(): HasOne
    {
        return $this->hasOne(SubjectResultStatus::class);
    }
    public function teacherAssignments(): HasMany
    {
        return $this->hasMany(TeacherCurriculumSubject::class);
    }

    public function studentAssignments(): HasMany
    {
        return $this->hasMany(StudentSubject::class);
    }

    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by_user_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->where('active', false);
    }

    public function isArchived(): bool
    {
        return !$this->active;
    }

    public function canBeAddedToStudent(): bool
    {
        return !$this->is_compulsory && $this->active;
    }

    public function isApproved(): bool
    {
        return $this->resultStatus && $this->resultStatus->status === 'approved';
    }

    protected static $logName = 'results';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['is_compulsory', 'active', 'archived_at', 'display_order'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
    public function beforeActivityLogged(Activity $activity, string $eventName): void
    {
        $subjectName = optional($this->subject)->name ?? 'Unknown subject';
        $curriculum = optional($this->curriculum)->full_name ?? '';

        $activity->description = match ($eventName) {
            'created' => "created {$subjectName} for {$curriculum}",
            'updated' => "updated {$subjectName} for {$curriculum}",
            default => "{$eventName} {$subjectName} for {$curriculum}",
        };

        $activity->properties = $activity->properties->merge([
            'subject_name' => $subjectName,
            'curriculum_name' => $curriculum,
            'actor_role' => optional(auth()->user())->roles?->first()?->name,
        ]);

    }
}
