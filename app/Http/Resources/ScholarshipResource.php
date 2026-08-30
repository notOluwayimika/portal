<?php

namespace App\Http\Resources;

use App\Models\Scholarship;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Scholarship
 */
class ScholarshipResource extends JsonResource
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
            'uuid' => $this->uuid,
            'name' => $this->name,
            // NULL is carried as null and NOT flattened to a label or an empty string. The screen has
            // to be able to render "not configured" as its own state — a scholarship nobody has
            // classified is refused by both the bulk invoice run and AwardStudentDiscount, so it is a
            // thing the operator must be able to SEE, not an absent field that reads as a blank cell.
            'kind' => $this->kind?->value,
        ];
    }
}
