<?php

namespace App\Finance\Http\Resources;

use App\Finance\Models\CreditNote;
use App\Finance\Models\Invoice;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A credit note as its OWN document (§5/§7 integrity). It appears BESIDE its invoice on a
 * statement, never folded into a netted figure.
 *
 * Ph3 — the note now carries its maker-checker lifecycle. `status` distinguishes a pending
 * PROPOSAL (no money moved) from an APPROVED document (in the balance) from a REJECTED one.
 *
 * `can_approve` / `can_reject` are computed by the POLICY (`$user->can('approve', $note)`),
 * not re-derived here — a single source of truth for the maker ≠ checker rule. A checker
 * sees them false on their OWN submission and true on others', and the frontend never needs
 * to know the SoD rule; it just reads these flags (the Policy is what actually stops the
 * request).
 *
 * @mixin CreditNote
 */
class CreditNoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            // Discriminator for the unified approvals queue (credit note vs void request).
            'type' => 'credit_note',
            'id' => $this->uuid,
            'number' => $this->number,
            'display_number' => $this->displayNumber(),
            'invoice_id' => $this->invoice_id,
            'invoice_display_number' => $this->whenLoaded('invoice', fn () => $this->invoice instanceof Invoice ? $this->invoice->displayNumber() : null),
            'kind' => $this->kind->value,
            // Money → {amount_minor, currency} via the VO — the only sanctioned wire shape.
            'amount' => $this->amount,
            'note' => $this->note,
            'status' => $this->status->value,
            'submitted_by_name' => $this->whenLoaded('submittedBy', fn () => $this->submittedBy instanceof User ? $this->submittedBy->name : null),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            // Policy-computed, viewer-relative (approve/reject disabled on one's own submission).
            'can_approve' => $user !== null && $user->can('approve', $this->resource),
            'can_reject' => $user !== null && $user->can('reject', $this->resource),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
