<?php

namespace App\Finance\Http\Resources;

use App\Finance\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The JSON shape of a payment. This is currently the ONLY surface that renders one — there is no
 * receipt PDF, printable page, emailed confirmation or export over finance_payments anywhere.
 *
 * WHOEVER BUILDS THE FIRST ONE owes the migrated-payment refusal. A row with origin = 'migrated' was
 * collected by WCBS before the cutover; nobody at Brookstone handed that parent this system's receipt,
 * so the receipt action must REFUSE for it with a stated reason rather than silently hide the row. See
 * docs/handoff/opening-balance-import-spec.md §4, "THE RECEIPT REFUSAL IS OWED, NOT BUILT".
 *
 * `origin` is deliberately NOT exposed here: it is an internal provenance predicate for reports and the
 * general-ledger export, and adding it to this payload is a separate decision with its own consumer.
 *
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
