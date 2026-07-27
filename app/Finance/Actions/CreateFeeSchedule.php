<?php

namespace App\Finance\Actions;

use App\Exceptions\BusinessRuleException;
use App\Finance\Enums\FeeScheduleStatus;
use App\Finance\Models\FeeSchedule;
use App\Support\ActiveSchool;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Publish a fee schedule for (term, class level) — S1 commit 2, the DIRECT-PUBLISH path.
 *
 * ⚠️ COMMIT 4 DELETES THE `active` FLIP AT THE END OF THIS ACTION. Once fee-schedule governance lands,
 * publishing becomes an approved change request (Head submits a draft, ED approves), and no route or
 * Action other than ApproveFeeScheduleChange may set `status = active`. This Action's whole reason to
 * exist in commit 2 is to give the catalog a way to reach `active` before that governance exists, and
 * to give proofs 24/25/26 a live table to bite. Proof 31 asserts this back door is gone after commit 4.
 *
 * ORDERING IS LOAD-BEARING (and is the same order ApproveFeeScheduleChange must follow — proof 29b):
 * the schedule is created as a DRAFT so the parent-state trigger permits the item inserts; the existing
 * active schedule is SUPERSEDED *before* the new one is activated, or `finance_fee_schedules_active_unique`
 * rejects the flip. Writing activate-before-supersede passes on a fresh DB (no prior active) and fails
 * only on the second publish — a first-run-passes bug.
 */
final class CreateFeeSchedule
{
    /**
     * @param  list<array<string, mixed>>  $items  each: description, amount_minor, currency?, is_mandatory?, is_discountable?, sort_order?
     */
    public function handle(int $termId, int $classLevelId, string $label, array $items): FeeSchedule
    {
        $schoolId = ActiveSchool::id();
        if ($schoolId === null) {
            throw new BusinessRuleException('No active School context: a fee schedule cannot be created.');
        }
        if ($items === []) {
            throw new BusinessRuleException('A fee schedule must have at least one item.');
        }

        return DB::transaction(function () use ($schoolId, $termId, $classLevelId, $label, $items) {
            // 1. Create as a DRAFT — items may only be inserted into a draft (parent-state trigger).
            $schedule = FeeSchedule::create([
                'school_id' => $schoolId,
                'term_id' => $termId,
                'class_level_id' => $classLevelId,
                'label' => $label,
                'status' => FeeScheduleStatus::Draft,
            ]);

            // 2. Insert the items.
            foreach ($items as $i => $item) {
                $schedule->items()->create([
                    'school_id' => $schoolId,
                    'description' => (string) $item['description'],
                    'amount' => Money::fromKobo((int) $item['amount_minor'], (string) ($item['currency'] ?? Money::DEFAULT_CURRENCY)),
                    'is_mandatory' => (bool) ($item['is_mandatory'] ?? true),
                    'is_discountable' => (bool) ($item['is_discountable'] ?? true),
                    'sort_order' => (int) ($item['sort_order'] ?? $i),
                ]);
            }

            // 3. Supersede any current active schedule for this slot — BEFORE activating the new one.
            $current = FeeSchedule::query()
                ->where('term_id', $termId)->where('class_level_id', $classLevelId)
                ->where('status', FeeScheduleStatus::Active->value)
                ->lockForUpdate()->first();
            if ($current !== null) {
                $current->update(['status' => FeeScheduleStatus::Superseded]);
                $schedule->supersedes_schedule_id = $current->id;
            }

            // 4. DIRECT-PUBLISH FLIP (deleted in commit 4).
            $schedule->status = FeeScheduleStatus::Active;
            $schedule->save();

            return $schedule->load(['items' => fn ($q) => $q->orderBy('sort_order')]);
        });
    }
}
