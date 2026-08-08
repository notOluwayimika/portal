<?php

namespace App\Finance\Actions;

use App\Exceptions\BusinessRuleException;
use App\Finance\Enums\OpeningBalanceBatchStatus;
use App\Finance\Models\OpeningBalanceBatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * §9 step 4c, checker side — a DIFFERENT user approves a submitted opening-balance batch, and
 * APPROVAL IS THE POST. There is no `approved` state between the two: {@see PostOpeningBalanceBatch}
 * runs inside this transaction, so the batch goes submitted → posted in one commit. A state that
 * exists only between two statements of one transaction is not a stage of a workflow.
 *
 * IT RE-IMPLEMENTS NONE OF 4b'S GUARDS, and that is the whole design of this file. G1 (at most one
 * posted batch per school) fires on the status transition inside PostOpeningBalanceBatch, as 4b
 * proved by driver code 1062; G1b's two triggers deny every exit from `posted`; the ok-rows filter,
 * the reserved receipt band, the netted migrated payment and the school-context refusal all live
 * there. Copying any of them here would produce a second place to keep in step, and a PHP guard in
 * front of a database guard is the shape that lets the database guard go untested.
 *
 * WHAT IT ADDS is exactly the gate: the batch must be `submitted` when read UNDER LOCK, and the
 * checker must not be the maker. Both are re-read from the locked row, never from the model passed
 * in — the status and the submitter are the whole authorisation for an irreversible write, and
 * deciding on values fetched before the transaction opened is how two checkers both win a race.
 *
 * MAKER ≠ CHECKER HOLDS TWO WAYS, deliberately, as on every other approval table: the refusal below
 * (a BusinessRuleException a caller can act on) and `finance_opening_balance_batches_maker_ne_checker`
 * (2026_08_09_100000), which refuses the row at the engine even if this method is edited away.
 *
 * SUPER_ADMIN CANNOT REACH THIS at the ability layer: `finance.opening-balance.approve` ends in
 * `approve`, so ApprovalAbility excludes it from the Gate::before bypass (ADR 0040) and a platform
 * authority holds no domain grant of its own (ADR 0045). That exclusion is a property of the NAME, not
 * of a list anyone maintains, and it is what the Action's own maker ≠ checker refusal cannot provide —
 * a super_admin who never submitted the batch would otherwise pass every check in this file.
 */
final class ApproveOpeningBalanceBatch
{
    public function __construct(private readonly PostOpeningBalanceBatch $poster) {}

    /**
     * @return OpeningBalanceBatch the POSTED batch, as PostOpeningBalanceBatch re-read it under the lock
     */
    public function handle(OpeningBalanceBatch $batch, User $checker): OpeningBalanceBatch
    {
        return DB::transaction(function () use ($batch, $checker) {
            $locked = OpeningBalanceBatch::query()->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== OpeningBalanceBatchStatus::Submitted) {
                throw new BusinessRuleException(
                    "Only a submitted opening-balance batch can be approved; this one is {$locked->status->value}."
                );
            }

            // The submitter is read off the LOCKED row. Comparing as strings matches the other four
            // approval actions and is insensitive to one side arriving as an int and the other as a
            // numeric string, which is exactly the comparison a `===` on mixed types gets wrong.
            if ((string) $locked->submitted_by_user_id === (string) $checker->id) {
                throw new BusinessRuleException(
                    'An opening-balance batch cannot be approved by its submitter (maker ≠ checker).'
                );
            }

            // The decision, recorded BEFORE the post. If the post throws — G1's 1062 on a school that
            // already posted, a currency mismatch inside a student's credit lines, anything — this
            // update rolls back with it and the batch is still `submitted`. A decision that survived a
            // failed post would attribute an approval to a cutover that never happened.
            $locked->update([
                'decided_by_user_id' => $checker->id,
                'decided_at' => now(),
            ]);

            // Approval IS the post. Same transaction, same checker as the acting user: on this path
            // `posted_by_user_id` and `decided_by_user_id` are the same person by construction, and
            // they stay separate columns because only one of them is set when a batch is rejected.
            return $this->poster->handle($locked, $checker);
        });
    }
}
