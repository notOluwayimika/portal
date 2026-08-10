<?php

namespace App\Finance\Actions;

use App\Exceptions\BusinessRuleException;
use App\Finance\Enums\FeeScheduleStatus;
use App\Finance\Models\BankAccount;
use App\Finance\Models\FeeSchedule;
use App\Support\ActiveSchool;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Author a DRAFT fee schedule for (term, class level) — S1 commit 2, NARROWED in commit 4.
 *
 * In commit 2 this Action also flipped the new schedule to `active` (the direct-publish path). COMMIT 4
 * DELETED THAT FLIP: publishing is now an approved change request ({@see ApproveFeeScheduleChange} is the
 * only code that may set `status = active`, proof 31). This Action's whole job is now draft authorship —
 * create the schedule as a draft and insert its items, so the Head can assemble the numbers and later
 * submit the draft for the ED's approval. Re-pricing an active slot means authoring a fresh draft here and
 * publishing it through a change request; the supersession that used to live at step 3 has moved into the
 * approval Action, where it belongs.
 *
 * The schedule is created as a DRAFT so the parent-state trigger permits the item inserts (an item may only
 * be added to a draft). At most one OPEN schedule per (school, term, class level) exists at a time —
 * `finance_fee_schedules_pending_unique`, which covers draft AND pending_approval. A second one for the
 * same slot is a friendly 422.
 *
 * IT USED TO NAME `finance_fee_schedules_draft_unique`, which S1 4a DROPPED
 * (2026_07_29_120000_finance_fee_schedule_pending_approval_state.php:38) and replaced with the wider key.
 * That stale premise is the reason a draft occupied its own slot and could not be edited at all — the
 * defect {@see EditFeeScheduleDraft} exists to fix — so this file was edited BECAUSE the sentence was
 * wrong, and left it standing. Corrected here.
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

        try {
            return DB::transaction(function () use ($schoolId, $termId, $classLevelId, $label, $items) {
                // Create as a DRAFT — items may only be inserted into a draft (parent-state trigger), and a
                // draft is a proposal, never a price: it becomes billable only when the ED approves a publish.
                $schedule = FeeSchedule::create([
                    'school_id' => $schoolId,
                    'term_id' => $termId,
                    'class_level_id' => $classLevelId,
                    'label' => $label,
                    'status' => FeeScheduleStatus::Draft,
                ]);

                foreach ($items as $i => $item) {
                    $schedule->items()->create([
                        'school_id' => $schoolId,
                        'description' => (string) $item['description'],
                        'amount' => Money::fromKobo((int) $item['amount_minor'], (string) ($item['currency'] ?? Money::DEFAULT_CURRENCY)),
                        'is_mandatory' => (bool) ($item['is_mandatory'] ?? true),
                        'is_discountable' => (bool) ($item['is_discountable'] ?? true),
                        'sort_order' => (int) ($item['sort_order'] ?? $i),
                        // The configured DESTINATION for this charge. NOT NULL at the database with
                        // no default, so an item that does not say where its money should go is not
                        // a configured charge and cannot be written. FeeScheduleRequest refuses it
                        // at the edge with a message; this resolves what that rule validated.
                        //
                        // uuid -> id, through the School-scoped model: a foreign uuid resolves to
                        // nothing here rather than being trusted and refused later by the composite
                        // foreign key, which would surface as a 500 instead of a 422.
                        'bank_account_id' => BankAccount::query()
                            ->where('uuid', (string) $item['bank_account_id'])
                            ->valueOrFail('id'),
                    ]);
                }

                return $schedule->load(['items' => fn ($q) => $q->orderBy('sort_order')]);
            });
        } catch (QueryException $e) {
            // A second OPEN schedule for the same slot trips finance_fee_schedules_pending_unique — which
            // covers draft AND pending_approval as of 4a (a slot with a schedule awaiting approval is also
            // occupied). Translate to a friendly 422 rather than a raw 500.
            if ((int) ($e->errorInfo[1] ?? 0) === 1062 && str_contains($e->getMessage(), 'finance_fee_schedules_pending_unique')) {
                // The advice names what the operator can actually DO. Until {@see EditFeeScheduleDraft}
                // shipped, "edit" named nothing in the tree — a draft could be neither edited nor deleted,
                // so this message sent the reader looking for a door that was not there. A message naming
                // an action the system does not have is the same defect as a button that 404s.
                throw new BusinessRuleException('A draft or pending schedule already exists for this term and class level. Edit that draft instead, or submit it for approval; if it is already awaiting approval, await the decision.');
            }
            throw $e;
        }
    }
}
