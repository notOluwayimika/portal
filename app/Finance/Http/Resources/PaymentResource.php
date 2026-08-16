<?php

namespace App\Finance\Http\Resources;

use App\Finance\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The JSON shape of a payment. It is no longer the only surface that renders one: U11 added the
 * printable receipt page (GET /finance/payments/{payment:uuid}/receipt), which resolves its own
 * document server-side and does not read this payload.
 *
 * THE MIGRATED-PAYMENT REFUSAL THIS DOCBLOCK USED TO OWE IS BUILT. A row with origin = 'migrated'
 * was collected by WCBS before the cutover; nobody at Brookstone handed that parent this system's
 * receipt, so the receipt route refuses for it with a stated reason (403 + the reason rendered on
 * the page). That refusal is the CONTROL and it is server-side; the two fields below are what let
 * the statement row state the same rule in place, so an operator reads it without navigating.
 *
 * `origin` IS STILL NOT EXPOSED, and the fields added are not a rename of it. `origin` is the
 * provenance axis every collections report and the general-ledger export turns on; putting it on the
 * wire invites the client to build provenance logic, which stays a separate decision with its own
 * consumer. What the client actually needs is narrower — "may I offer a receipt for this row, and if
 * not, what do I tell the operator?" — so that is what it gets, in the shape this codebase already
 * uses for exactly this (`can_request_void` + `void_blocked_reason`, InvoiceSettlement:32-34:
 * "the flag is false with a void_blocked_reason, so the UI disables-with-reason rather than hides").
 *
 * Said plainly, because it would be dishonest to imply otherwise: `receiptable: false` today means
 * `origin = 'migrated'`, so the same bit is inferable. What differs is the CONTRACT, not the current
 * information content — the flag promises receiptability, so if the predicate ever widens (a
 * reversed payment, say) the flag keeps its meaning where a leaked `origin` would quietly change
 * what a client believed about provenance.
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
            // Receipt eligibility, DERIVED server-side from `origin` (Payment::isReceiptable). The
            // UI renders these; it never re-derives them, and it never hides a row on them.
            'receiptable' => $this->isReceiptable(),
            'receipt_refusal_reason' => $this->receiptRefusalReason(),
            'allocations' => $this->whenLoaded('allocations', fn () => $this->allocations->map(fn ($a) => [
                'id' => $a->uuid,
                'invoice_id' => $a->invoice_id,
                'amount' => $a->amount,
            ])),
        ];
    }
}
