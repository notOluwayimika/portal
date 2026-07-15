<?php

namespace App\Models;

use App\Concerns\AddUuid;
use App\Enums\BehavioralGradeEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PsychomotorSkill extends Model
{
    use AddUuid;

    protected $fillable = [
        'student_curriculum_id',
        'assessed_by',
        'drawing_colouring',
        'cutting_pasting',
        'puzzles_building',
        'climbing_sliding',
        'comment',
        'assessment_term_id',
    ];

    protected $casts = [
        'drawing_colouring' => BehavioralGradeEnum::class,
        'cutting_pasting' => BehavioralGradeEnum::class,
        'puzzles_building' => BehavioralGradeEnum::class,
        'climbing_sliding' => BehavioralGradeEnum::class,
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
