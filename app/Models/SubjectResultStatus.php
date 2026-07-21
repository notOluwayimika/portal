<?php

// app/Models/SubjectResultStatus.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SubjectResultStatus extends Model
{
    use LogsActivity;

    protected $fillable = [
        'curriculum_subject_id',
        'status',
        'rejection_reason',
        'updated_by',
        // Maker and checker are recorded separately (ADR 0040/0044). `updated_by`
        // remains "who touched this last"; it cannot serve as either, because
        // each transition overwrites it.
        'submitted_by',
        'decided_by',
    ];

    // Valid state machine transitions
    public const TRANSITIONS = [
        'draft' => ['submitted'],
        'submitted' => ['approved', 'rejected'],
        'rejected' => ['draft'],
        'approved' => [], // terminal — no further transitions
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

    public function curriculumSubject(): BelongsTo
    {
        return $this->belongsTo(CurriculumSubject::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function canTransitionTo(string $newStatus): bool
    {
        return in_array($newStatus, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function transitionTo(string $newStatus, string $actorId, ?string $reason = null): void
    {
        if (! $this->canTransitionTo($newStatus)) {
            throw new \DomainException("Cannot transition from [{$this->status}] to [{$newStatus}].");
        }

        if ($newStatus === 'rejected' && empty($reason)) {
            throw new \InvalidArgumentException('A rejection reason is required.');
        }

        // Record the actor in the column its ROLE in the workflow calls for, not
        // just in updated_by — otherwise a caller of this method could produce a
        // transition with no recoverable maker or checker, which is the defect
        // the C3 migration exists to remove. (No caller exists today; leaving it
        // writing only updated_by would have made the first one silently unsafe.)
        $this->update([
            'status' => $newStatus,
            'rejection_reason' => $newStatus === 'rejected' ? $reason : null,
            'updated_by' => $actorId,
            ...match ($newStatus) {
                'submitted' => ['submitted_by' => $actorId, 'decided_by' => null],
                'approved', 'rejected' => ['decided_by' => $actorId],
                default => [],
            },
        ]);
    }

    protected static $logName = 'results';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'rejection_reason'])
            ->logOnlyDirty();
    }
}
