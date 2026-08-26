<?php

namespace App\Finance\Actions;

use App\Exceptions\BusinessRuleException;
use App\Finance\Enums\DiscountBase;
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
        // THE TARGET ITSELF, not just its id — insertPolicy() inherits any term the change leaves
        // unstated from the policy being amended. Reading it AFTER the supersede is safe: the update
        // guard on this table permits `status` and nothing else to move, so every term is untouched.
        $this->insertPolicy($change, $target);
    }

    private function retire(DiscountPolicyChange $change): void
    {
        $target = DiscountPolicy::query()->whereKey($change->target_policy_id)->lockForUpdate()->firstOrFail();
        $target->update(['status' => DiscountPolicyStatus::Retired]);
    }

    /**
     * @param  ?DiscountPolicy  $supersedes  the policy being amended, or null on a create. It is the
     *                                       MODEL and not an id because a term the change leaves
     *                                       unstated is inherited from it — see `base` below.
     */
    private function insertPolicy(DiscountPolicyChange $change, ?DiscountPolicy $supersedes): DiscountPolicy
    {
        // RESOLVED HERE RATHER THAN INLINE, because the inline form is `$supersedes?->base ?? …` and
        // Larastan refuses it (nullsafe.neverNull): `??` already suppresses the null-property read,
        // so the `?->` is dead syntax. The explicit instanceof says the same thing and says which
        // branch is the create path.
        $inherited = $supersedes instanceof DiscountPolicy ? $supersedes->base : DiscountBase::Discountable;

        try {
            return DiscountPolicy::create([
                'school_id' => $change->school_id,
                'name' => $change->name,
                'description' => $change->description,
                'basis' => $change->basis,
                'value_minor' => $change->value_minor,
                'value_currency' => $change->value_currency,
                'percent' => $change->percent,
                // AXIS C, AND IT MUST BE CARRIED RATHER THAN LEFT TO THE COLUMN DEFAULT. This array
                // is the whole of what an approved change becomes, so a term missing from it is a
                // term the governance path silently discards. It did: before this line, amending a
                // `total` policy superseded it and inserted a replacement that had fallen back to
                // `discountable` — 50% of the whole bill became 50% of tuition, the family billed
                // MORE, through the flow whose entire purpose is that terms cannot move without a
                // checker. And `base` is immutable on the catalog row, so it could not be put back
                // except by another amend, which dropped it again.
                //
                // THE THREE-STEP COALESCE, IN THIS ORDER, AND EACH STEP EARNS ITS PLACE:
                //
                //   $change->base    — the maker said so. Stating a term is always authoritative.
                //   $supersedes      — the maker said NOTHING and there is a policy being amended,
                //                      so nothing is what changes. This is the step that makes
                //                      omission SAFE rather than merely refused: a `total` policy
                //                      raised from 50% to 55% stays whole-bill even if the maker
                //                      never mentions the base, which is the realistic shape of the
                //                      mistake. Requiring the field would have moved the defect onto
                //                      the maker remembering; this removes it.
                //   Discountable     — a CREATE that stated nothing, or a pre-axis change row whose
                //                      `base` is NULL because it was submitted before the column
                //                      existed. Both land on the behaviour they were authored under,
                //                      which is a statement of fact rather than a guess. An `amount`
                //                      basis lands here too and the value is inert.
                'base' => $change->base ?? $inherited,
                'requires_approval' => $change->requires_approval,
                'status' => DiscountPolicyStatus::Active,
                'supersedes_policy_id' => $supersedes?->id,
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
