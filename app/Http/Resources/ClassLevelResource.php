<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClassLevelResource extends JsonResource
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
            'name' => $this->name,
            'arms' => ArmResource::collection($this->arms),
            'class_level_arms' => ClassLevelArmResource::collection($this->whenLoaded('classLevelArms')),
            'order' => $this->order,
            'grading_mode' => $this->grading_scheme_id ? 'categorical' : 'numeric',
            'grading_scheme' => $this->gradingScheme ? new GradingSchemeResource($this->gradingScheme) : null,
        ];
    }
}
