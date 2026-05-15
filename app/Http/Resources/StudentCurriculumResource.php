<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentCurriculumResource extends JsonResource
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
            'curriculum' => new CurriculumResource($this->whenLoaded('curriculum')),
            'promoted_to' => new CurriculumResource($this->whenLoaded('promotedTo')),
            'subjects' => StudentSubjectResource::collection($this->whenLoaded('studentSubjects')),
            'status' => $this->status,
        ];
    }
}
