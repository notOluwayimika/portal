<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubjectResultStatusResource extends JsonResource
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
            'status' => $this->status,
            'curriculum_subject' => new CurriculumSubjectResource($this->whenLoaded('curriculumSubject')),
            'rejection_reason' => $this->rejection_reason,
            'updated_at' => optional($this->updated_at)->toIso8601String(),
            'updated_by' => $this->updatedBy ? new UserResource($this->updatedBy) : null,
        ];
    }
}
