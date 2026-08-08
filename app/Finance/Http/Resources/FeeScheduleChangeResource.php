<?php

namespace App\Finance\Http\Resources;

use App\Finance\Models\FeeScheduleChange;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A fee-schedule change request. §9 step 5a brought it onto the unified approvals queue, which it had
 * been eligible for and absent from since it shipped: the feed was live and ability-gated and the
 * page rendered two hardcoded imports, so an approver holding finance.fee-schedule.change.approve had
 * no screen. The additions below are what "on the queue" actually costs — the queue keys its type
 * badge, its row label, its submitter column and its decision buttons off exactly these fields, and
 * a row missing them renders as an untyped, unlabelled, permanently-disabled line.
 *
 * The added fields MIRROR {@see CreditNoteResource} and {@see VoidRequestResource} rather than
 * improving on them, deliberately. All five types answering in one shape is the mechanism that lets
 * the page enumerate its feeds instead of special-casing them; any divergence here is where the next
 * type gets hardcoded back in.
 *
 * `note` duplicates `reason` for the queue's single reason column — VoidRequestResource does the same
 * thing for the same reason, and `reason` stays because the governance screens read it.
 *
 * `amount` is NULL, and that is a fact rather than a gap: approving a publish/retire moves no money.
 * The queue renders '—'. The nullability is already in the shape (a void's `amount` is null when its
 * invoice is missing), so this costs the consumer nothing.
 *
 * `can_approve` / `can_reject` are POLICY-computed and viewer-relative, exactly as the two working
 * types do it (FeeScheduleChangePolicy — permission AND maker ≠ checker). The page must never infer
 * button state from abilities it holds locally; the Policy is the real guard and these only shape the
 * UI.
 *
 * @mixin FeeScheduleChange
 */
class FeeScheduleChangeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            // Discriminator for the unified approvals queue.
            'type' => 'fee_schedule_change',
            'id' => $this->uuid,
            'kind' => $this->kind->value,
            'target_schedule_id' => $this->target?->uuid,
            'reason' => $this->reason,
            // The queue reads every type's free text under one column.
            'note' => $this->reason,
            // No money moves on approval; the queue shows '—'.
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
