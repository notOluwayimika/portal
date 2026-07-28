<?php

namespace App\Finance\Http\Resources;

use App\Finance\Models\FeeScheduleChange;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FeeScheduleChange */
class FeeScheduleChangeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'kind' => $this->kind->value,
            'target_schedule_id' => $this->target?->uuid,
            'reason' => $this->reason,
            'status' => $this->status->value,
            'rejection_reason' => $this->rejection_reason,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
