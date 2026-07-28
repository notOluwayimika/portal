<?php

namespace App\Finance\Policies;

use App\Finance\Models\FeeScheduleChange;
use App\Models\User;

/**
 * The record-level maker ≠ checker rule for fee-schedule changes — structurally identical to
 * {@see DiscountPolicyChangePolicy}. The permission decides whether a user may check at all; this decides
 * whether THIS user may check THIS change (not if they submitted it). `approve`/`reject` are checker
 * abilities, excluded from the super-admin Gate::before bypass by ApprovalAbility, so they run for a super
 * admin too. The DB CHECK (submitted_by <> decided_by) is the independent backstop for any raw write.
 */
class FeeScheduleChangePolicy
{
    public function approve(User $user, FeeScheduleChange $change): bool
    {
        return $user->can('finance.fee-schedule.change.approve') && $this->isNotTheMaker($user, $change);
    }

    public function reject(User $user, FeeScheduleChange $change): bool
    {
        return $user->can('finance.fee-schedule.change.reject') && $this->isNotTheMaker($user, $change);
    }

    private function isNotTheMaker(User $user, FeeScheduleChange $change): bool
    {
        if ($change->submitted_by === null) {
            return true;
        }

        // String-compared deliberately: a strict !== between int and string ids would report the SAME
        // person as different and silently allow self-approval — the one direction that must never fail.
        return (string) $change->submitted_by !== (string) $user->id;
    }
}
