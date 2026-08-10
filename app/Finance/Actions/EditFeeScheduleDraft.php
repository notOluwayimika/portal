<?php

namespace App\Finance\Actions;

use App\Exceptions\BusinessRuleException;
use App\Finance\Enums\FeeScheduleStatus;
use App\Finance\Models\BankAccount;
use App\Finance\Models\FeeSchedule;
use App\Support\Money;
use App\Support\SchoolContext;
use Illuminate\Support\Facades\DB;

/**
 * Edit a DRAFT fee schedule in place — its label, and its items replaced wholesale.
 *
 * WHY THIS EXISTS AT ALL. Until this Action there was no way to change a draft. `store` and the route
 * formerly named `update` (now {@see FeeScheduleController::supersede}) both call {@see CreateFeeSchedule},
 * which only ever creates; and finance_fee_schedules_pending_unique (S1 4a) permits at most one
 * draft-or-pending schedule per (school, term, class level). So a draft occupied its own slot: authoring a
 * replacement for it collided with itself, there is no delete route, and one typo bricked that slot for
 * the term until someone ran SQL. Meanwhile {@see RejectFeeScheduleChange} already returns a rejected
 * publish to `draft` "so the Head can edit and resubmit — the items unfreeze the moment the schedule is a
 * draft again". 4a built the loop; the edit it loops back to was never built. This is it.
 *
 * ITEMS ARE REPLACED WHOLESALE, not diffed (project lead, ruling of this commit). The ids are not worth
 * preserving: finance_invoice_lines.fee_item_id is LOOKUP provenance with NO foreign key by policy
 * (docs/finance-data-ownership.md), the display never joins it, and a draft's items cannot be cited by any
 * invoice — prefill resolves through FeeScheduleLookup::activeFor, active only. Diffing would also need a
 * natural key to match on, and there is none: descriptions are free text and repeat.
 *
 * NO SCHEMA CHANGE. The three finance_fee_items_parent_state_guard_{ins,upd,del} triggers already permit
 * insert, update and delete while the parent is a draft — deleting this draft's items is exactly what they
 * were written to allow. They are the backstop here, not the check.
 */
final class EditFeeScheduleDraft
{
    /**
     * @param  list<array<string, mixed>>  $items  each: description, bank_account_id (uuid), amount_minor, currency?, is_mandatory?, is_discountable?, sort_order?
     */
    public function handle(FeeSchedule $schedule, string $label, array $items): FeeSchedule
    {
        // Rule 13: no context, no financial act. `$schedule` arrives as a bound model, so without this the
        // ownership question is never asked again — the writes below would land on another School's draft
        // stamped with the active School's id. Same guard, same reason, as SubmitFeeScheduleChange.
        // It returns the context it required, so the null-context refusal and the ownership refusal are
        // one call rather than two checks that could drift apart.
        $schoolId = SchoolContext::assertOwns($schedule, 'fee schedule', 'edited');

        if ($items === []) {
            throw new BusinessRuleException('A fee schedule must have at least one item.');
        }

        // An EARLY refusal, not a third control — and said plainly because the difference was measured,
        // not assumed. Removing this line alone leaves every arm of EditFeeScheduleDraftTest green: the
        // locked re-read below refuses the same request for the same reason a few microseconds later. What
        // it buys is that the common case does not open a transaction or take a row lock to be told no.
        // The two things actually holding the rule up are that re-read and the DB triggers.
        $this->assertDraft($schedule);

        return DB::transaction(function () use ($schedule, $label, $items, $schoolId) {
            // THE STATE CONTROL. Re-read the status UNDER LOCK. The check above read a model loaded before the
            // transaction; between the two an approval could have moved this schedule to pending_approval
            // or active, and editing it then is exactly the ADR 0050 window 4a closed by prevention.
            $locked = FeeSchedule::query()->whereKey($schedule->getKey())->lockForUpdate()->firstOrFail();
            $this->assertDraft($locked);

            $locked->update(['label' => $label]);

            // Wholesale: every existing item goes, the submitted set replaces it. Deleted through the
            // relation rather than by truncating a query, so the parent-state triggers see the rows.
            $locked->items()->delete();

            foreach ($items as $i => $item) {
                $locked->items()->create([
                    'school_id' => $schoolId,
                    'description' => (string) $item['description'],
                    'amount' => Money::fromKobo((int) $item['amount_minor'], (string) ($item['currency'] ?? Money::DEFAULT_CURRENCY)),
                    'is_mandatory' => (bool) ($item['is_mandatory'] ?? true),
                    'is_discountable' => (bool) ($item['is_discountable'] ?? true),
                    'sort_order' => (int) ($item['sort_order'] ?? $i),
                    // uuid -> id through the School-scoped model, the same resolution CreateFeeSchedule
                    // does: a foreign uuid resolves to nothing here rather than being trusted and refused
                    // later by the composite foreign key, which would surface as a 500 instead of a 422.
                    'bank_account_id' => BankAccount::query()
                        ->where('uuid', (string) $item['bank_account_id'])
                        ->valueOrFail('id'),
                ]);
            }

            return $locked->load(['items' => fn ($q) => $q->orderBy('sort_order')]);
        });
    }

    /**
     * The state rule, in one place so the early refusal and the locked one cannot drift apart, and the message names
     * the state FOUND — an operator reading "it is active" knows to author a superseding draft instead,
     * where "it is not a draft" leaves them guessing.
     */
    private function assertDraft(FeeSchedule $schedule): void
    {
        if ($schedule->status !== FeeScheduleStatus::Draft) {
            throw new BusinessRuleException(
                'Only a draft fee schedule can be edited; this one is '.$schedule->status->value.'.'
            );
        }
    }
}
