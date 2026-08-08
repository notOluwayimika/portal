<?php

namespace App\Finance\Enums;

/**
 * The lifecycle of one staged WCBS opening-balance extract (§9 commit 1, extended by step 4b).
 *
 * FOUR VALUES, AND `approved` IS STILL ABSENT ON PURPOSE. §9's build order splits commit 4: 4b is the
 * posting Action, 4c is §8's maker-checker gate — and two lints make 4c indivisible (a new Permission
 * `*_SUBMIT` case must arrive with a Submit* action, and a grantsMap addition must arrive with a
 * migration). So today's transition is validated → posted, and 4c inserts `approved` between the two
 * when it ships the gate that can produce it. A state no code path can reach is a claim about a
 * feature that does not exist, which is exactly why `posted` itself was absent until 4b.
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
