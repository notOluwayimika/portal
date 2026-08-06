<?php

namespace App\Finance\Enums;

/**
 * The lifecycle of one staged WCBS opening-balance extract (§9 commit 1).
 *
 * THREE VALUES, ON PURPOSE. The posting states (`approved`, `posted`) belong to commit 4 and are
 * absent because nothing in this commit can reach them — a state a code path cannot produce is a
 * claim about a feature that does not exist.
 *
 * `Validated` means every staged row is structurally sound AND the batch carries no batch-level
 * finding. It does NOT mean the figures agree with the portal's prices: a §5 comparison mismatch is
 * an exception for a human to look at (spec §5), not a structural defect, so it leaves the batch
 * validated and is carried on the row.
 */
enum OpeningBalanceBatchStatus: string
{
    /** Inserted, not yet run to completion. */
    case Draft = 'draft';

    /** Every row structurally sound; no batch-level finding. */
    case Validated = 'validated';

    /** At least one rejected row, or at least one batch-level finding. */
    case Rejected = 'rejected';
}
