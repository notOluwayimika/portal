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
        // THE TARGET ITSELF, not just its id — see insertPolicy()'s @param. effectiveBase() re-reads
        // this same row through the relation rather than taking this instance: the row is X-locked by
        // THIS transaction, so the re-read is our own view, and `$change->target` is unloaded here
        // (route-model binding does not eager-load it) so it is a fresh read, not a stale cache.
        // Reading it AFTER the supersede is safe either way: the update guard on this table permits
        // `status` and nothing else to move, so every term is untouched.
        $this->insertPolicy($change, $target);
    }

    private function retire(DiscountPolicyChange $change): void
    {
        $target = DiscountPolicy::query()->whereKey($change->target_policy_id)->lockForUpdate()->firstOrFail();
        $target->update(['status' => DiscountPolicyStatus::Retired]);
    }

    /**
     * @param  ?DiscountPolicy  $supersedes  the policy being amended, or null on a create. Only its
     *                                       id is written here; the inheritance of an unstated term
     *                                       moved onto DiscountPolicyChange::effectiveBase(), which
     *                                       resolves the target itself so the Resource can ask the
     *                                       same question without an approval in hand. It stays the
     *                                       MODEL rather than an int so the row this transaction
     *                                       LOCKED is the row named here.
     */
    private function insertPolicy(DiscountPolicyChange $change, ?DiscountPolicy $supersedes): DiscountPolicy
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
                // AXIS C, AND IT MUST BE CARRIED RATHER THAN LEFT TO THE COLUMN DEFAULT. This array
                // is the whole of what an approved change becomes, so a term missing from it is a
                // term the governance path silently discards. It did: before `base` reached this
                // array, amending a `total` policy superseded it and inserted a replacement that had
                // fallen back to `discountable` — 50% of the whole bill became 50% of tuition, the
                // family billed MORE, through the flow whose entire purpose is that terms cannot
                // move without a checker. And `base` is immutable on the catalog row, so it could
                // not be put back except by another amend, which dropped it again.
                //
                // THE RULE ITSELF LIVES ON THE MODEL, in ONE place, because it has a SECOND reader:
                // DiscountPolicyChangeResource shows the checker what they are approving. Resolved
                // twice, the write and the screen agree only until one is edited — and a checker
                // shown a base the catalog will not receive is the original defect wearing a screen.
                // effectiveBase() carries the reasoning; read it there, not here.
                //
                // NEVER NULL ON THIS PATH: effectiveBase() returns null only for a `retire`, and a
                // retire never reaches insertPolicy() (the match in handle() sends it to retire()).
                // If that ever stops holding, the catalog's NOT NULL refuses the insert loudly,
                // which is the direction to fail in.
                'base' => $change->effectiveBase(),
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
