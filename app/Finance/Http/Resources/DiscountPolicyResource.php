<?php

namespace App\Finance\Http\Resources;

use App\Finance\Models\DiscountPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DiscountPolicy */
class DiscountPolicyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'description' => $this->description,
            'basis' => $this->basis->value,
            'value_minor' => $this->value_minor,
            'value_currency' => $this->value_currency,
            'percent' => $this->percent,
            'requires_approval' => $this->requires_approval,
            'status' => $this->status->value,
        ];
    }
}
