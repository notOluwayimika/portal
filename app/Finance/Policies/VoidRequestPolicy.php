<?php

namespace App\Finance\Policies;

use App\Finance\Models\VoidRequest;
use App\Models\User;

/**
 * ADR 0040 mechanism 2 for invoice voids — the SECOND maker-checker instance, structurally
 * identical to {@see CreditNotePolicy}. The permission decides whether a user may check at all;
 * the record-level rule decides whether THIS user may check THIS request (they may not, if they
 * submitted it).
 *
 * As with CreditNotePolicy, `approve`/`reject` are NOT short-circuited by the super-admin
 * Gate::before — ApprovalAbility excludes the checker abilities from the bypass, so they run for
 * a super admin too. Submission is not here: a route permission gate carries "may submit"
 * (permission:finance.invoice.void-request.submit), exactly as credit-note submit is gated.
 *
 * Two independent, both-required guarantees: this stops any single identity approving its own
 * submission; the DB CHECK (submitted_by <> decided_by) stops it even for a raw write that never
 * passes through here.
 */
class VoidRequestPolicy
{
    public function approve(User $user, VoidRequest $voidRequest): bool
    {
        return $user->can('finance.invoice.void-request.approve') && $this->isNotTheMaker($user, $voidRequest);
    }

    public function reject(User $user, VoidRequest $voidRequest): bool
    {
        return $user->can('finance.invoice.void-request.reject') && $this->isNotTheMaker($user, $voidRequest);
    }

    /**
     * The checker must not be the maker. A NULL submitted_by means the maker is unknown — the
     * permission decides alone there, as the DB CHECK's NULL guard admits. Compared as strings
     * deliberately: a strict !== between an int id and a string id would report "different
     * identity" for the SAME person and silently ALLOW self-approval — the one direction a type
     * mismatch must never fail in.
     */
    private function isNotTheMaker(User $user, VoidRequest $voidRequest): bool
    {
        if ($voidRequest->submitted_by === null) {
            return true;
        }

        return (string) $voidRequest->submitted_by !== (string) $user->id;
    }
}
