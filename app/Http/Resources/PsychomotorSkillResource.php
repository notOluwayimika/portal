<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PsychomotorSkillResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'drawing_colouring' => $this->drawing_colouring?->value,
            'cutting_pasting' => $this->cutting_pasting?->value,
            'puzzles_building' => $this->puzzles_building?->value,
            'climbing_sliding' => $this->climbing_sliding?->value,
            'comment' => $this->comment,
            'updated_at' => $this->updated_at,
        ];
    }
}
