<?php

namespace App\Finance\Enums;

use App\Finance\Jobs\ProcessManualInvoiceRun;

/**
 * Where a MANUAL invoice run is (slice 1 of the bulk-manual-invoicing brief).
 *
 * The same four states {@see BulkInvoiceRunStatus} carries, and deliberately the same words: a
 * screen showing both kinds of run must not have to learn two vocabularies for the same fact. What
 * differs is what two of them are load-bearing FOR.
 *
 * `Pending` AND `Running` ARE THE NON-TERMINAL PAIR, AND THAT PAIRING IS ENFORCED IN SCHEMA. The
 * generated column `finance_manual_invoice_runs.active_run_key` is `school_id` while `status` is one
 * of these two and NULL otherwise, under a UNIQUE index — so at most one manual run per School can
 * be in either state at a time. Adding a fifth non-terminal status without adding it to that
 * expression would open a hole in the one-active-run guard shaped exactly like the new value.
 *
 * THE DOMAIN IS ENFORCED BY A TRIGGER, NOT A `CHECK`. Production is MySQL 5.7.23, which parses and
 * discards `CHECK` (docs/finance/check-constraints-on-mysql-5-7.md), so a `CHECK` would be enforced
 * locally, absent on the server that holds the money, and green in both places. The trigger's value
 * list is a LITERAL, so a fifth case added here without its own migration is refused by the database
 * at insert time rather than admitted silently — see
 * 2026_08_30_100000_create_finance_manual_invoice_run_tables.php.
 */
enum ManualInvoiceRunStatus: string
{
    /** Inserted with its targets and its lines, not yet picked up by a worker. */
    case Pending = 'pending';

    /** {@see ProcessManualInvoiceRun} is walking the target list. */
    case Running = 'running';

    /**
     * TERMINAL. The list was walked to the end. NOT a promise that everyone was billed — a run
     * completes with `failed` rows in it, and it completes with a stuck `claimed` row in it too, in
     * which case `billed_count + failed_count < target_count` is the alarm that says so.
     */
    case Completed = 'completed';

    /**
     * TERMINAL. A per-run condition stopped it before the first claim, or the worker died;
     * `failure_reason` says which. NOT a promise that nothing was billed: a death mid-list leaves
     * behind every invoice raised before it, which is why re-running is a decision rather than a
     * reflex — a manual run has no `already_billed` outcome to make a second pass safe, because the
     * supplementary invoice it raises has no duplicate backstop at any layer
     * (docs/handoff/tickets/a-supplementary-invoice-has-no-duplicate-backstop.md).
     */
    case Failed = 'failed';

    /**
     * TERMINAL means "this run has stopped and will not write another row" — `completed` or
     * `failed`, and nothing else.
     *
     * IT LIVES HERE BECAUSE THREE PLACES ASK THE SAME QUESTION and a fourth would drift. The
     * generated column `active_run_key` (2026_08_30_100000) is the first: it is `school_id` while
     * the status is `pending` or `running` and NULL otherwise, so the set this method returns FALSE
     * for is exactly the set that holds a School's one-active-run key. `ManualInvoiceRunController`
     * asks it to refuse a second start and to name the run in flight; the run report asks it to
     * decide whether the cohort equality is answerable YET — mid-run a shortfall is normal, and
     * reporting "does not balance" over a run that is still working would fire the alarm on every
     * healthy run.
     *
     * Written as a positive list of the two terminal cases rather than a negation of the two
     * non-terminal ones, so a fifth status added later is NOT silently terminal.
     */
    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Failed;
    }
}
