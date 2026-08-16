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
 * AND THE NEW FIELDS LEAK NOTHING, because the migrated bit was ALREADY FULLY LEGIBLE on this exact
 * payload before they existed — twice over, not by inference:
 *
 *   • `reference` (below) is drawn for a migrated row from the reserved band at or above
 *     Payment::MIGRATED_REFERENCE_FLOOR = 900,000,000, allocated in PostOpeningBalanceBatch; a
 *     portal receipt number is a small integer from a counter that starts at 0. A nine-digit
 *     reference beside a four-digit one is not a hint, it is the answer.
 *   • `payer_name` (below) is written by that same Action as
 *     PAYER_NAME_PREFIX.$batch_reference.PAYER_NAME_SUFFIX — literally
 *     "Balance brought forward (WCBS batch …)". It names the previous system in the string.
 *
 * Both render today in the statement's payments tab, and the U11 drive shows them doing it:
 * `{"cells":["Balance brought forward (WCBS batch WCBS-DRIVE-1)","#900000001","migrated", …]}`.
 * `method` is a third tell ('migrated'). So the question these two fields answer was never
 * "should the client be able to tell?" — it already could — but "should the client have to INFER
 * the rule from a number's magnitude and a sentence's wording, and hard-code its own copy of the
 * refusal?" A derived flag with the server's own reason is the answer to that, and it removes a
 * client-side rule rather than adding a disclosure.
 *
 * What still differs from exposing `origin` is the CONTRACT: the flag promises receiptability, so
 * if the predicate ever widens (a reversed payment, say) the flag keeps its meaning where a leaked
 * `origin` would quietly change what a client believed about provenance.
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
