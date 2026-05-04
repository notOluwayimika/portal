<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GradeBoundaryResource extends JsonResource
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
            'min_score' => $this->min_score,
            'max_score' => $this->max_score,
            'grade' => $this->grade,
            'label' => $this->label,
        ];
    }
}
