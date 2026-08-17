<?php

namespace App\Finance\Policies;

use App\Finance\Actions\ApproveOpeningBalanceBatch;
use App\Finance\Models\OpeningBalanceBatch;
use App\Models\User;

/**
 * The record-level maker ≠ checker rule for opening-balance batches (§9 step 5b-ii) — structurally
 * identical to {@see DiscountPolicyChangePolicy} / {@see VoidRequestPolicy} / CreditNotePolicy. The
 * permission decides whether a user may check at all; this decides whether THIS user may check THIS
 * batch (not if they submitted it). `approve`/`reject` are checker abilities, excluded from the
 * super-admin Gate::before bypass by ApprovalAbility, so they run for a super admin too.
 *
 * IT IS THE THIRD LAYER, NOT THE ONLY ONE, and the other two matter because they fail
 * independently: {@see ApproveOpeningBalanceBatch} re-reads the submitter UNDER
 * LOCK and refuses (a race this Policy cannot see, since it reads a model fetched before the
 * transaction), and the maker≠checker trigger pair
 * `finance_opening_balance_batches_maker_ne_checker_bi` / `_bu` (2026_08_17_100000) refuses the row
 * at the engine for any raw write that never passes through either. That pair replaced a CHECK of
 * the same name (2026_08_09_100000), which production — MySQL 5.7.23 — never enforced, so this
 * third layer existed only on the dev machine until then.
 *
 * IT IS ALSO WHAT FLIPS THE QUEUE'S FLAGS ON. OpeningBalanceBatchResource has computed
 * `can_approve` / `can_reject` through the Gate since 5a and documented them as false for every
 * viewer, because no policy existed to consult. Registering this class is the whole of that change:
 * the Resource is untouched.
 *
 * NO REGISTRATION IS WRITTEN ANYWHERE. Laravel's guesser maps App\Finance\Models\X to
 * App\Finance\Policies\XPolicy, which is how the four siblings resolve too. A convention holding a
 * money guard up is a convention worth a test rather than a comment, so
 * OpeningBalanceDecisionSurfaceTest resolves this class through the Gate by name.
 */
class OpeningBalanceBatchPolicy
{
    public function approve(User $user, OpeningBalanceBatch $batch): bool
    {
        return $user->can('finance.opening-balance.approve') && $this->isNotTheMaker($user, $batch);
    }

    public function reject(User $user, OpeningBalanceBatch $batch): bool
    {
        return $user->can('finance.opening-balance.reject') && $this->isNotTheMaker($user, $batch);
    }

    /**
     * The maker is `submitted_by_user_id` — NOT `submitted_by`, which is the discount-policy-change
     * sibling's column. The two tables name the same fact differently, and a copied `submitted_by`
     * here would read `null` on every batch, take the early return below, and permit self-approval
     * on every row while every test that never checks the maker stays green.
     */
    private function isNotTheMaker(User $user, OpeningBalanceBatch $batch): bool
    {
        if ($batch->submitted_by_user_id === null) {
            return true;
        }

        // String-compared deliberately: a strict !== between int and string ids would report the SAME
        // person as different and silently allow self-approval — the one direction that must never fail.
        return (string) $batch->submitted_by_user_id !== (string) $user->id;
    }
}
