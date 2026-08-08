<?php

namespace App\Finance\Enums;

use App\Finance\Actions\ApproveOpeningBalanceBatch;
use App\Finance\Actions\PostOpeningBalanceBatch;

/**
 * The lifecycle of one staged WCBS opening-balance extract (§9 commit 1, extended by steps 4b and 4c).
 *
 * FIVE VALUES, AND `approved` IS STILL ABSENT — 4c added `submitted`, and DELIBERATELY NOT `approved`.
 * The transition is validated → submitted → posted (approve) or rejected (reject). Approval POSTS in
 * the SAME TRANSACTION ({@see ApproveOpeningBalanceBatch}), so an `approved`
 * state would be one no batch ever occupies for the duration of a single statement — a claim about a
 * stage of the workflow that does not exist. §7 refuses decoration of exactly that shape, and it is
 * the same reasoning that kept `posted` out until 4b and `submitted` out until 4c.
 *
 * WHAT `rejected` NOW MEANS, because 4c widened it and a reader will otherwise assume the narrower
 * sense: it is reached two ways — structurally, by the validator (a rejected row or a batch-level
 * finding), and by GOVERNANCE, when a checker rejects a submitted batch. The two are told apart by
 * `rejection_reason` (non-null only on the governance path) and `decided_by_user_id`, both added by
 * 2026_08_09_100000. Neither posts anything.
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

    /** Every row structurally sound; no batch-level finding. The only state a maker may submit from. */
    case Validated = 'validated';

    /**
     * Awaiting a second signature (§8). The maker is on `submitted_by_user_id`; a DIFFERENT user
     * approves or rejects. This is the ONLY state {@see PostOpeningBalanceBatch}
     * will post from — 4b posted straight from `validated`, and 4c closed that door rather than
     * leaving two ways in to the one irreversible write in this feature.
     */
    case Submitted = 'submitted';

    /**
     * At least one rejected row, or at least one batch-level finding — OR a checker rejected the
     * submission (`rejection_reason` non-null). Nothing is posted either way.
     */
    case Rejected = 'rejected';

    /**
     * TERMINAL. The batch's balances are in the subledger: a ledger charge per positive fee-type line
     * and one netted migrated payment per student in credit (§3). It is terminal AT THE DATABASE, not
     * by convention — the generated `posted_school_key` holds at most one posted batch per school
     * (G1), and two triggers deny both ways out of the state, UPDATE and DELETE (G1b). Both exist
     * because the ledger charges a post writes can never be deleted, so re-opening the slot would
     * double-count the arrears permanently.
     */
    case Posted = 'posted';
}
