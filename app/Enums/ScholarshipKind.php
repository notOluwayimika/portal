<?php

namespace App\Enums;

use App\Finance\Jobs\ProcessBulkInvoiceRun;

/**
 * WHICH SCHEME a scholarship is — the fact that decides whether the bulk invoice run bills its
 * holders at all. Assigned to the SCHOLARSHIP, not to the student: `students.scholarship_id` says
 * which scheme a child is on, and this says what that scheme means for billing.
 *
 * IT LIVES IN App\Enums AND NOT IN App\Finance\Enums, deliberately. A scholarship is a School setup
 * record assigned at admission, and `App\Models\Scholarship` is a core model — putting the enum in
 * the Finance namespace would make the Kernel's own model depend on a Module, which is the one
 * direction the Module Blueprint forbids. Finance reads it; Finance does not own it.
 *
 * TWO VALUES, AND NULL IS A THIRD STATE THAT IS NOT A VALUE.
 *
 *   Discount  — the school reduces the bill. The parent still pays, less. These students ARE in the
 *               cohort and ARE billed by the run from the standard fee schedule, MINUS a percentage
 *               reduction where they hold a standing award — a row in
 *               `finance_student_discount_awards`. NAMED IN PROSE AND NOT AS A `{@see}`, for the
 *               reason stated below about where this enum lives: resolving that tag needs a
 *               `use App\Finance\Models\...` at the top of this file, which is a COMPILE-TIME
 *               Kernel-to-Module reference and is what `Finance models are private to the Finance
 *               module` (tests/Arch/ArchitectureBoundaryTest.php) exists to refuse. The award is
 *               what carries the reduction; `kind = discount` only says the child is on a scheme
 *               that may have one.
 *               A discount-scholarship holder with NO award is billed the standard schedule in full,
 *               exactly as before — which is the honest state of a scheme that has been declared but
 *               not yet priced, not an oversight.
 *
 *   Sponsored — an outside organisation pays, on a different fee basis, once a session, by hand and
 *               off platform. These students are EXCLUDED from the bulk run. Billing them the
 *               standard schedule would produce a full-price invoice to a parent who owes nothing,
 *               on a run that reports success.
 *
 *   NULL      — NOBODY HAS SAID YET. Not a default and not a third scheme. `scholarships.kind`
 *               backfills to NULL because nothing in the existing data says which scheme any
 *               scholarship is: the table held a name and nothing else. A default would have
 *               GUESSED, and the wrong guess bills real children the wrong amount in the direction
 *               nobody checks. {@see ProcessBulkInvoiceRun} refuses to run a
 *               cohort containing an unconfigured scholarship rather than falling through to the
 *               standard schedule, because a fall-through is indistinguishable from correct
 *               behaviour on screen until a sponsored parent opens a full-price invoice.
 *
 * THE DOMAIN IS ENFORCED BY A TRIGGER, NOT A `CHECK` — production is MySQL 5.7.23, which parses and
 * discards `CHECK` (docs/finance/check-constraints-on-mysql-5-7.md). See
 * `2026_08_26_100000_add_kind_to_scholarships_table.php`.
 */
enum ScholarshipKind: string
{
    /** The school discounts the bill. Billed by the bulk run; the discount itself is not built yet. */
    case Discount = 'discount';

    /** An outside organisation pays, off platform. NOT billed by the bulk run. */
    case Sponsored = 'sponsored';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
