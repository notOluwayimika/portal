<?php

namespace App\Finance\Http\Resources;

use App\Finance\Models\DiscountPolicyChange;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A discount-policy change request — the twin of {@see FeeScheduleChangeResource}, brought onto the
 * unified approvals queue by §9 step 5a for the same reason and at the same cost. Read that class's
 * docblock: every note there (why the added fields mirror rather than improve, why `note` duplicates
 * `reason`, why `amount` is null, why the flags are Policy-computed) applies here unchanged.
 *
 * `amount` is null even though this type DOES carry a figure: `value_minor` / `percent` are the
 * policy's PARAMETERS, not a sum that moves on approval, and putting a rate in the column the queue
 * renders through formatNaira would print a discount rate as if it were money at stake. The
 * parameters stay exposed under their own keys for the governance screens.
 *
 * @mixin DiscountPolicyChange
 */
class DiscountPolicyChangeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            // Discriminator for the unified approvals queue.
            'type' => 'discount_policy_change',
            'id' => $this->uuid,
            'kind' => $this->kind->value,
            'target_policy_id' => $this->target_policy_id,
            // THE SUBJECT OF THE DECISION, and a RETIRE is the case that needs it. The
            // `…_terms_shape` CHECK (2026_07_26_140001:76-84) forces `name`, `basis` and
            // `requires_approval` to be NULL on a retire and NOT NULL on everything else — so a
            // create and an amend name themselves, and a retire carries nothing whatsoever except
            // `target_policy_id`, an internal integer. Two pending retires are then two rows
            // reading "Discount policy" and nothing else. This is the only field that tells them
            // apart.
            'target_policy_name' => $this->whenLoaded('target', fn () => $this->target?->name),
            'name' => $this->name,
            'basis' => $this->basis?->value,
            'value_minor' => $this->value_minor,
            'value_currency' => $this->value_currency,
            'percent' => $this->percent,
            // AXIS C, ON THE CHECKER'S SIDE. A checker approves what this resource shows them, so a
            // proposed term absent from here is a term decided unseen — "50%" reads identically
            // whether it means half the tuition or half the whole bill, and those are different
            // amounts of money. Nullable: a retire proposes no terms and an amount basis has none.
            'base' => $this->base?->value,
            'requires_approval' => $this->requires_approval,
            'reason' => $this->reason,
            // The queue reads every type's free text under one column.
            'note' => $this->reason,
            // A rate is not money at stake; the queue shows '—'. See the class docblock.
            'amount' => null,
            'status' => $this->status->value,
            'submitted_by_name' => $this->whenLoaded('submitter', fn () => $this->submitter instanceof User ? $this->submitter->name : null),
            'rejection_reason' => $this->rejection_reason,
            // Policy-computed, viewer-relative (approve/reject disabled on one's own submission).
            'can_approve' => $user !== null && $user->can('approve', $this->resource),
            'can_reject' => $user !== null && $user->can('reject', $this->resource),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
