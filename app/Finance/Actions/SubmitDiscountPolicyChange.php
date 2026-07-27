<?php

namespace App\Finance\Actions;

use App\Exceptions\BusinessRuleException;
use App\Finance\Enums\DiscountPolicyChangeKind;
use App\Finance\Enums\DiscountPolicyChangeStatus;
use App\Finance\Models\DiscountPolicy;
use App\Finance\Models\DiscountPolicyChange;
use App\Models\User;
use App\Support\ActiveSchool;
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
    public function handle(DiscountPolicyChangeKind $kind, ?DiscountPolicy $target, array $terms, string $reason, User $maker): DiscountPolicyChange
    {
        $schoolId = ActiveSchool::id();
        if ($schoolId === null) {
            throw new BusinessRuleException('No active School context: a discount-policy change cannot be submitted.');
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

        $proposed = $kind === DiscountPolicyChangeKind::Retire
            ? []
            : [
                'name' => $terms['name'] ?? null,
                'description' => $terms['description'] ?? null,
                'basis' => $terms['basis'] ?? null,
                'value_minor' => $terms['value_minor'] ?? null,
                'value_currency' => $terms['value_currency'] ?? null,
                'percent' => $terms['percent'] ?? null,
                'requires_approval' => $terms['requires_approval'] ?? false,
            ];

        return DB::transaction(fn () => DiscountPolicyChange::create([
            'school_id' => $schoolId,
            'kind' => $kind,
            'target_policy_id' => $target?->id,
            'reason' => trim($reason),
            'status' => DiscountPolicyChangeStatus::Submitted,
            'submitted_by' => $maker->id,
            ...$proposed,
        ]));
    }
}
