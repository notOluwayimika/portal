<?php

namespace App\Support;

use App\Models\DataBackfill;

/**
 * Is `contact_points` the source of truth for email reachability YET?
 *
 * THE GATE, AND THE REASON IT IS A CLASS RATHER THAN A CALL. Every deliverability
 * question in the app now asks this first, including inside two loops that already
 * had an N+1 problem — so an unmemoised `DataBackfill::isComplete()` would add one
 * query per row on top of the one this cutover is trying to eliminate.
 *
 * IN App\Support, NOT THE NOTIFICATION MODULE, and the arch suite is what said so.
 * `App\Models\User` reads this, and notification SERVICES are private to their module
 * (blueprint §9/§10) — a Kernel model reaching into them is the dependency direction
 * the boundary exists to forbid. "Has the backfill completed" is a Kernel question
 * anyway: it is about `users.email`'s authority, not about notifications.
 *
 * SCOPED, NOT SINGLETON. A `scoped` binding is rebuilt per request and per test,
 * which is exactly the lifetime this needs: the marker cannot change mid-request, and
 * a plain static would leak the first test's answer into every subsequent test in the
 * process — a memo that makes the whole suite agree with whichever test ran first.
 *
 * FAILS SAFE TO LEGACY. Until the backfill's marker is set, every reader keeps the
 * string behaviour it has today. The window between code-live and backfill-complete
 * is otherwise the one where every flipped reader mis-answers for the WHOLE populated
 * database — bulk messaging no-ops school-wide, password reset refuses everyone.
 */
class ContactPointAuthority
{
    private ?bool $memo = null;

    public function isAuthoritative(): bool
    {
        return $this->memo ??= DataBackfill::isComplete(DataBackfill::CONTACT_POINTS);
    }
}
