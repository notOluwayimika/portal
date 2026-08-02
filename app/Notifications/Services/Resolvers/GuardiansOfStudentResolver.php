<?php

namespace App\Notifications\Services\Resolvers;

use App\Models\Guardian;
use App\Notifications\Contracts\Notification;
use App\Notifications\Contracts\RecipientResolver;
use App\Notifications\DTOs\Recipient;
use App\Notifications\Enums\RecipientReason;
use LogicException;

/**
 * The guardians of one student, as user accounts.
 *
 * The relationship-derived counterpart to CheckerAbilityResolver, and the second
 * of the two shapes every later type reuses.
 *
 * ONE STUDENT PER NOTIFICATION, deliberately. A guardian with three children gets
 * three notifications, each carrying its own child as subject and its own
 * deep-link — the feed is where a parent expects to click through per child, so
 * collapsing here would destroy information. The collapse belongs on the OUTBOUND
 * side (v2 bundling: three feed rows, one email), which is exactly why it is not
 * expressible as a dedup key on the event.
 *
 * NO SCHOOL FILTER IS WRITTEN HERE and none should be. Guardian is School-scoped
 * through BelongsToSchool, and the resolver runs inside ActiveSchool::runFor, so
 * the global SchoolScope applies. Filtering by `users.school_id` instead would be
 * the legacy fallback the Constitution forbids (rule 13 / ADR 0042) and would be
 * simply wrong for a guardian with children at both schools.
 */
class GuardiansOfStudentResolver implements RecipientResolver
{
    public function resolve(Notification $notification): iterable
    {
        $studentId = $notification->payload()['student_id'] ?? null;

        if (! is_int($studentId)) {
            throw new LogicException(
                'GuardiansOfStudentResolver requires an integer `student_id` in the '
                .'notification payload; none was given.'
            );
        }

        $guardians = Guardian::query()
            ->whereHas('students', fn ($query) => $query->where('students.id', $studentId))
            ->with('user:id,deleted_at,disabled_at')
            ->get();

        foreach ($guardians as $guardian) {
            $user = $guardian->user;

            // A guardian without a login is a real and common case here — bulk
            // enable-login exists as its own job. They are simply not reachable
            // in-app; email reaches them in v2 through contact_points.
            if ($user === null || $user->disabled_at !== null) {
                continue;
            }

            yield Recipient::user((int) $user->id, RecipientReason::RELATIONSHIP);
        }
    }
}
