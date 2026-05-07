<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TermResource extends JsonResource
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
            'full_name' => $this->academicSession->name . ' - ' . $this->name,
            'slug' => $this->slug,
            'order' => $this->order,
            'status' => $this->status,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'registration_deadline' => $this->registration_deadline,
            'result_visible_at' => $this->result_visible_at,
            'academic_session' => new SessionResource($this->whenLoaded('academicSession')),
        ];
    }
}
