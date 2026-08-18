<?php

namespace App\Finance\Enums;

use App\Finance\Jobs\ProcessBulkInvoiceRun;
use App\Finance\Services\FeeScheduleLineMapper;

/**
 * The lifecycle of one bulk invoice run (U6 commit 3).
 *
 * FOUR VALUES, AND EVERY ONE IS OCCUPIED FOR A REAL INTERVAL. That is the test
 * {@see OpeningBalanceBatchStatus} applies to itself — it refused an `approved` state because
 * approval posts in the same transaction, so no batch ever sits in it for the duration of a
 * statement — and it is the test applied here:
 *
 *   Pending   — the row exists, the worker has not picked the job up. Minutes, on a busy queue.
 *   Running   — {@see ProcessBulkInvoiceRun} is iterating the cohort. Seconds
 *               to minutes; it is the whole point of doing this off the request.
 *   Completed — the run reached the end of the cohort. TERMINAL.
 *   Failed    — a PER-RUN condition stopped it before the first invoice. TERMINAL.
 *
 * `Pending` and `Running` are genuinely different facts and telling them apart is the reason both
 * exist: a run stuck in `pending` means the queue is not being worked, and a run stuck in `running`
 * means the worker died mid-cohort. One is an infrastructure problem and the other is a data
 * problem, and a single `in_progress` value would make the screen say the same thing about both.
 *
 * `Completed` DOES NOT MEAN EVERY STUDENT WAS BILLED, and reading it that way is the §26
 * silent-partial-result defect this whole commit exists to close. A completed run may carry failed
 * rows, already-billed rows, unplaceable rows and a non-zero `unaccounted_count`. It means the run
 * reached the end of the cohort and wrote down what happened to each member — nothing more. The
 * counts are the report; the status is only whether the report is finished.
 *
 * `Failed` IS PER-RUN AND NEVER PER-STUDENT. It is reached exactly three ways, all of them before
 * any invoice is raised: no active fee schedule at the run's coordinates, a schedule the mapper
 * refuses ({@see FeeScheduleLineMapper::linesFor()}, five refusals), or the
 * job itself dying. A student who cannot be billed does NOT fail the run — that is a row with
 * {@see BulkInvoiceRunOutcome::Failed} and a reason, and the run carries on to the next student.
 *
 * THE DOMAIN IS ENFORCED BY A TRIGGER, NOT A CHECK. Production is MySQL 5.7.23, which parses and
 * discards `CHECK` (docs/finance/check-constraints-on-mysql-5-7.md), so a `CHECK` here would be
 * enforced locally and absent on the server that holds the money. See
 * 2026_08_18_110000_create_finance_bulk_invoice_run_tables.php.
 */
enum BulkInvoiceRunStatus: string
{
    /** Inserted, not yet picked up by a worker. */
    case Pending = 'pending';

    /** The job is iterating the cohort. */
    case Running = 'running';

    /** TERMINAL. The cohort was walked to the end and every member has a row. */
    case Completed = 'completed';

    /** TERMINAL. A per-run condition stopped it; `failure_reason` says which. No invoice was raised. */
    case Failed = 'failed';
}
