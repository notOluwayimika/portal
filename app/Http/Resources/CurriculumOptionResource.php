<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CurriculumOptionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'term' => $this->term?->order,
            'term_name' => $this->term?->name,
            'class_level' => $this->classLevelArm?->classLevel?->name,
            'arm' => $this->classLevelArm?->arm?->label,
            'stream' => $this->classLevelArm?->stream?->name,
            'full_name' => $this->full_name,
        ];
    }
}
