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
            'id'                 => $this->uuid,
            'status'             => $this->status?->value,
            'student_curriculum' => $this->whenLoaded('studentCurriculum', fn () => new StudentCurriculumResource($this->studentCurriculum)),
            'curriculum_subject' => $this->whenLoaded('curriculumSubject', fn () => new CurriculumSubjectResource($this->curriculumSubject)),
            'dropped_at'         => $this->dropped_at?->toIso8601String(),
            'drop_reason'        => $this->drop_reason,
            'dropped_by'         => $this->whenLoaded('droppedBy', fn () => [
                'id'        => $this->droppedBy?->id,
                'full_name' => $this->droppedBy?->full_name,
            ]),
            'restored_at'        => $this->restored_at?->toIso8601String(),
            'restored_by'        => $this->whenLoaded('restoredBy', fn () => [
                'id'        => $this->restoredBy?->id,
                'full_name' => $this->restoredBy?->full_name,
            ]),
        ];
    }
}
