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
 * `billed_to_name` (the parent knows who they are), `lines`, `cancelled_at`, `cancel_reason`. The
 * portal is being built against this shape, so the shape freezes: adding a field later is easy,
 * removing one from a contract someone has built against is not.
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
        ];
    }
}
