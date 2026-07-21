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
            // `kind` tells the client whether this line is a charge or a reduction, so
            // §5's "full fee above, reduction beneath" grouping is a presentation
            // decision the client can make WITHOUT recomputing anything.
            'kind' => $this->kind->value,
            'note' => $this->note,
            // Money → {amount_minor, currency}. Negative for reductions: the sign IS
            // the arithmetic, and it is never netted away into a single figure.
            'amount' => $this->amount,
        ];
    }
}
