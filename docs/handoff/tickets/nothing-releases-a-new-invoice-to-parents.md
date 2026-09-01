# Nothing stamps `reviewed_at` on a NEW invoice — the parent payment path is inert after the bulk run

**Raised:** 2026-09-01 · **Severity:** STOP for the 6th · **Owner:** not the payments workstream — Section 0 / Developer 1

## The measurement

Every write to `finance_invoices.reviewed_at` anywhere in the repository — searched across
`*.php`, `*.tsx`, `*.ts`, `*.json`, excluding only `vendor/` and `node_modules/`:

1. the backfill in `2026_08_31_100000_finance_invoices_internal_audit_review.php`, conditioned on
   `reviewed_at IS NULL`, which runs when that migration runs;
2. a test fixture in `tests/Feature/Finance/GatewayInitiateTest.php`.

**That is the complete set.** No controller, no action, no command, no job, no page. Neither
`GenerateInvoice` nor `StartManualInvoiceRun` touches it. There is no invoice-review permission in
`config/rbac.php`, so the capability is not merely unbuilt — it is not scoped.

## The consequence

The backfill covers the population that existed when it ran. Every invoice created **after** that
carries `reviewed_at = NULL` permanently:

- `Invoice::scopeReviewed()` withholds it from the parent feed — invisible;
- `InitiateGatewayPayment` refuses it — unpayable.

So the resumption bulk run produces a book of invoices no parent can see or pay, and **the entire
parent-facing payment feature is inert on the 6th** regardless of whether steps 3–7 are correct.
This is not a defect in the payments work; it is a missing piece upstream of it.

## The question for Developer 1

> The backfill covers the existing population. **What stamps `reviewed_at` on invoices created from
> now on, does it exist, and is it landing before the 6th?**

## A stopgap exists, and it should not be used quietly

The backfill is written to be re-runnable — it stamps only rows where `reviewed_at IS NULL`, and
says so in its own docblock. So re-running it after the bulk run WOULD release the new book.

**It is recorded here as available, not proposed.** Every row it stamps gets `reviewed_by_user_id`
NULL — the migration is explicit that this means *nobody reviewed them*. Using it as an operational
release mechanism buys the 6th by asserting in the audit trail that Internal Audit reviewed a book
it never saw, which is precisely the state Brookstone's 31 August ruling exists to prevent. If it is
used, it should be a stated decision with the audit consequence named, not a quiet migration re-run.

## Why this was not found earlier

The withhold feature and its backfill were reviewed as one change, and the backfill made the parent
screen correct on the day. Nothing in the suite fails: the tests create invoices and stamp the
column, or predate the column entirely. The gap is only visible by asking *what writes this
column in production* — a question no test asks, because the answer "nothing" is not a failing
assertion anywhere.
