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
 * `->where('status', 'active')` LIVES HERE AND NOWHERE ELSE. A draft is a proposal, not a price: a
 * billing path that accepted a draft would let the Head price a term without the ED ever seeing it —
 * the failure Ruling 2 exists to prevent. Bite-proven by proof 26 (remove the filter → a draft-only
 * term starts pricing → red). School isolation is the model's SchoolScope; this filters lifecycle only.
 */
final class FeeScheduleLookup
{
    public function activeFor(int $termId, int $classLevelId): ?FeeSchedule
    {
        return FeeSchedule::query()
            ->where('term_id', $termId)
            ->where('class_level_id', $classLevelId)
            ->where('status', FeeScheduleStatus::Active->value)
            ->with(['items' => fn ($q) => $q->orderBy('sort_order')])
            ->first();
    }
}
