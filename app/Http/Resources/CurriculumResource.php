<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CurriculumResource extends JsonResource
{
    protected $includeSubjects = true;

    public function withoutSubjects()
    {
        $this->includeSubjects = false;
        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            "id" => $this->uuid,
            "term" => new TermResource($this->term),
            "academic_session" => new SessionResource($this->academicSession),
            "class_level_arm" => new ClassLevelArmResource($this->classLevelArm),
            "exam_type" => new ExamTypeResource($this->examType),
            "min_subjects" => $this->min_subjects,
            "status" => $this->status,
            "is_ccm" => $this->is_ccm,
            "full_name" => $this->academicSession->name . ' ' . $this->classLevelArm->classLevel->name . ' ' . $this->classLevelArm->arm->label . ($this->classLevelArm->stream ? ' ' . $this->classLevelArm->stream->name : '') . ' ' . $this->examType->name . ' ' . $this->term->name . ' '  . ($this->is_ccm ? '(CCM)' : ''),

            "curriculum_subjects" => $this->when(
                $this->includeSubjects,
                CurriculumSubjectResource::collection($this->curriculumSubjects)
            ),
            'student_curricula' => StudentCurriculumResource::collection($this->whenLoaded('studentCurricula'))
        ];
    }
}
