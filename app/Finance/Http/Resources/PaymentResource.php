<?php

namespace App\Finance\Http\Resources;

use App\Finance\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Payment
 */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'reference' => $this->reference,
            'payer_name' => $this->payer_name,
            'method' => $this->method,
            'amount' => $this->amount, // Money → {amount_minor, currency}
            'created_at' => $this->created_at->toIso8601String(),
            'allocations' => $this->whenLoaded('allocations', fn () => $this->allocations->map(fn ($a) => [
                'id' => $a->uuid,
                'invoice_id' => $a->invoice_id,
                'amount' => $a->amount,
            ])),
        ];
    }
}
