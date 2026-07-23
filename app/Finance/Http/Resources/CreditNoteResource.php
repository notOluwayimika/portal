<?php

namespace App\Finance\Http\Resources;

use App\Finance\Models\CreditNote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A credit note as its OWN document (§5/§7 integrity). It appears BESIDE its invoice
 * on a statement, never folded into a netted figure — the invoice keeps showing its
 * full amount and this shows the separate credit.
 *
 * @mixin CreditNote
 */
class CreditNoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'number' => $this->number,
            'display_number' => $this->displayNumber(),
            'invoice_id' => $this->invoice_id,
            'kind' => $this->kind->value,
            // Money → {amount_minor, currency} via the VO — the only sanctioned wire shape.
            'amount' => $this->amount,
            'note' => $this->note,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
