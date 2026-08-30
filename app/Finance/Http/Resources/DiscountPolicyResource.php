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
            // Inert on an `amount` basis (nothing to take a percentage of) and never null — the
            // catalog column is NOT NULL with a default. Exposed so the catalog reads the same way
            // the change queue does; a policy list that cannot tell "half the tuition" from "half
            // the bill" is a list of amounts nobody can check.
            'base' => $this->base->value,
            'requires_approval' => $this->requires_approval,
            'status' => $this->status->value,
        ];
    }
}
