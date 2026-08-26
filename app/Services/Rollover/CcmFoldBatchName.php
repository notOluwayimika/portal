<?php

namespace App\Services\Rollover;

/**
 * The name of a CCM-fold batch, written in ONE place and matched from that same place.
 *
 * Sibling of {@see RolloverBatchName}, and it exists for the reason that one does: a panel reading
 * `job_batches` with a hand-written LIKE drifts from the writer, and the drift shows as "no batches
 * running" — which is indistinguishable from a finished fold while jobs are still draining.
 *
 * ── WHY THE FOLD BATCH IS NAMED APART FROM THE ROLLOVER BATCH ────────────────────────────────────
 * They are dispatched from the same screen, seconds apart, and they mean opposite things: the fold
 * CLEARS the blocker, the rollover is what the blocker was stopping. A shared prefix would let one
 * appear as the other in the panel — an operator seeing "draining" after clicking Fold has to know
 * WHICH operation is draining before the gate re-evaluates, or they will confirm a rollover against
 * a fold that has not finished.
 */
final class CcmFoldBatchName
{
    public const KIND = 'ccm-fold';

    public static function forTerm(int $schoolId, int $termId): string
    {
        return self::KIND.":school:{$schoolId}:term:{$termId}";
    }

    /** Every fold batch for one school — the panel's filter, derived from the writer above. */
    public static function likeForSchool(int $schoolId): string
    {
        return self::KIND.":school:{$schoolId}:%";
    }

    public static function isFold(string $batchName): bool
    {
        return str_starts_with($batchName, self::KIND.':');
    }
}
