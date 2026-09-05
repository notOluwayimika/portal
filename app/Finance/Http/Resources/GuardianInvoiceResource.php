<?php

namespace App\Finance\Http\Resources;

use App\Finance\Models\Invoice;
use App\Finance\Services\InvoiceSettlement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ONE INVOICE AS A PAYER SEES IT — the parent portal's invoice shape, and deliberately NOT
 * InvoiceResource.
 *
 * WHY A SECOND RESOURCE RATHER THAN A REUSE. InvoiceResource carries `can_record_payment`,
 * `can_submit_credit_note`, `can_request_void`, `void_blocked_reason`, `lines`, `status`, the raw
 * `number` and `cancel_reason`. Every one of those is either a STAFF eligibility flag — the answer
 * to "which buttons does the bursar get" — or internal document state. A parent is not a bursar and
 * must never be handed the bursar's answer: shipping `can_request_void` to a payer surface tells a
 * payer about an internal control they have no part in, and `void_blocked_reason` narrates the
 * school's own accounting position back to the person it is about. Reuse here would have been a data
 * leak of exactly the kind the two guardian-authorisation fixes that precede this commit closed.
 *
 * WHAT A PAYER NEEDS TO DECIDE AND PAY, and nothing beyond it:
 *   id                 the invoice's uuid — the identifier a payment is initiated against
 *   display_number     what the parent sees on the document ("BSS-000042")
 *   kind               WHAT the charge is: the term bill (`scheduled`) or a one-off
 *                      (`supplementary`). An episode can carry both at once, so a number alone no
 *                      longer says which document this is.
 *   academic_context   WHICH term/class it belongs to
 *   total              what was billed
 *   outstanding        what is still owed — the figure the parent is being asked for
 *
 * ABSENT ON PURPOSE, so a later reader does not read the omission as an oversight: `status` (the
 * read excludes void, so every invoice here is issued), `settlement_state` (derivable from `total`
 * and `outstanding`, and one axis is enough on a payer surface), `number` (the internal integer),
 * `billed_to_name` (the parent knows who they are), ~~`lines`~~, `cancelled_at`, `cancel_reason`. The
 * portal is being built against this shape, so the shape freezes: adding a field later is easy,
 * removing one from a contract someone has built against is not.
 *
 * ── `lines` IS NO LONGER ABSENT — SUPERSEDED BY RULING, 2026-09-05 ─────────────────────────────
 *
 * The reasoning above listed `lines` among the deliberate omissions, on the principle that a payer
 * needs what lets them DECIDE AND PAY. **That reasoning is kept rather than deleted, because it was
 * sound for the question it answered and wrong about a different one.**
 *
 * It is right that a payer deciding WHETHER to pay needs only the outstanding figure. It is wrong
 * about a payer deciding WHAT THEY ARE PAYING FOR — and a drive of this screen with a real term bill
 * on it found the consequence: a parent asked for NGN 247,500 against a document number and a term
 * label, with no way to see it comprised tuition, a levy and a discount. Meanwhile the confirmation
 * screen beside it states the payment processing charge to the naira. A parent could see exactly
 * what the provider took and nothing about what the school was charging.
 *
 * **The ruling is all-or-nothing, and the code is why.** A discount is a LINE carrying a negative
 * amount — `InvoiceLine`'s own docblock: *"the SIGN of `amount` … the literal signed SUM(lines) that
 * never branches on kind"*. So showing lines shows discounts automatically, and hiding a discount
 * would require filtering the collection — extra work, and it breaks the sum. A parent would read
 * `Tuition NGN 300,000` above `Total NGN 247,500` and have arithmetic they cannot reconcile, which
 * is worse than the opaque row this replaced.
 *
 * `GuardianInvoiceLineResource` decides the line's own shape field by field, on the same principle
 * that made this class not `InvoiceResource`.
 *
 * THE INVOICE MUST ARRIVE THROUGH THE READ MODEL. `outstanding` is derived by InvoiceSettlement,
 * which reads the `allocated_minor` / `approved_credit_minor` aggregates off the model and treats an
 * ABSENT one as zero — so an Invoice handed here without passing through
 * `outstandingForStudent` (app/Finance/Services/InvoiceReadModel.php) serialises `outstanding` equal
 * to its FULL TOTAL, silently. On this surface that is a parent asked to pay a bill they have
 * already settled. There is one caller and it goes through the read model; keep it that way.
 *
 * MONEY IS THE VO's WIRE SHAPE — `{amount_minor, currency}` (ADR 0037/0039). Never a decimal, never
 * a formatted string, never both: the frontend does no monetary arithmetic and a lint enforces it.
 *
 * @mixin Invoice
 */
class GuardianInvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $settlement = (new InvoiceSettlement)->for($this->resource);

        return [
            'id' => $this->uuid,
            'display_number' => $this->displayNumber(),
            'kind' => $this->kind->value,
            'academic_context' => $this->academic_context,
            'total' => $this->total,
            'outstanding' => $settlement['outstanding'],
            // WHAT THE BILL COMPRISES. `whenLoaded` because the read model is the only sanctioned
            // way in and it eager-loads them — an invoice arriving without the relation returns no
            // lines rather than triggering a query per invoice on a list.
            'lines' => GuardianInvoiceLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
