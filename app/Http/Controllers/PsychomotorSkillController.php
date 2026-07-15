<?php

namespace App\Http\Controllers;

use App\Concerns\ResolvesAssessmentAccess;
use App\Concerns\ResolvesTermFilter;
use App\Enums\TermStatusEnum;
use App\Http\Requests\PsychomotorSkillRequest;
use App\Http\Resources\PsychomotorSkillResource;
use App\Models\PsychomotorSkill;
use App\Models\StudentCurriculum;
use Illuminate\Support\Facades\Response;

class PsychomotorSkillController extends Controller
{
    use ResolvesAssessmentAccess, ResolvesTermFilter;

    public function store(PsychomotorSkillRequest $request)
    {
        $data = $request->validated();

        $studentCurriculum = StudentCurriculum::where('uuid', $data['student_curriculum_id'])->firstOrFail();

        // Recorded against the enrollment's own term (not the active term),
        // mirroring behavioral assessments.
        $term = $studentCurriculum->curriculum?->term;

        abort_unless($term, 422, 'There is no term to record an assessment for.');
        abort_if($term->status === TermStatusEnum::UPCOMING, 422, 'Cannot record assessments for an upcoming term.');

        abort_unless(
            (bool) $studentCurriculum->curriculum?->usesCategoricalGrading(),
            422,
            'Psychomotor skills are only recorded for categorical-grading curricula.'
        );

        abort_unless($this->canRecordAssessmentFor($studentCurriculum, $term), 403);

        $skill = PsychomotorSkill::updateOrCreate(
            [
                'student_curriculum_id' => $studentCurriculum->id,
                'assessment_term_id' => $term->id,
            ],
            [
                'drawing_colouring' => $data['drawing_colouring'],
                'cutting_pasting' => $data['cutting_pasting'],
                'puzzles_building' => $data['puzzles_building'],
                'climbing_sliding' => $data['climbing_sliding'],
                'comment' => $data['comment'] ?? null,
                'assessed_by' => auth()->id(),
            ]
        );

        return Response::success(new PsychomotorSkillResource($skill));
    }
}
