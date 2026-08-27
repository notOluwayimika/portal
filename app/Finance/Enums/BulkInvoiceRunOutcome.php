<?php

namespace App\Finance\Enums;

use App\Enums\ScholarshipKind;
use App\Finance\Contracts\BillableEnrollmentProvider;
use App\Finance\Models\BulkInvoiceRun;

/**
 * What a bulk invoice run made of ONE enrollment (U6 commit 3).
 *
 * FIVE VALUES, AND THEY PARTITION THE ROWS THE RUN WRITES — every enrollment the run SAW lands in
 * exactly one of them, and exactly one row per enrollment is written (unique(school_id, run_id,
 * enrollment_id) holds that at the engine). Four of them come from the cohort
 * ({@see BillableEnrollmentProvider::listForCohort()}) and the fifth from
 * {@see BillableEnrollmentProvider::listUnplaceableForSchool()}; the two lists are disjoint by
 * construction, since a cohort member has both coordinates and an unplaceable one has neither or
 * only one.
 *
 * `Sponsored` IS A COHORT OUTCOME AND NOT AN ABSENCE, and that choice is the whole reason the cohort
 * equality still means something. A sponsored student is IN the cohort — they sit at the run's
 * coordinates and a preview counts them — so filtering them out of the list before the loop would
 * have been the cheaper change and the wrong one, twice over:
 *
 *   1. `outside_coordinates_count` is `billable − cohort − unplaceable_listed`, so every excluded
 *      student would have silently landed in a residual whose NAME says they are priced at other
 *      coordinates, when they are priced at these ones. That figure is large and unalarming on every
 *      healthy run, which is exactly why {@see BulkInvoiceRun} says movement
 *      into it is the thing its shape exists to prevent.
 *   2. There would be no record of WHO was skipped. The school has to invoice these students by hand
 *      once a session; the list of them is the deliverable. Deriving it when a screen opens would
 *      describe a roster that has since moved — the same objection that put the unplaceable list on
 *      rows rather than in a query.
 *
 * So they are walked, recorded, and counted, and the cohort equality gains a term:
 *
 *     billed + already_billed + failed + sponsored == cohort_count
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

    /**
     * In the cohort, and DELIBERATELY NOT BILLED: the student holds a scholarship whose
     * {@see ScholarshipKind} is `sponsored`, so an outside organisation pays on a
     * different fee basis, once a session, by hand and off platform.
     *
     * NOT A FAILURE AND NOT AN ERROR. `invoice_id` and `reason` are both NULL: nothing was refused
     * and nothing went wrong, so there is no reason to carry. It is the run reporting that it saw
     * this student, understood why they are not its business, and left them alone — which is the
     * only way an operator can tell "excluded on purpose" from "quietly missed".
     */
    case Sponsored = 'sponsored';
}
