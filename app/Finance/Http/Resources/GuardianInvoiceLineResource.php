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
 * `note` — REFUSED PERMANENTLY, and this is a position rather than a pending question.
 *
 * It is operator free text (`GenerateInvoiceRequest`) with no declared audience. **A field's
 * audience is a property of how it was WRITTEN, not of where it is displayed.** Every note in the
 * table was typed by a member of staff who believed only staff would read it. Publishing them to
 * parents does not make that text safe; it makes it visible. It may read "pro-rata, joined
 * mid-term", which a parent would want — and over enough rows it will also read "mother disputes,
 * chased twice", which is the school narrating its own position back to the person it is about.
 * That is not a risk at scale, it is a certainty, and it is the exact reason `void_blocked_reason`
 * is refused one level up.
 *
 * **If a school wants a parent to understand why a line reads as it does, that is a NEW field**,
 * written by someone who knows a parent will read it. Retro-fitting an audience onto existing text
 * is the thing that cannot be done.
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
