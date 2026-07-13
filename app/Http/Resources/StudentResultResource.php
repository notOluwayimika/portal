<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentResultResource extends JsonResource
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
            'student' => new StudentResource($this->whenLoaded('student')),
            'curriculum_subject' => new CurriculumSubjectResource($this->whenLoaded('curriculumSubject')),
            'total_score' => $this->total_score,
            'grade' => $this->grade,
            'grading_item' => $this->gradingSchemeItem ? [
                'id' => $this->gradingSchemeItem->uuid,
                'code' => $this->gradingSchemeItem->code,
                'label' => $this->gradingSchemeItem->label,
            ] : null,
            'status' => $this->status,
            'approved_by' => new UserResource($this->whenLoaded('approvedBy')),
            'approved_at' => $this->approved_at,
        ];
    }
}
