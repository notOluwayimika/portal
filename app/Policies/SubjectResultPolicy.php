<?php

namespace App\Policies;

use App\Models\SubjectResultStatus;
use App\Models\User;

/**
 * ADR 0040 mechanism 2 at the application layer — maker ≠ checker for the
 * result workflow (ADR 0044's "resolved the Finance way": a single identity may
 * not both submit and approve the same result).
 *
 * Follows the ExportPolicy pattern: School isolation is upstream
 * (BelongsToSchool), so a Policy only adds permission + record-level rules.
 *
 * UNLIKE ExportPolicy, this one is NOT short-circuited by the super-admin
 * Gate::before: `approve`/`reject` are checker abilities and ApprovalAbility
 * excludes them from the bypass, so these methods actually run for a super
 * admin. That is the whole point — ADR 0040 says no role, super_admin included,
 * bypasses maker ≠ checker.
 *
 * The two mechanisms are independent and both required:
 *  - the bypass exclusion stops platform authority from granting approval;
 *  - this rule stops ANY single identity approving its own submission, which
 *    survives even if someone later re-enables a bypass.
 * Neither implies the other.
 */
class SubjectResultPolicy
{
    /**
     * Submit a subject's results for review (the maker side).
     */
    public function submit(User $user, SubjectResultStatus $status): bool
    {
        return $user->can('result.submit');
    }

    public function approve(User $user, SubjectResultStatus $status): bool
    {
        return $user->can('result.approve') && $this->isNotTheMaker($user, $status);
    }

    public function reject(User $user, SubjectResultStatus $status): bool
    {
        return $user->can('result.reject') && $this->isNotTheMaker($user, $status);
    }

    /**
     * The structural rule: the identity deciding must not be the identity that
     * submitted.
     *
     * A NULL submitted_by means the maker is unknown — a draft that was never
     * submitted, or a pre-C3 row whose submitter the old single-column schema
     * overwrote. Unknown is not evidence of a violation, so the permission
     * decides alone there; the DB constraint carries the same NULL guard, so
     * Policy and schema agree on exactly which rows the rule can speak about.
     */
    private function isNotTheMaker(User $user, SubjectResultStatus $status): bool
    {
        if ($status->submitted_by === null) {
            return true;
        }

        // Compared as strings deliberately. A strict !== between an int id and a
        // string id would report "different identity" for the SAME person and
        // silently ALLOW the self-approval this rule exists to stop — the one
        // direction a type mismatch must never fail in.
        return (string) $status->submitted_by !== (string) $user->id;
    }
}
