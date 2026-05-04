<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CurriculumResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->uuid,
            "academic_session" => new SessionResource($this->academicSession),
            "class_level_arm" => new ClassLevelArmResource($this->classLevelArm),
            "exam_type" => new ExamTypeResource($this->examType),
            "term" => $this->term,
            "min_subjects" => $this->min_subjects,
            "registration_deadline" => $this->registration_deadline,
            "result_visible_at" => $this->result_visible_at,
            "status" => $this->status,
        ];
    }
}
