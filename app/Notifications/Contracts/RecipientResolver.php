<?php

namespace App\Notifications\Contracts;

use App\Notifications\DTOs\Recipient;

/**
 * Who receives a notification. PRIVATE to the module; registered per type.
 *
 * ALWAYS INVOKED INSIDE THE NOTIFICATION'S SCHOOL CONTEXT (ActiveSchool::runFor),
 * so a resolver may rely on the global SchoolScope and must never filter by
 * `users.school_id` itself — that column is the legacy fallback (ADR 0042), and
 * with `school_user` being a pivot it is wrong for any multi-school user.
 *
 * Resolvers enumerate STORED grants. They do not test users one at a time with
 * `can()`: "who are all the checkers here?" is the inverse of "may this user
 * approve?", and an all-users sweep answering it does not scale and quietly
 * sweeps in bypass-only super admins.
 */
interface RecipientResolver
{
    /** @return iterable<Recipient> */
    public function resolve(Notification $notification): iterable;
}
