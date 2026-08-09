<?php

namespace App\Finance\Actions;

use App\Exceptions\BusinessRuleException;
use App\Finance\Enums\FeeScheduleChangeKind;
use App\Finance\Enums\FeeScheduleChangeStatus;
use App\Finance\Enums\FeeScheduleStatus;
use App\Finance\Models\FeeSchedule;
use App\Finance\Models\FeeScheduleChange;
use App\Models\User;
use App\Support\SchoolContext;
use Illuminate\Support\Facades\DB;

/**
 * Governance checker side — the ED REJECTS a change with a reason; the change is retained (un-deletable)
 * for audit. maker ≠ checker holds via the Policy (403) and the DB CHECK. Mirrors {@see RejectDiscountPolicyChange}.
 *
 * 4a: rejecting a PUBLISH restores the target pending_approval → draft, in the same transaction, so the
 * Head can edit and resubmit — the items unfreeze the moment the schedule is a draft again. Rejecting a
 * retire touches only the change row (the target stayed active throughout).
 */
final class RejectFeeScheduleChange
{
    public function handle(FeeScheduleChange $change, string $reason, User $checker): FeeScheduleChange
    {
        // Rule 13: no context, no financial governance act (App\Support\SchoolContext).
        SchoolContext::assertOwns($change, 'fee-schedule change', 'rejected');

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
            // Restore a rejected publish's target to draft (under lock) so its items unfreeze for re-editing.
            if ($change->kind === FeeScheduleChangeKind::Publish) {
                $target = FeeSchedule::query()->whereKey($change->target_schedule_id)->lockForUpdate()->firstOrFail();
                if ($target->status === FeeScheduleStatus::PendingApproval) {
                    $target->update(['status' => FeeScheduleStatus::Draft]);
                }
            }

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
