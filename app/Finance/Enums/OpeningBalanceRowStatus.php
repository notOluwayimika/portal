<?php

namespace App\Finance\Enums;

/**
 * The outcome of validating one staged extract row (§9 commit 1).
 *
 * The three §5 comparison outcomes do NOT map one-to-one onto these, and conflating them was the
 * available mistake:
 *
 *   equal          → Ok, `expected_billed_minor` set and equal to `wcbs_billed_total_minor`
 *   different      → Ok, `expected_billed_minor` set and DIFFERENT — an exception, which is a
 *                    figure for a human to reconcile (§5), not a defect in the row. The row is
 *                    structurally sound; it carries a `comparison_mismatch` finding with both
 *                    sides and the signed difference.
 *   not comparable → NotComparable, `expected_billed_minor` NULL. Spec §5 is explicit that this is
 *                    NOT an error: it means U1 has not priced that class level yet, and must
 *                    before V2 runs. Never counted as an exception.
 *
 * `Rejected` is orthogonal to all three and wins over them — a row that failed §1's identity, or
 * carried a blank required column, a negative figure, or no resolvable student, is not compared at
 * all. Comparing a row whose arithmetic is already known to be wrong produces a second finding
 * about the first finding.
 */
enum OpeningBalanceRowStatus: string
{
    /** Structurally sound. May still carry a §5 comparison exception. */
    case Ok = 'ok';

    /** Failed a §1/§2/§7 rule. Named findings say which. Nothing about it is comparable. */
    case Rejected = 'rejected';

    /** Sound, but no ACTIVE fee schedule exists for its (term, class level) to compare against. */
    case NotComparable = 'not_comparable';
}
