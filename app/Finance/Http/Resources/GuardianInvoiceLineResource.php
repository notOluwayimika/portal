<?php

namespace App\Finance\Http\Resources;

use App\Finance\Models\InvoiceLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ONE LINE OF A BILL AS A PAYER SEES IT — an allowlist, not a pass-through.
 *
 * ── WHY NOT `InvoiceLineResource` ──
 *
 * Same reason {@see GuardianInvoiceResource} is not `InvoiceResource`: the payer shape is decided
 * field by field rather than inherited. The staff line resource ships `id` and `note` as well as the
 * three below, and both are staff answers to staff questions.
 *
 * THREE FIELDS ADMITTED, and each because a parent comparing this screen against a paper invoice
 * needs it:
 *
 *   description  WHAT the charge is — "Tuition", "Development levy". The whole subject of the
 *                ruling that produced this class: a parent asked for a term's fees can now see what
 *                they comprise.
 *   kind         charge / waiver / discount. The client groups reductions beneath charges WITHOUT
 *                recomputing anything, exactly as the staff screen does — the presentation decision
 *                is the client's, the arithmetic is not.
 *   amount       the Money wire shape, SIGNED. Negative on a reduction, because the sign IS the
 *                arithmetic and the lines must sum to the invoice total.
 *
 * ── WHAT IS REFUSED, AND WHY EACH ──
 *
 * `id` / `uuid` — a payer takes no action on a single line. An identifier on the wire invites an
 * endpoint that accepts one, and this surface's whole security property is that it carries no
 * identifier a caller could tamper with.
 *
 * `note` — operator free text (`GenerateInvoiceRequest`), with NO STATED AUDIENCE. It may read
 * "pro-rata, joined mid-term", which a parent would want; it may equally read "mother disputes,
 * chased twice", which is the school narrating its own position back to the person it is about —
 * the exact reason `void_blocked_reason` is refused one level up. Publishing it to payers is a
 * decision nobody has taken, so it is refused pending one rather than shipped by default.
 *
 * `fee_item_id` / `discount_policy_id` — catalogue references. The parent gets the DESCRIPTION and
 * the AMOUNT, which is what a bill says; which internal row priced it is not their question.
 *
 * `bank_account_id` — the destination the school routes this money to. Internal, and on a payment
 * surface it would be actively misleading.
 *
 * `created_by_user_id` — a staff member's identity, on a parent's screen.
 *
 * `created_at` / `updated_at` — when a clerk touched the row is not when the charge applies.
 *
 * @mixin InvoiceLine
 */
class GuardianInvoiceLineResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'description' => $this->description,
            // The label the model already carries. NOT re-worded for parents: a family compares this
            // screen against a paper invoice, and inventing friendlier names here would put two
            // vocabularies on one bill.
            'kind' => $this->kind->value,
            // SIGNED, never netted. A discount is a negative line, and the lines summing to the
            // total is the property the whole ruling rests on — omit the sign and a parent reads
            // charges that do not reconcile with what they are asked to pay.
            'amount' => $this->amount,
        ];
    }
}
