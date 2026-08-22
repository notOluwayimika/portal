<?php

namespace App\Finance\Http\Resources;

use App\Finance\Models\Invoice;
use App\Finance\Models\VoidRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A void request as its OWN document (Ph3b) — the maker-checker twin of {@see CreditNoteResource}.
 * `status` distinguishes a pending PROPOSAL (invoice still issued, in the balance, no money moved)
 * from an APPROVED one (invoice voided, reversal posted) from a REJECTED one (charge stands).
 *
 * `type: 'void'` is the discriminator that lets the unified approvals queue render credit notes
 * and void requests in one table with a type column — CreditNoteResource carries `type: 'credit_note'`.
 *
 * `amount` is the invoice TOTAL — the full charge a void reverses — surfaced so the queue shows the
 * money at stake without a second round-trip. `can_approve` / `can_reject` are POLICY-computed and
 * viewer-relative (false on one's own submission — maker ≠ checker); the Policy is the real guard,
 * these flags only shape the UI.
 *
 * @mixin VoidRequest
 */
class VoidRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $invoice = $this->invoice instanceof Invoice ? $this->invoice : null;

        return [
            'type' => 'void',
            'id' => $this->uuid,
            'invoice_id' => $this->invoice_id,
            'invoice_display_number' => $invoice?->displayNumber(),
            // The kind beside the number, for the reason CreditNoteResource carries it: a number
            // alone stopped naming one document once an episode could hold a term bill and a
            // supplementary charge at the same time (U7).
            'invoice_kind' => $invoice?->kind->value,
            // The reversal at stake = the invoice's full total, in the sanctioned wire shape.
            'amount' => $invoice?->total,
            // `reason` is the maker's justification (credit note calls its free text `note`); the
            // unified queue reads both under one column, so expose it under `note` too.
            'reason' => $this->reason,
            'note' => $this->reason,
            'status' => $this->status->value,
            'submitted_by_name' => $this->whenLoaded('submittedBy', fn () => $this->submittedBy instanceof User ? $this->submittedBy->name : null),
            // THE CHECKER (U14) — CreditNoteResource's twin field, present only where `decidedBy` is
            // eager-loaded, so the pending queue's payload does not change. maker ≠ checker is
            // enforced by the Policy and by `finance_void_requests`'s CHECK; this is where the two
            // names become readable, which is the whole point of recording them.
            'decided_by_name' => $this->whenLoaded('decidedBy', fn () => $this->decidedBy instanceof User ? $this->decidedBy->name : null),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            // Policy-computed, viewer-relative (approve/reject disabled on one's own submission).
            'can_approve' => $user !== null && $user->can('approve', $this->resource),
            'can_reject' => $user !== null && $user->can('reject', $this->resource),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
