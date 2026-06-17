<?php

namespace App\Models;

use App\Concerns\AddUuid;
use App\Enums\BehavioralGradeEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BehavioralAssessment extends Model
{
    use AddUuid;

    protected $fillable = [
        'student_curriculum_id',
        'assessed_by',
        'punctuality',
        'mental_alertness',
        'respect',
        'neatness',
        'politeness',
        'honesty',
        'relationship_with_peers',
        'teamwork',
        'perseverance',
        'comment',
        'assessment_term_id',
    ];

    protected $casts = [
        'punctuality' => BehavioralGradeEnum::class,
        'mental_alertness' => BehavioralGradeEnum::class,
        'respect' => BehavioralGradeEnum::class,
        'neatness' => BehavioralGradeEnum::class,
        'politeness' => BehavioralGradeEnum::class,
        'honesty' => BehavioralGradeEnum::class,
        'relationship_with_peers' => BehavioralGradeEnum::class,
        'teamwork' => BehavioralGradeEnum::class,
        'perseverance' => BehavioralGradeEnum::class,
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function studentCurriculum(): BelongsTo
    {
        return $this->belongsTo(StudentCurriculum::class);
    }

    public function assessedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }

    public function assessmentTerm(): BelongsTo
    {
        return $this->belongsTo(Term::class, 'assessment_term_id');
    }
}
