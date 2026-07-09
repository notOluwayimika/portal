<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BehavioralAssessmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'punctuality' => $this->punctuality?->value,
            'mental_alertness' => $this->mental_alertness?->value,
            'respect' => $this->respect?->value,
            'neatness' => $this->neatness?->value,
            'politeness' => $this->politeness?->value,
            'honesty' => $this->honesty?->value,
            'relationship_with_peers' => $this->relationship_with_peers?->value,
            'teamwork' => $this->teamwork?->value,
            'perseverance' => $this->perseverance?->value,
            'comment' => $this->comment,
            'updated_at' => $this->updated_at,
        ];
    }
}
