<?php
// app/Models/Score.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Score extends Model
{
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
        static::creating(fn ($model) => $model->uuid ??= (string) Str::uuid());
        static::saving(function (Score $score) {
            $status = SubjectResultStatus::where('curriculum_subject_id', $score->curriculum_subject_id)
                ->value('status');

            if ($status === 'approved') {
                throw new \DomainException('Cannot modify scores: subject result is approved.');
            }
        });
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
    public function markingComponent(): BelongsTo
    {
        return $this->belongsTo(MarkingComponent::class);
    }
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
