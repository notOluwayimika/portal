<?php

namespace App\Finance\Policies;

use App\Finance\Models\CreditNote;
use App\Models\User;

/**
 * ADR 0040 mechanism 2 for credit notes — maker ≠ checker on the DECISION (approve /
 * reject). Mirrors {@see SubjectResultPolicy}: the permission decides whether a user may
 * check at all; the record-level rule decides whether THIS user may check THIS note (they
 * may not, if they submitted it).
 *
 * Like SubjectResultPolicy, these methods are NOT short-circuited by the super-admin
 * Gate::before: `approve`/`reject` are checker abilities that ApprovalAbility excludes
 * from the bypass, so they actually run for a super admin — ADR 0040's whole point.
 *
 * Submission is not here: a credit note does not exist yet at submit time, so "may submit"
 * is a pure permission gate carried by route middleware (permission:finance.credit-note.
 * submit), exactly as C1 gated issuance.
 *
 * The two guarantees are independent and both required: this stops any single identity
 * approving its own submission; the DB CHECK (submitted_by <> decided_by) stops it even
 * for a raw write that never passes through here.
 */
class CreditNotePolicy
{
    public function approve(User $user, CreditNote $creditNote): bool
    {
        return $user->can('finance.credit-note.approve') && $this->isNotTheMaker($user, $creditNote);
    }

    public function reject(User $user, CreditNote $creditNote): bool
    {
        return $user->can('finance.credit-note.reject') && $this->isNotTheMaker($user, $creditNote);
    }

    /**
     * The structural rule: the checker must not be the maker. A NULL submitted_by (a
     * backfilled pre-Ph3 row) means the maker is unknown — not evidence of a violation —
     * so the permission decides alone there, exactly as the DB CHECK's NULL guard admits
     * those rows.
     *
     * Compared as strings deliberately: a strict !== between an int id and a string id
     * would report "different identity" for the SAME person and silently ALLOW the
     * self-approval this rule exists to stop — the one direction a type mismatch must
     * never fail in.
     */
    private function isNotTheMaker(User $user, CreditNote $creditNote): bool
    {
        if ($creditNote->submitted_by === null) {
            return true;
        }

        return (string) $creditNote->submitted_by !== (string) $user->id;
    }
}
