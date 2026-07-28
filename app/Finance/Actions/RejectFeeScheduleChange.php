<?php

namespace App\Finance\Actions;

use App\Exceptions\BusinessRuleException;
use App\Finance\Enums\FeeScheduleChangeStatus;
use App\Finance\Models\FeeScheduleChange;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Governance checker side — the ED REJECTS a change with a reason. The schedule is untouched (the draft
 * stays a draft and may be edited and resubmitted); the change is retained (un-deletable) for audit.
 * maker ≠ checker holds via the Policy (403) and the DB CHECK. Mirrors {@see RejectDiscountPolicyChange}.
 */
final class RejectFeeScheduleChange
{
    public function handle(FeeScheduleChange $change, string $reason, User $checker): FeeScheduleChange
    {
        if ($change->status !== FeeScheduleChangeStatus::Submitted) {
            throw new BusinessRuleException('Only a submitted change can be rejected.');
        }
        if (trim($reason) === '') {
            throw new BusinessRuleException('A reason is required to reject a change.');
        }
        if ((string) $change->submitted_by === (string) $checker->id) {
            throw new BusinessRuleException('A change cannot be rejected by its submitter (maker ≠ checker).');
        }

        return DB::transaction(function () use ($change, $checker, $reason) {
            $change->update([
                'status' => FeeScheduleChangeStatus::Rejected,
                'decided_by' => $checker->id,
                'decided_at' => now(),
                'rejection_reason' => trim($reason),
            ]);

            return $change->refresh();
        });
    }
}
