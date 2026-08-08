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
            'name' => $this->name,
            'basis' => $this->basis?->value,
            'value_minor' => $this->value_minor,
            'value_currency' => $this->value_currency,
            'percent' => $this->percent,
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
