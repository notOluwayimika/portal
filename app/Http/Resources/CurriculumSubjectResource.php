<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CurriculumSubjectResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'curriculum' => (new CurriculumResource($this->curriculum))->withoutSubjects(),
            'subject' => new SubjectResource($this->subject),
            'is_compulsory' => $this->is_compulsory,
            'display_order' => $this->display_order,
            'teachers' => TeacherCurriculumSubjectResource::collection($this->teacherAssignments),
            'marking_components' => MarkingComponentResource::collection($this->markingComponents),
            'students' => StudentSubjectResource::collection($this->whenLoaded('studentAssignments')),
            'scores' => ScoreResource::collection($this->whenLoaded('scores')),
        ];
    }
}
