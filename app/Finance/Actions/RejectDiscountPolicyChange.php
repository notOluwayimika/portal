<?php

namespace App\Finance\Actions;

use App\Exceptions\BusinessRuleException;
use App\Finance\Enums\DiscountPolicyChangeStatus;
use App\Finance\Models\DiscountPolicyChange;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Axis A checker side — the ED REJECTS a change with a reason. The catalog is untouched; the change is
 * retained (un-deletable) for audit. maker ≠ checker holds via the Policy (403) and the DB CHECK.
 */
final class RejectDiscountPolicyChange
{
    public function handle(DiscountPolicyChange $change, string $reason, User $checker): DiscountPolicyChange
    {
        if ($change->status !== DiscountPolicyChangeStatus::Submitted) {
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
                'status' => DiscountPolicyChangeStatus::Rejected,
                'decided_by' => $checker->id,
                'decided_at' => now(),
                'rejection_reason' => trim($reason),
            ]);

            return $change->refresh();
        });
    }
}
