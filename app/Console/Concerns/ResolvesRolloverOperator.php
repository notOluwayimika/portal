<?php

namespace App\Console\Concerns;

use App\Models\User;

/**
 * The `--user` resolution both rollover commands need, written once.
 *
 * ── WHY A TRAIT AND NOT THE PLANNER ──────────────────────────────────────────────────────────────
 * This is console I/O: it reads an option and writes errors to the terminal. The planner has no
 * terminal and slice 2's controller resolves its operator from the authenticated request instead, so
 * pushing this into RolloverPlanner would give the planner a dependency neither of its callers
 * shares. The duplication being removed is between the two COMMANDS, which is where it existed —
 * `RunEndOfTerm::resolveOperator` and `RunEndOfYear::resolveOperator` were byte-identical.
 *
 * ── NO SCHOOL-MEMBERSHIP CHECK HERE, DELIBERATELY ────────────────────────────────────────────────
 * The obvious guard — `$user->school_id !== $schoolId` — is the `school-id-fallback-context` pattern
 * the boundary lint refuses and ADR 0042 is retiring: deriving school context from
 * `users.school_id`. It was caught by that lint the first time this code was written, and the right
 * fix was not to work around it but to notice the check does not belong here at all.
 *
 * The causer is ATTRIBUTION ONLY — the jobs receive `$schoolId` explicitly and never read it from
 * the causer (Constitution 13) — so validating the operator against their own row would reintroduce
 * the fallback to answer a question the rollover does not ask. Authorization for running a rollover
 * is shell access to artisan, not a column on the operator.
 *
 * Carried over verbatim with the reasoning intact, because a rule whose justification is dropped
 * during a refactor is a rule someone re-adds six months later.
 */
trait ResolvesRolloverOperator
{
    /**
     * The operator the queued jobs attribute their audit trail to, or null having already said why.
     *
     * `$schoolId` is accepted and deliberately unused — see the docblock. It is kept in the
     * signature because every caller has it and its ABSENCE is the thing worth noticing: a future
     * edit that starts using it is reintroducing the fallback.
     */
    protected function resolveOperator(int $schoolId): ?User
    {
        $userId = $this->option('user');

        if (! $userId) {
            $this->error('--user is required with --commit: the jobs attribute their audit trail to an operator.');

            return null;
        }

        $user = User::find($userId);

        if ($user === null) {
            $this->error("No user with id {$userId}.");

            return null;
        }

        return $user;
    }
}
