<?php

namespace App\Finance\Actions;

use App\Exceptions\BusinessRuleException;
use App\Finance\Enums\DiscountPolicyChangeKind;
use App\Finance\Enums\DiscountPolicyChangeStatus;
use App\Finance\Enums\DiscountPolicyStatus;
use App\Finance\Models\DiscountPolicy;
use App\Finance\Models\DiscountPolicyChange;
use App\Models\User;
use App\Support\SchoolContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Axis A checker side — the ED APPROVES a change, and the catalog changes HERE AND ONLY HERE. Everything
 * — insert the new policy, set supersedes_policy_id, flip a target to superseded/retired, mark the change
 * approved — happens in ONE transaction. A half-applied amendment (supersede the old without creating the
 * new) would leave a school with no discount and no error, the worst available failure, so it must be
 * atomic (proof 8). An arch test asserts no other class writes finance_discount_policies.
 *
 * maker ≠ checker holds two ways: the Policy (403) and the DB CHECK (submitted_by <> decided_by).
 */
final class ApproveDiscountPolicyChange
{
    public function handle(DiscountPolicyChange $change, User $checker): DiscountPolicyChange
    {
        // Rule 13: no context, no financial governance act (App\Support\SchoolContext).
        SchoolContext::assertOwns($change, 'discount-policy change', 'approved');

        if ($change->status !== DiscountPolicyChangeStatus::Submitted) {
            throw new BusinessRuleException('Only a submitted change can be approved.');
        }
        if ((string) $change->submitted_by === (string) $checker->id) {
            throw new BusinessRuleException('A change cannot be approved by its submitter (maker ≠ checker).');
        }

        return DB::transaction(function () use ($change, $checker) {
            match ($change->kind) {
                DiscountPolicyChangeKind::Create => $this->insertPolicy($change, null),
                DiscountPolicyChangeKind::Amend => $this->amend($change),
                DiscountPolicyChangeKind::Retire => $this->retire($change),
            };

            $change->update([
                'status' => DiscountPolicyChangeStatus::Approved,
                'decided_by' => $checker->id,
                'decided_at' => now(),
            ]);

            return $change->refresh();
        });
    }

    private function amend(DiscountPolicyChange $change): void
    {
        // Supersede the target BEFORE inserting the new active row, or the active-name unique rejects the
        // insert — the same supersede-before-activate ordering the fee schedule follows.
        $target = DiscountPolicy::query()->whereKey($change->target_policy_id)->lockForUpdate()->firstOrFail();
        $target->update(['status' => DiscountPolicyStatus::Superseded]);
        $this->insertPolicy($change, (int) $target->id);
    }

    private function retire(DiscountPolicyChange $change): void
    {
        $target = DiscountPolicy::query()->whereKey($change->target_policy_id)->lockForUpdate()->firstOrFail();
        $target->update(['status' => DiscountPolicyStatus::Retired]);
    }

    private function insertPolicy(DiscountPolicyChange $change, ?int $supersedesId): DiscountPolicy
    {
        try {
            return DiscountPolicy::create([
                'school_id' => $change->school_id,
                'name' => $change->name,
                'description' => $change->description,
                'basis' => $change->basis,
                'value_minor' => $change->value_minor,
                'value_currency' => $change->value_currency,
                'percent' => $change->percent,
                'requires_approval' => $change->requires_approval,
                'status' => DiscountPolicyStatus::Active,
                'supersedes_policy_id' => $supersedesId,
            ]);
        } catch (QueryException $e) {
            // Two concurrent creates of the same name both submit (null target, no open_key collision);
            // the SECOND to approve trips active_name_unique. Translate to a friendly 422 — an untranslated
            // 500 in an approval flow is how a checker stops trusting the queue (mirrors GenerateInvoice).
            if ((int) ($e->errorInfo[1] ?? 0) === 1062 && str_contains($e->getMessage(), 'finance_discount_policies_active_name_unique')) {
                throw new BusinessRuleException('A policy with that name is already active.');
            }
            throw $e;
        }
    }
}
