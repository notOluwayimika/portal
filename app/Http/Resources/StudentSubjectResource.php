<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentSubjectResource extends JsonResource
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
            "student_curriculum" => $this->studentCurriculum ? new StudentCurriculumResource($this->studentCurriculum) : null,
            "curriculum_subject" => $this->curriculumSubject ? new CurriculumSubjectResource($this->curriculumSubject) : null
        ];
    }
}
