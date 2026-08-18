<?php

namespace App\Finance\Services;

use App\Finance\Enums\FeeScheduleStatus;
use App\Finance\Models\FeeSchedule;

/**
 * The schedule → billing read path (S1 commit 2). Resolves (term, class level) to the ACTIVE schedule
 * and its items, for prefilling the bursar's generate form. This is the smallest possible read path —
 * GenerateInvoice still takes its lines from the wire (server-side pricing is S2); what commit 2 owes
 * is exactly one status filter, in exactly one place.
 *
 * THE STATUS FILTER NO LONGER LIVES HERE. It reads {@see FeeScheduleStatus::billable()}, the one home
 * for "which lifecycle states may be billed from", which {@see FeeScheduleLineMapper::linesFor()} also
 * reads. The previous sentence here said the filter "LIVES HERE AND NOWHERE ELSE"; U6 commit 2 made
 * that false by adding a second site, and cold review made it a finding. A draft is still a proposal
 * and not a price — a billing path that accepted one would let the Head price a term without the ED
 * ever seeing it, the failure Ruling 2 exists to prevent — but the RULE is now a shared symbol rather
 * than a literal repeated per site.
 *
 * `whereIn` over a one-member set is behaviour-identical to the `where('status', 'active')` it
 * replaces, and it MOVES when the set does, which a literal cannot. Bite-proven twice over: by proof
 * 26 (remove the filter → a draft-only term starts pricing → red) and now by widening
 * FeeScheduleStatus::billable() (→ this read AND the mapper both go red, which is the property the
 * shared symbol exists to give). School isolation is the model's SchoolScope; this filters lifecycle
 * only.
 */
final class FeeScheduleLookup
{
    public function activeFor(int $termId, int $classLevelId): ?FeeSchedule
    {
        return FeeSchedule::query()
            ->where('term_id', $termId)
            ->where('class_level_id', $classLevelId)
            ->whereIn('status', FeeScheduleStatus::billableValues())
            ->with(['items' => fn ($q) => $q->orderBy('sort_order')])
            ->first();
    }
}
