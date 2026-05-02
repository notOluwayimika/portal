<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClassLevelArmResource extends JsonResource
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
            'class_level' => new ClassLevelResource($this->classLevel),
            'arm' => new ArmResource($this->arm),
            'stream' => new StreamResource($this->stream)
        ];
    }
}
