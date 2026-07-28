<?php

namespace App\Finance\Actions;

use App\Exceptions\BusinessRuleException;
use App\Finance\Enums\FeeScheduleChangeKind;
use App\Finance\Enums\FeeScheduleChangeStatus;
use App\Finance\Enums\FeeScheduleStatus;
use App\Finance\Models\FeeSchedule;
use App\Finance\Models\FeeScheduleChange;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Governance checker side — the ED APPROVES a change, and a schedule reaches `active` HERE AND ONLY HERE.
 * Everything — supersede the current active, activate the draft, set supersedes_schedule_id, mark the
 * change approved — happens in ONE transaction (proof 29: a mid-transaction failure leaves NOTHING moved).
 * An arch test asserts no other class writes `finance_fee_schedules.status = active`; proof 31 asserts the
 * commit-2 direct-publish flip is gone.
 *
 * maker ≠ checker holds two ways: the FeeScheduleChangePolicy (403) and the DB CHECK (submitted_by <> decided_by).
 */
final class ApproveFeeScheduleChange
{
    public function handle(FeeScheduleChange $change, User $checker): FeeScheduleChange
    {
        if ($change->status !== FeeScheduleChangeStatus::Submitted) {
            throw new BusinessRuleException('Only a submitted change can be approved.');
        }
        if ((string) $change->submitted_by === (string) $checker->id) {
            throw new BusinessRuleException('A change cannot be approved by its submitter (maker ≠ checker).');
        }

        return DB::transaction(function () use ($change, $checker) {
            match ($change->kind) {
                FeeScheduleChangeKind::Publish => $this->publish($change),
                FeeScheduleChangeKind::Retire => $this->retire($change),
            };

            $change->update([
                'status' => FeeScheduleChangeStatus::Approved,
                'decided_by' => $checker->id,
                'decided_at' => now(),
            ]);

            return $change->refresh();
        });
    }

    private function publish(FeeScheduleChange $change): void
    {
        $draft = FeeSchedule::query()->whereKey($change->target_schedule_id)->lockForUpdate()->firstOrFail();

        // Re-read under lock: the draft may have been published or retired since submission.
        if ($draft->status !== FeeScheduleStatus::Draft) {
            throw new BusinessRuleException('The target schedule is no longer a draft.');
        }

        // An empty approved schedule bills nothing and looks like a working configuration — refuse it. This
        // is a business rule with no natural database expression (proof 29c); the DB happily activates a
        // schedule with zero items. CreateFeeSchedule refuses an empty draft up front, but a draft stripped
        // of its items after authoring would slip past that — so the guard lives at the point of approval.
        if ($draft->items()->count() === 0) {
            throw new BusinessRuleException('A schedule with no items cannot be published.');
        }

        // Supersede the current active schedule for this slot BEFORE activating the draft, or
        // finance_fee_schedules_active_unique rejects the activation. The reverse order is the natural way
        // to write it and passes on a fresh database every time (no active row to collide with) — a
        // first-run-passes, second-run-fails bug, and 9.3 names it the single most likely defect here
        // (proof 29b: publish twice against one slot).
        $current = FeeSchedule::query()
            ->where('term_id', $draft->term_id)
            ->where('class_level_id', $draft->class_level_id)
            ->where('status', FeeScheduleStatus::Active->value)
            ->lockForUpdate()->first();

        $supersedesId = null;
        if ($current !== null) {
            $current->update(['status' => FeeScheduleStatus::Superseded]);
            $supersedesId = (int) $current->id;
        }

        $draft->update([
            'status' => FeeScheduleStatus::Active,
            'supersedes_schedule_id' => $supersedesId,
        ]);
    }

    private function retire(FeeScheduleChange $change): void
    {
        $schedule = FeeSchedule::query()->whereKey($change->target_schedule_id)->lockForUpdate()->firstOrFail();

        // Re-read under lock: only an active schedule can be retired.
        if ($schedule->status !== FeeScheduleStatus::Active) {
            throw new BusinessRuleException('The target schedule is not active and cannot be retired.');
        }

        // Retirement is not retroactive — invoices already raised against this schedule keep their lines
        // (they are snapshots). Zero active for the slot is the INTENDED outcome here (proof 29's retire case).
        $schedule->update(['status' => FeeScheduleStatus::Retired]);
    }
}
