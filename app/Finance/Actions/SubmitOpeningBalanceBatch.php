<?php

namespace App\Finance\Actions;

use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Finance\Approval\ApprovalRequirement;
use App\Finance\Approval\NotifiesApprovalCheckers;
use App\Finance\Enums\OpeningBalanceBatchStatus;
use App\Finance\Models\OpeningBalanceBatch;
use App\Models\User;
use App\Support\ActiveSchool;
use Illuminate\Support\Facades\DB;

/**
 * §9 step 4c, maker side — the bursar office PROPOSES a validated WCBS extract for posting (spec §8).
 * NOTHING IS POSTED HERE: no ledger transaction, no payment, no account movement. The batch moves
 * validated → submitted and waits for a second signature. Mirrors {@see SubmitFeeScheduleChange} file
 * for file, which is the point — §8 asks for "the same shape as the other four request types".
 *
 * THE BATCH IS THE UNIT OF APPROVAL, NOT THE ROW (§8). A checker decides on the extract as a whole,
 * which is also the only decision that means anything: the rows of one WCBS file are one school's
 * closing position, and approving a subset would post a partial cutover that no control total covers.
 *
 * ONLY A `validated` BATCH MAY BE SUBMITTED, and the check happens twice — once outside the
 * transaction for a friendly refusal, once UNDER LOCK inside it, because the status read outside is a
 * value fetched before the transaction opened. Two concurrent submissions of one batch would otherwise
 * both pass the first check; the second one loses at the locked re-read rather than overwriting the
 * first maker's attribution.
 *
 * THERE IS NO "ONE OPEN SUBMISSION PER SCHOOL" KEY, unlike the other four request types' `open_key`,
 * and the reason is that the constraint that matters is already one table-level index down: G1
 * (`ob_batches_posted_school_unique`) permits at most one POSTED batch per school ever, and G1b makes
 * that irreversible. Two submitted batches for one school are therefore not a hazard — at most one of
 * them can ever be approved, and the second's approval fails at the database with 1062 rather than
 * double-posting. Stated here rather than left as a gap for a reader to find.
 */
final class SubmitOpeningBalanceBatch
{
    use NotifiesApprovalCheckers;

    public function handle(OpeningBalanceBatch $batch, User $maker): OpeningBalanceBatch
    {
        // Rule 13: no context, no financial governance act. Every read below goes through SchoolScope,
        // so a mismatched context would silently find nothing and look like a clean refusal.
        $schoolId = ActiveSchool::id();
        if ($schoolId === null) {
            throw new BusinessRuleException('No active School context: an opening-balance batch cannot be submitted.');
        }
        if ((int) $batch->school_id !== $schoolId) {
            throw new BusinessRuleException('That opening-balance batch belongs to another School.');
        }
        if ($batch->status !== OpeningBalanceBatchStatus::Validated) {
            throw new BusinessRuleException(
                "Only a validated opening-balance batch can be submitted for approval; this one is {$batch->status->value}."
            );
        }

        // The maker-checker seam (ADR 0051): always requires a checker today. No amount is passed — a
        // cutover's size is the control total, not a transaction value, and a future threshold rule
        // keying on it would be keying on the wrong number. bin/ci-boundary-lint.php's
        // approval-seam-missing rule requires this call on a LIVE line in every Submit* action.
        if (! ApprovalRequirement::for(Permission::FINANCE_OPENING_BALANCE_SUBMIT->value)->required) {
            throw new \LogicException('Straight-through submission is not implemented — see ADR 0051.');
        }

        $submitted = DB::transaction(function () use ($batch, $maker) {
            $locked = OpeningBalanceBatch::query()->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== OpeningBalanceBatchStatus::Validated) {
                throw new BusinessRuleException(
                    "Only a validated opening-balance batch can be submitted for approval; this one is {$locked->status->value}."
                );
            }

            $locked->update([
                'status' => OpeningBalanceBatchStatus::Submitted,
                'submitted_by_user_id' => $maker->id,
                'submitted_at' => now(),
            ]);

            return $locked;
        });

        // AFTER the commit, never inside it.
        $this->notifyApprovalCheckers(
            subject: $submitted,
            checkerAbility: Permission::FINANCE_OPENING_BALANCE_APPROVE->value,
            submittedBy: (int) $maker->id,
            summary: 'Opening-balance batch '.$submitted->batch_reference.' ('.$submitted->row_count.' rows)',
        );

        return $submitted;
    }
}
