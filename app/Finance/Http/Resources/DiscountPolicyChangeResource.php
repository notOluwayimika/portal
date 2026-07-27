<?php

namespace App\Finance\Http\Resources;

use App\Finance\Models\DiscountPolicyChange;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DiscountPolicyChange */
class DiscountPolicyChangeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'kind' => $this->kind->value,
            'target_policy_id' => $this->target_policy_id,
            'name' => $this->name,
            'basis' => $this->basis?->value,
            'value_minor' => $this->value_minor,
            'value_currency' => $this->value_currency,
            'percent' => $this->percent,
            'requires_approval' => $this->requires_approval,
            'reason' => $this->reason,
            'status' => $this->status->value,
            'rejection_reason' => $this->rejection_reason,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
