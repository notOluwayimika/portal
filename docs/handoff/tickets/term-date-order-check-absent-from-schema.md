# `terms_end_after_start_check` is in the migration ledger and not in the schema

**Raised by:** the cold review of U1 commit 1 (`feat/fee-schedules-data-surface`). **Reproduced by
that reviewer, not by the author of this file and not by the project lead** — the finding is recorded
here under the reviewer's reproduction, attributed, because neither of the other two had a MySQL
client to run it.

## What the reviewer found, on the dev copy `portaa10_portal`

- The `migrations` table carries `2026_07_28_120000_add_term_date_order_check` at **batch 11**.
- `information_schema.CHECK_CONSTRAINTS` returns **15** constraints for that schema and **0** matching
  `%term%`. Fifteen present means this is not a MySQL-version artefact or a malformed query — the
  other CHECK constraints in the same schema are visible to the same read.
- `database/migrations/2026_07_28_120000_add_term_date_order_check.php:34-40` runs its
  `ALTER TABLE terms ADD CONSTRAINT terms_end_after_start_check CHECK (…)` **unconditionally**. There
  is no environment guard, no `if`, no try/catch — no path by which the migration could report itself
  as run while adding nothing.

So the ledger says the constraint was applied and the schema says it is not there.

## Why it matters

The migration's own docblock states the stake: `finance_fee_schedules.term_id` is a RESTRICT foreign
key, so a term's window prices a fee schedule, and "a backwards term is no longer a cosmetic error —
it reaches money, and it cannot be deleted away once priced". Application validation binds only the
one path that runs it; seeders, jobs, tinker, a future import and the TermSeeder invoked from inside
a migration all write terms without reaching `TermController`. The database CHECK was the control.
On this copy there is no control.

This is ADR 0052's rule in the flesh: **verify by SHAPE, not by exit code.** A migration reporting
`Ran` is an exit code. The constraint's presence in `information_schema` is the shape, and the two
disagree.

## The open question, which is the project lead's

**Is production in the same state?** Nobody has looked, and it is not a thing to check from a dev
machine. If production also lacks the constraint, then term windows there are unconstrained and have
been since 2026-07-28 — and the fix is a fresh named migration that adds it, not a rollback and re-run
of one the ledger already claims.

## What would close this

1. Determine whether production carries `terms_end_after_start_check` (lead).
2. Determine how the dev copy lost it — a dump/restore that dropped CHECK constraints is the obvious
   candidate and would mean the copy, not the migration, is what went wrong.
3. Either way: a roll-forward migration that adds the constraint if absent, and an arm that asserts
   its presence from `information_schema` rather than asserting the migration ran. `FeeScheduleTest`'s
   proof 24b is the pattern — it reads index shape out of `information_schema` for exactly this
   reason.
