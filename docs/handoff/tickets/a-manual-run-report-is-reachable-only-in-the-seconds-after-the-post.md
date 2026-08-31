# A manual run report is reachable only in the seconds after the POST

**Status:** open. Measured 31 August against `7a7848c`. The feature works; what is missing is any
second visit to its own record.

## The design says this report is the whole of the oversight

Two places in the shipped code say it, in almost the same words.

`app/Finance/Models/ManualInvoiceRun.php` — "This feature issues DIRECTLY with no maker-checker
(Brookstone, 30 August 2026), so this report is the only place a wrong selection can surface."

`resources/js/components/app-sidebar.tsx:515-517` — "It is also the one act in this group with no
approval step anywhere behind it, which is why its screen's confirmation and its run report carry
the whole of the oversight."

Both are true. The consequence neither of them draws is that the report has to be *findable* for
that to mean anything.

## There is no way back to it

API routes, `routes/endpoints/finance.php:462-466`:

    POST /v1/finance/manual-invoice-runs
    GET  /v1/finance/manual-invoice-runs/students
    GET  /v1/finance/manual-invoice-runs/{run:uuid}

There is no index. Web routes, `routes/web.php:378` and `:432`: `/finance/manual-invoice-runs`
renders the CREATE screen — the URL that reads like an index is already taken — and
`/finance/manual-invoice-runs/{run}` renders the report. The sidebar has one entry
(`app-sidebar.tsx:520`) and it points at create. `ManualInvoiceRun` has no `LogsActivity`.

So a run's uuid exists in exactly one place a human can reach: the address bar, in the moments after
the POST. Close the tab and a run that billed ninety-one families is not findable anywhere in the
application. The only recovery is a `SELECT` against `finance_manual_invoice_runs`, which is a
developer's tool and not a bursar's.

## It compounds with the 200-row truncation

See `the-manual-run-report-truncates-at-200-and-the-unplaceable-can-hide.md`. That ticket assumes an
operator can come back to the report and work through the `unplaceable` bucket. Today, leaving the
page to look a child up is enough to lose it.

## Nothing needs migrating

`finance_manual_invoice_runs` already carries everything a listing needs — `started_by_user_id`,
`started_at`, `finished_at`, `status`, and `target_count` / `billed_count` / `failed_count`
(`createRuns`,
`database/migrations/2026_08_30_100000_create_finance_manual_invoice_run_tables.php:276-307`). What
is absent is a route and a screen, not a column.

## What closes it

Any of these, and the choice is a decision:

- An index route and screen listing this school's runs, newest first, each linking to its report.
- An activity-log entry on run completion naming the run, so it is discoverable from the audit trail
  the rest of Finance already writes to.
- The create screen showing the last run's outcome on load, which costs one query and covers the
  common case of a bursar returning the same day.

**Decide the ability rather than inheriting one.** A list of past runs is billing history, not the
act of billing. `finance.invoice.generate` is the ability to CHARGE; reading who charged whom last
term may reasonably belong to a wider seat, or a narrower one. Pick it deliberately — a route that
copies the create screen's permission because that was the nearest example is how the guardians page
and its API came to disagree.
