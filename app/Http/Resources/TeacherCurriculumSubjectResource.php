<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeacherCurriculumSubjectResource extends JsonResource
{

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'teacher' => new TeacherResource($this->teacher),
            'curriculum_subject' => new CurriculumSubjectResource($this->whenLoaded('curriculumSubject')),
        ];
    }
}
