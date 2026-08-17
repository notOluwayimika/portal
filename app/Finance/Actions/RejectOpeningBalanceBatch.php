<?php

namespace App\Finance\Actions;

use App\Exceptions\BusinessRuleException;
use App\Finance\Enums\OpeningBalanceBatchStatus;
use App\Finance\Models\OpeningBalanceBatch;
use App\Models\User;
use App\Support\SchoolContext;
use Illuminate\Support\Facades\DB;

/**
 * §9 step 4c, checker side — a DIFFERENT user REJECTS a submitted opening-balance batch with a reason.
 * NOTHING POSTS: no ledger transaction, no payment, no account movement, and no `posted_at`. The batch
 * and its staged rows are retained for audit, which is the point of a reason being mandatory —
 * "rejected" with no stated why is the same as no record at all when someone asks a year later.
 *
 * IT GOES TO `rejected`, THE VALIDATOR'S OWN TERMINAL STATE, and the two paths stay distinguishable by
 * `rejection_reason` + `decided_by_user_id` (non-null only here). §8 asks for one refusal state; a
 * second one would fork every "did this batch post?" query for no gain. The discriminator is recorded
 * in 2026_08_09_100000's docblock and on OpeningBalanceBatchStatus::Rejected.
 *
 * THERE IS NO WAY BACK TO `validated` FROM HERE, and that is a choice rather than an omission. A
 * rejected cutover is corrected by fixing the WCBS extract and importing it again under a new batch
 * reference — the validator's `ob_batches_school_reference_unique` is §7's idempotency key, so a
 * re-import of the SAME reference is refused by the engine and a corrected file necessarily arrives as
 * a new batch. Un-rejecting in place would let a batch's staged rows change under a decision that had
 * already been made about them.
 *
 * MAKER ≠ CHECKER HOLDS TWO WAYS — the refusal below and the maker≠checker TRIGGER pair
 * `finance_opening_balance_batches_maker_ne_checker_bi` / `_bu` (2026_08_17_100000), which replaced a
 * CHECK of the same name (2026_08_09_100000) because MySQL 5.7 parses and ignores CHECK. Mirrors
 * {@see RejectFeeScheduleChange}.
 */
final class RejectOpeningBalanceBatch
{
    public function handle(OpeningBalanceBatch $batch, string $reason, User $checker): OpeningBalanceBatch
    {
        // Rule 13: no context, no financial governance act (App\Support\SchoolContext).
        SchoolContext::assertOwns($batch, 'opening-balance batch', 'rejected');

        if (trim($reason) === '') {
            throw new BusinessRuleException('A reason is required to reject an opening-balance batch.');
        }

        return DB::transaction(function () use ($batch, $reason, $checker) {
            $locked = OpeningBalanceBatch::query()->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== OpeningBalanceBatchStatus::Submitted) {
                throw new BusinessRuleException(
                    "Only a submitted opening-balance batch can be rejected; this one is {$locked->status->value}."
                );
            }

            if ((string) $locked->submitted_by_user_id === (string) $checker->id) {
                throw new BusinessRuleException(
                    'An opening-balance batch cannot be rejected by its submitter (maker ≠ checker).'
                );
            }

            $locked->update([
                'status' => OpeningBalanceBatchStatus::Rejected,
                'decided_by_user_id' => $checker->id,
                'decided_at' => now(),
                'rejection_reason' => trim($reason),
            ]);

            return $locked->refresh();
        });
    }
}
