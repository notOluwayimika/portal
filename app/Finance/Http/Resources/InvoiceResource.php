<?php

namespace App\Finance\Http\Resources;

use App\Finance\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Invoice
 */
class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            // The stored integer, unchanged — existing consumers keep working.
            'number' => $this->number,
            // …and the human-facing form beside it. Additive: no field changed shape.
            'display_number' => $this->displayNumber(),
            'status' => $this->status->value,
            'billed_to_name' => $this->billed_to_name,
            'academic_context' => $this->academic_context,
            // Money serialises to {amount_minor, currency} via the VO — the only
            // sanctioned wire shape (ADR 0037/0039; never a decimal, never raw).
            'total' => $this->total,
            'lines' => InvoiceLineResource::collection($this->whenLoaded('lines')),
            'cancelled_at' => optional($this->cancelled_at)->toIso8601String(),
            'cancel_reason' => $this->cancel_reason,
        ];
    }
}
