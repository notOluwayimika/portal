<?php

namespace App\Finance\Actions;

use App\Exceptions\BusinessRuleException;
use App\Finance\Enums\FeeScheduleChangeKind;
use App\Finance\Enums\FeeScheduleChangeStatus;
use App\Finance\Enums\FeeScheduleStatus;
use App\Finance\Models\FeeSchedule;
use App\Finance\Models\FeeScheduleChange;
use App\Models\User;
use App\Support\ActiveSchool;
use Illuminate\Support\Facades\DB;

/**
 * Governance maker side — the Head PROPOSES publishing a draft schedule, or retiring an active one. The
 * schedule is NOT touched; nothing becomes billable (or stops billing) until the ED approves
 * ({@see ApproveFeeScheduleChange}). The friendly kind/state/one-open checks here mirror the DB CHECK and
 * open_key that are the real guarantees. Mirrors {@see SubmitDiscountPolicyChange} file for file.
 */
final class SubmitFeeScheduleChange
{
    public function handle(FeeScheduleChangeKind $kind, FeeSchedule $target, string $reason, User $maker): FeeScheduleChange
    {
        $schoolId = ActiveSchool::id();
        if ($schoolId === null) {
            throw new BusinessRuleException('No active School context: a fee-schedule change cannot be submitted.');
        }
        if (trim($reason) === '') {
            throw new BusinessRuleException('A reason is required to propose a fee-schedule change.');
        }

        // The target must be in the right state for the act — a publish needs a draft, a retire an active
        // schedule. The DB does not encode this (a schedule's status is not on the change row), so it is a
        // maker-side check with the approval Action's re-read under lock as the authoritative backstop.
        if ($kind === FeeScheduleChangeKind::Publish && $target->status !== FeeScheduleStatus::Draft) {
            throw new BusinessRuleException('Only a draft schedule can be submitted for publication.');
        }
        if ($kind === FeeScheduleChangeKind::Retire && $target->status !== FeeScheduleStatus::Active) {
            throw new BusinessRuleException('Only an active schedule can be retired.');
        }

        // One open request per target schedule (the open_key UNIQUE is the concurrency backstop).
        if (FeeScheduleChange::query()
            ->where('target_schedule_id', $target->id)
            ->where('status', FeeScheduleChangeStatus::Submitted->value)
            ->exists()
        ) {
            throw new BusinessRuleException('A change for this schedule is already awaiting approval.');
        }

        return DB::transaction(function () use ($schoolId, $kind, $target, $reason, $maker) {
            // 4a: a publish FREEZES the target by moving it draft → pending_approval, so the numbers the ED
            // sees cannot change under approval (the item guards refuse every write once it leaves draft).
            // Re-read the target status UNDER LOCK first: the maker-side check above read a model loaded
            // outside this transaction, and with a real state change now riding on it two submissions could
            // otherwise race between that check and this flip (open_key would catch the loser only as a raw
            // unique-violation the caller cannot act on, not a graceful refusal).
            if ($kind === FeeScheduleChangeKind::Publish) {
                $locked = FeeSchedule::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== FeeScheduleStatus::Draft) {
                    throw new BusinessRuleException('Only a draft schedule can be submitted for publication.');
                }
                $locked->update(['status' => FeeScheduleStatus::PendingApproval]);
            }

            return FeeScheduleChange::create([
                'school_id' => $schoolId,
                'kind' => $kind,
                'target_schedule_id' => $target->id,
                'reason' => trim($reason),
                'status' => FeeScheduleChangeStatus::Submitted,
                'submitted_by' => $maker->id,
            ]);
        });
    }
}
