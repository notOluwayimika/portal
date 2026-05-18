<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScoreResource extends JsonResource
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
            'marking_component' => new MarkingComponentResource($this->whenLoaded('markingComponent')),
            'score' => $this->score,
            'created_by' => new UserResource($this->whenLoaded('created_by')),
        ];
    }
}
