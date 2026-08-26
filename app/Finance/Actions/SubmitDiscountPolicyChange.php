<?php

namespace App\Finance\Actions;

use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Finance\Approval\ApprovalRequirement;
use App\Finance\Approval\NotifiesApprovalCheckers;
use App\Finance\Enums\DiscountPolicyChangeKind;
use App\Finance\Enums\DiscountPolicyChangeStatus;
use App\Finance\Models\DiscountPolicy;
use App\Finance\Models\DiscountPolicyChange;
use App\Models\User;
use App\Support\SchoolContext;
use Illuminate\Support\Facades\DB;

/**
 * Axis A maker side — the Head PROPOSES a change to the discount catalog (create | amend | retire). The
 * catalog is NOT touched; nothing changes until the ED approves ({@see ApproveDiscountPolicyChange}).
 * The friendly shape/one-open checks here mirror the DB CHECKs and open_key that are the real guarantees.
 *
 * @param  array<string, mixed>  $terms  name, description, basis, value_minor, value_currency, percent,
 *                                       requires_approval — present for create/amend, ignored for retire.
 */
final class SubmitDiscountPolicyChange
{
    use NotifiesApprovalCheckers;

    public function handle(DiscountPolicyChangeKind $kind, ?DiscountPolicy $target, array $terms, string $reason, User $maker): DiscountPolicyChange
    {
        // Rule 13, and THE ONE PLACE IN THE FIFTEEN THAT CALLS require() RATHER THAN assertOwns().
        //
        // A `create` change names no policy — `:40-41` refuses one that does — so on that path there
        // is no pre-existing school-owned record for the act to belong to. That is not a weaker guard
        // over the same surface; it is a COMPLETE guard over a smaller one: the open-request query
        // below is gated on `$target !== null` and does not run, and the change row's own school_id is
        // stamped from the context this call returns. The context IS the whole school-sensitive
        // surface there, and require() closes all of it.
        //
        // When a target IS named (amend, retire) the full guard applies, and the ownership half is
        // NEW — a behaviour change, not a move.
        $schoolId = SchoolContext::require('discount-policy change', 'submitted');

        if ($target !== null) {
            SchoolContext::assertOwns($target, 'discount policy', 'submitted');
        }
        if (trim($reason) === '') {
            throw new BusinessRuleException('A reason is required to propose a discount-policy change.');
        }

        // Target shape (the DB target_shape CHECK is the backstop).
        if ($kind === DiscountPolicyChangeKind::Create && $target !== null) {
            throw new BusinessRuleException('A create change may not name an existing policy.');
        }
        if ($kind !== DiscountPolicyChangeKind::Create && $target === null) {
            throw new BusinessRuleException('An amend or retire change must name the policy it changes.');
        }

        // One open request per target (the open_key UNIQUE is the concurrency backstop). Creates carry a
        // null target and are not constrained here — a duplicate name is caught at approval.
        if ($target !== null && DiscountPolicyChange::query()
            ->where('target_policy_id', $target->id)
            ->where('status', DiscountPolicyChangeStatus::Submitted->value)
            ->exists()
        ) {
            throw new BusinessRuleException('A change for this policy is already awaiting approval.');
        }

        // The maker-checker seam (ADR 0051): always requires a checker today. No transaction amount — a
        // discount policy is a definition, so a future threshold rule would key on the ability alone.
        if (! ApprovalRequirement::for(Permission::FINANCE_DISCOUNT_POLICY_CHANGE_SUBMIT->value)->required) {
            throw new \LogicException('Straight-through submission is not implemented — see ADR 0051.');
        }

        $proposed = $kind === DiscountPolicyChangeKind::Retire
            ? []
            : [
                'name' => $terms['name'] ?? null,
                'description' => $terms['description'] ?? null,
                'basis' => $terms['basis'] ?? null,
                'value_minor' => $terms['value_minor'] ?? null,
                'value_currency' => $terms['value_currency'] ?? null,
                'percent' => $terms['percent'] ?? null,
                // AXIS C. THE SECOND WHITELIST IN THIS CHAIN, and both had to move: cold review
                // found `base` missing from ApproveDiscountPolicyChange::insertPolicy(), and it was
                // missing here too — so carrying it only there would have carried a column that is
                // always null. A term is end-to-end when every array between the wire and the
                // catalog names it; there are two, and this is the first.
                'base' => $terms['base'] ?? null,
                'requires_approval' => $terms['requires_approval'] ?? false,
            ];

        $submitted = DB::transaction(fn () => DiscountPolicyChange::create([
            'school_id' => $schoolId,
            'kind' => $kind,
            'target_policy_id' => $target?->id,
            'reason' => trim($reason),
            'status' => DiscountPolicyChangeStatus::Submitted,
            'submitted_by' => $maker->id,
            ...$proposed,
        ]));

        // AFTER the commit, never inside it.
        $this->notifyApprovalCheckers(
            subject: $submitted,
            checkerAbility: Permission::FINANCE_DISCOUNT_POLICY_CHANGE_APPROVE->value,
            submittedBy: (int) $maker->id,
            summary: 'Discount policy change ('.$kind->value.') awaiting approval',
        );

        return $submitted;
    }
}
