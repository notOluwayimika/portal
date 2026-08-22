<?php

namespace App\Finance\Http\Resources;

use App\Finance\Models\Invoice;
use App\Finance\Services\InvoiceSettlement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Invoice
 */
class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Per-invoice settlement + eligibility — DERIVED server-side (the server owns the rule,
        // the UI renders it; Decisions 1/2/3/4). Two orthogonal axes: `status` is the DOCUMENT
        // state (issued/void); `settlement_state` + `outstanding` are the SETTLEMENT state.
        $settlement = (new InvoiceSettlement)->for($this->resource);

        return [
            'id' => $this->uuid,
            // The stored integer, unchanged — existing consumers keep working.
            'number' => $this->number,
            // …and the human-facing form beside it. Additive: no field changed shape.
            'display_number' => $this->displayNumber(),
            'status' => $this->status->value,
            // WHAT THE DOCUMENT IS, beside what state it is in. `status` says issued/void; this says
            // term bill or one-off charge, and the two are orthogonal (InvoiceKind's docblock:
            // "DELIBERATELY NOT A STATUS"). It is here because an episode can now carry an active
            // term bill AND any number of live supplementary charges at once (#259 re-keyed the
            // generated column to permit exactly that), so a number on a screen no longer implies
            // which document it is. This resource is the ONLY invoice serialiser in the codebase —
            // both generate routes answer their 201 through it and the per-student read returns a
            // collection of it — so a client could not distinguish the two kinds by any route until
            // this line existed. See docs/handoff/tickets/nothing-shows-which-invoices-are-
            // supplementary.md §1, which is what this closes.
            'kind' => $this->kind->value,
            'billed_to_name' => $this->billed_to_name,
            'academic_context' => $this->academic_context,
            // Money serialises to {amount_minor, currency} via the VO — the only
            // sanctioned wire shape (ADR 0037/0039; never a decimal, never raw).
            'total' => $this->total,
            // Settlement axis (displayed outstanding floors at zero; the account balance carries
            // the true credit position when a paid invoice is later credit-noted).
            'outstanding' => $settlement['outstanding'],
            'settlement_state' => $settlement['settlement_state'],
            // Eligibility flags — the UI gates buttons on these, never on client-side arithmetic.
            'can_record_payment' => $settlement['can_record_payment'],
            'can_submit_credit_note' => $settlement['can_submit_credit_note'],
            'can_request_void' => $settlement['can_request_void'],
            'void_blocked_reason' => $settlement['void_blocked_reason'],
            'lines' => InvoiceLineResource::collection($this->whenLoaded('lines')),
            'cancelled_at' => optional($this->cancelled_at)->toIso8601String(),
            'cancel_reason' => $this->cancel_reason,
        ];
    }
}
