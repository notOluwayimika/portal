<?php

namespace App\Finance\Http\Resources;

use App\Finance\Models\OpeningBalanceBatch;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A submitted opening-balance batch as a queue row (§9 step 5a) — the FIFTH member of the unified
 * approvals shape, mirroring {@see CreditNoteResource} and {@see VoidRequestResource} field for
 * field. It is deliberately NOT a richer shape: the whole point of 5a is that the queue renders
 * every type from one declared list, and a type that reports itself differently is the seam a
 * hardcoded special case grows back into.
 *
 * `type: 'opening_balance'` is the discriminator. `amount` is the batch's `control_total` — §1's L2
 * witness, the operator-typed figure the extract claims — because approving this batch posts that
 * whole position into the subledger in one transaction. It is null only on a pre-4a batch, which is
 * the same nullability the void resource already carries on `amount`.
 *
 * `can_approve` / `can_reject` are POLICY-computed exactly as the four siblings do it — never
 * inferred by the page from abilities it holds locally.
 *
 * THEY WERE FALSE FOR EVERY VIEWER UNTIL §9 STEP 5b-ii, and that prediction is worth keeping because
 * it came true: 5a wrote them through the Gate while no OpeningBalanceBatchPolicy existed to consult,
 * on the reasoning that the decision surface would then flip them on by registering a policy, with no
 * edit here. 5b-ii added OpeningBalanceBatchPolicy and the two approve/reject routes, and this file
 * was not touched. The queue's row is decidable now; the declared feed list carries its decision urls,
 * and — because approving a batch POSTS the cutover irreversibly — the one confirmation on that
 * screen.
 *
 * @mixin OpeningBalanceBatch
 */
class OpeningBalanceBatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'type' => 'opening_balance',
            'id' => $this->uuid,
            // The batch's own human handle — the credit note's `display_number` equivalent.
            'batch_reference' => $this->batch_reference,
            // No invoice is involved in a cutover batch; the queue's invoice column reads '—'.
            'invoice_id' => null,
            'invoice_display_number' => null,
            // Money → {amount_minor, currency} via the VO — the only sanctioned wire shape.
            'amount' => $this->control_total,
            // The maker leaves no free-text justification on a batch (the file and its findings are
            // the submission), so the queue's reason column is empty for this type.
            'note' => null,
            'status' => $this->status->value,
            'submitted_by_name' => $this->whenLoaded('submittedBy', fn () => $this->submittedBy instanceof User ? $this->submittedBy->name : null),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            // Policy-computed, viewer-relative — see the class docblock for why both are false today.
            'can_approve' => $user !== null && $user->can('approve', $this->resource),
            'can_reject' => $user !== null && $user->can('reject', $this->resource),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
