<?php

namespace App\Finance\Enums;

/**
 * The outcome of validating one staged extract row (§9 step 4a).
 *
 * TWO VALUES. `NotComparable` is GONE, with §5 (WITHDRAWN under R5/R10) that was its only subject.
 * Its three reasons — `no_active_enrollment`, `enrollment_has_no_class_level`,
 * `no_active_fee_schedule` — went with it, and they lost their SUBJECT rather than merely their
 * input: under R6 the import touches no episode at all, it posts ledger charges keyed to a student.
 *
 * A row is therefore sound or it is rejected, and nothing in between. The checks that decide are
 * §2's required columns, §7's rules, and §1's L1 — which is a check on a STUDENT'S ROW-GROUP, not on
 * a row: when it fails, every row of that student is rejected, because posting three of a student's
 * four lines is worse than posting none (§7).
 *
 * DO NOT RE-ADD AN ENROLLMENT CHECK. A student with no active enrollment posts anyway (§7) — the
 * cutover exists to carry exactly those debtors, and rejecting them would drop the balances it was
 * built for.
 */
enum OpeningBalanceRowStatus: string
{
    /** Structurally sound, and its student's L1 checksum holds. */
    case Ok = 'ok';

    /** Failed a §2/§7 rule, or its student's row-group failed §1's L1. Named findings say which. */
    case Rejected = 'rejected';
}
