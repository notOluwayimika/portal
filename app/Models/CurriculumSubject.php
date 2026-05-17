<?php
// app/Models/CurriculumSubject.php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class CurriculumSubject extends Model
{
    protected $fillable = [
        'curriculum_id', 'subject_id', 'is_compulsory', 'display_order',
        'active', 'archived_at', 'archived_by_user_id',
    ];

    protected $casts = [
        'is_compulsory'  => 'boolean',
        'display_order'  => 'integer',
        'active'         => 'boolean',
        'archived_at'    => 'datetime',
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
}
