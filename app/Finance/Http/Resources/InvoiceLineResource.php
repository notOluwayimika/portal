<?php

namespace App\Finance\Http\Resources;

use App\Finance\Models\InvoiceLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin InvoiceLine
 */
class InvoiceLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'description' => $this->description,
            'amount' => $this->amount, // Money → {amount_minor, currency}
        ];
    }
}
