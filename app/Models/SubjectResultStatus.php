<?php
// app/Models/SubjectResultStatus.php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubjectResultStatus extends Model
{
    use HasUuids;

    protected $fillable = [
        'curriculum_subject_id',
        'status',
        'rejection_reason',
        'updated_by',
    ];

    // Valid state machine transitions
    public const TRANSITIONS = [
        'draft' => ['submitted'],
        'submitted' => ['approved', 'rejected'],
        'rejected' => ['draft'],
        'approved' => [], // terminal — no further transitions
    ];

    public function curriculumSubject(): BelongsTo
    {
        return $this->belongsTo(CurriculumSubject::class);
    }
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function canTransitionTo(string $newStatus): bool
    {
        return in_array($newStatus, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function transitionTo(string $newStatus, string $actorId, ?string $reason = null): void
    {
        if (!$this->canTransitionTo($newStatus)) {
            throw new \DomainException("Cannot transition from [{$this->status}] to [{$newStatus}].");
        }

        if ($newStatus === 'rejected' && empty($reason)) {
            throw new \InvalidArgumentException('A rejection reason is required.');
        }

        $this->update([
            'status' => $newStatus,
            'rejection_reason' => $newStatus === 'rejected' ? $reason : null,
            'updated_by' => $actorId,
        ]);
    }
}
