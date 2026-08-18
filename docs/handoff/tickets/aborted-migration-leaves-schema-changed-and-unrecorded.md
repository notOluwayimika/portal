# An aborted migration leaves the schema changed and the migration unrecorded

**Raised** 2026-08-18, on `feat/supplementary-invoices`, from a cold review of
`2026_08_18_100000_finance_invoices_kind_and_scheduled_only_episode_guard.php`.
**Scope** every migration in this repository, not that one.
**Severity** ticket. Nothing is wrong today; this is a standing condition with no stated operator
remedy.

## The condition

MySQL commits DDL implicitly. Every `ALTER TABLE` and `CREATE TRIGGER` ends any open transaction, so
`DB::transaction()` around a migration's `up()` provides the appearance of atomicity and none of the
substance. Laravel records a migration in the `migrations` table only after `up()` **returns**.

Together those give: **any abort part-way through a multi-statement migration leaves the schema
partly changed and the `migrations` table disagreeing with it.** The database is in a state that is
neither "before" nor "after", and nothing detects it, announces it, or names the way out.

## Both directions, and `down()` is the silent one

The two directions fail differently, and the rollback leg is worse. It is also the leg
`bin/quality-clean-db` exercises on every release, so it is not the rarer path.

| | aborted `up()` | aborted `down()` |
| --- | --- | --- |
| schema | partly changed | partly reverted |
| `migrations` row | **absent** (never written) | **absent** (deleted) |
| what the row implies | "not applied" — true of some statements, false of the ones that committed | "not applied" — false of every statement that had not been reverted yet |
| `php artisan migrate` next | **re-runs the file**, so a guarded, re-runnable `up()` converges | **"Nothing to migrate."** The file is already un-recorded, so there is nothing to re-run |
| net | loud, recoverable | **silent** |

The asymmetry is the whole finding. On `up()` the unrecorded row means the operator's next `migrate`
attempts the work again, and if the statements are individually re-runnable it converges. On
`down()` the row is deleted **before** the operator learns anything went wrong, so the next `migrate`
says *"Nothing to migrate"* and exits 0 against a database that is half-reverted. Nothing announces
it and nothing will.

Made concrete with the migration that raised this
(`2026_08_18_100000_finance_invoices_kind_and_scheduled_only_episode_guard.php`). Its `down()` drops
three triggers, then re-keys the generated column in one `ALTER`, then reads the shape back, then
drops the column. An abort at that read-back leaves:

- `finance_invoices.kind` still present, still `NOT NULL`;
- `active_enrollment_key` still keyed on `kind` (or not — that is exactly what the read-back could
  not confirm, which is why it threw);
- **all three `kind` triggers gone** — so the column's domain is unenforced and, worse, `kind` is
  mutable again, which is the state the immutability trigger exists to prevent: flip a live
  scheduled invoice to supplementary and the episode's slot frees while it is still collecting
  payments;
- `migrate` reporting *"Nothing to migrate."*

A half-reverted money table with its guards removed and a clean bill of health from the only command
anyone runs to check.

This is sharpest in migrations that deliberately abort — the ones that read their own work back from
`information_schema` and throw rather than record a green that means nothing (ADR 0052). Those
throws are correct and should stay. They are also, precisely, the ones that leave a half-applied
schema behind.

## Why it is not the migration that surfaced it

`2026_08_18_100000` was reviewed and its `ADD COLUMN` made idempotent (guarded on
`information_schema.COLUMNS`), joining its `ALTER` and its `DROP TRIGGER IF EXISTS`-prefixed
`CREATE TRIGGER`s. Every statement in that file is now individually re-runnable, so a retry after an
abort proceeds instead of dying on `1060 Duplicate column name 'kind'`.

**Re-runnability is not atomicity.** It makes the retry work when the operator thinks to retry. It
does not tell them the database is half-applied, and it is a property each migration has to earn
one statement at a time — most in this tree have not.

## What is actually missing

1. **Detection.** After an aborted deploy there is no command that answers "is the schema consistent
   with the recorded migrations?". `migrate:status` reports what is *recorded*, which is exactly the
   thing that is wrong — and after an aborted `down()` it reports the file as not applied, which is
   the most confident possible statement of the wrong answer.
2. **A stated remedy.** No runbook says what an operator does when `migrate` dies mid-file. The
   answer today is "read the migration and undo the committed statements by hand", derived under
   pressure, on production, with no rehearsal.
3. **A convention.** Nothing requires a new migration's statements to be individually re-runnable,
   and no lint checks it. By the wallpaper principle that makes it a wish, not a rule.

## Options, none costed

- **Guard everything.** Extend the `information_schema` guard pattern to every DDL statement in
  every migration, and add a lint that fails on an unguarded `ADD COLUMN` / `ADD INDEX` /
  `ADD CONSTRAINT`. Mechanical, wide, and it only buys retry-ability.
- **Name the cleanup in the throw.** Cheapest real improvement: every `ADD COLUMN` / `ADD INDEX` /
  `ADD CONSTRAINT` / `CREATE TRIGGER` failure message states the concrete statement to run before
  retrying. Turns a production guess into a paste.
- **A schema-vs-migrations reconciler.** A command that diffs the live schema against a
  migrate-from-zero build (`bin/quality-clean-db` already builds one) and reports drift. The only
  option that gives *detection* rather than *recovery*.

## Where this bites hardest — and the rollback leg is the one we run

`bin/quality-clean-db` migrates from zero against a throwaway database and then exercises
**rollback and re-up** — so the `down()` leg above is on the release path, not a hypothetical. What
it never exercises is an abort against REAL data: the throwaway database is empty, so the
data-dependent aborts (a stray-row check, a constraint that existing rows violate) cannot fire there.
That is the same blind spot ADR 0053 records for the local enforcement floor.
Production is shared hosting on MySQL 5.7 where the `CHECK` clauses several migrations rely on were
parsed and discarded (`docs/finance/check-constraints-on-mysql-5-7.md`), so an abort there can arrive
from a direction local runs cannot reproduce.

## Not doing now, and why

The abort paths on the migration that raised this are defensive and expected never to fire — the
stray-row check documents itself as expecting 0 rows. Fixing the standing condition means touching
every migration in the tree or building a reconciler, and neither belongs in a supplementary-invoicing
commit.
