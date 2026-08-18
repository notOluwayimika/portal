<?php

namespace App\Finance\Enums;

use App\Finance\Contracts\BillableEnrollmentProvider;

/**
 * What a bulk invoice run made of ONE enrollment (U6 commit 3).
 *
 * FOUR VALUES, AND THEY PARTITION THE ROWS THE RUN WRITES — every enrollment the run SAW lands in
 * exactly one of them, and exactly one row per enrollment is written (unique(school_id, run_id,
 * enrollment_id) holds that at the engine). Three of them come from the cohort
 * ({@see BillableEnrollmentProvider::listForCohort()}) and the fourth from
 * {@see BillableEnrollmentProvider::listUnplaceableForSchool()}; the two lists are disjoint by
 * construction, since a cohort member has both coordinates and an unplaceable one has neither or
 * only one.
 *
 * WHAT IS DELIBERATELY NOT A VALUE HERE: the students the run did NOT see. A billable enrollment
 * sitting at coordinates this run did not name is real, is unbilled, and gets no row — because the
 * run never enumerated it. It is carried as `finance_bulk_invoice_runs.unaccounted_count`, a
 * FIGURE and not a set, which is exactly what
 * docs/handoff/tickets/bulk-run-must-account-for-every-billable-student.md asks for
 * ("a reconciliation count … cheapest, and it answers the requirement exactly as stated"). Adding a
 * fifth case for them would be this enum claiming to enumerate something the run has no list of.
 *
 * `AlreadyBilled` IS NOT AN ERROR, and separating it from {@see self::Failed} is the whole reason
 * this enum has four cases rather than three. A re-run after a partial failure is the NORMAL
 * recovery path: the unique index over `finance_invoices.active_enrollment_key` refuses the second
 * scheduled invoice per episode, and an operator re-running a run that got halfway must be able to
 * see "these forty were already done" without it reading as forty failures.
 *
 * THE DOMAIN IS ENFORCED BY A TRIGGER, NOT A CHECK — see {@see BulkInvoiceRunStatus} for why.
 */
enum BulkInvoiceRunOutcome: string
{
    /** A scheduled invoice was raised for this enrollment by this run. `invoice_id` names it. */
    case Billed = 'billed';

    /**
     * The episode already carried an active scheduled invoice, so this run raised none. `invoice_id`
     * names the invoice that was already there — the pre-existing one, not one this run wrote.
     */
    case AlreadyBilled = 'already_billed';

    /** Billing this one enrollment threw. `reason` carries the message; the run carried on. */
    case Failed = 'failed';

    /**
     * Billable, but its term or its class level is null, so no fee schedule can be keyed to it and no
     * cohort can ever contain it. Recorded AT RUN TIME rather than derived when a screen is opened —
     * the roster moves, and a figure computed later would describe a different School than the one
     * that was billed.
     */
    case Unplaceable = 'unplaceable';
}
