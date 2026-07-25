<?php

namespace App\Finance\Actions;

use App\Exceptions\BusinessRuleException;
use App\Finance\Enums\VoidRequestStatus;
use App\Finance\Models\VoidRequest;
use App\Models\User;

/**
 * Ph3b checker side — REJECT a pending void request with a reason. The invoice is NEVER
 * touched and NO ledger entry is posted — the charge stands. The request is retained for
 * audit in a terminal `rejected` state; a fresh request is the path to try again.
 *
 * A reason is required at the domain layer (VoidRequest::transitionTo throws on an empty
 * reason). Maker ≠ checker holds the same three ways as approval (Policy / this guard / DB CHECK).
 */
final class RejectVoidRequest
{
    public function handle(VoidRequest $request, User $checker, string $reason): VoidRequest
    {
        if (! $request->isPending()) {
            throw new BusinessRuleException('Only a pending void request can be rejected.');
        }

        if ((string) $request->submitted_by === (string) $checker->id) {
            throw new BusinessRuleException('A void request cannot be rejected by its submitter (maker ≠ checker).');
        }

        $request->transitionTo(VoidRequestStatus::Rejected, (int) $checker->id, $reason);

        return $request->refresh();
    }
}
