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

Together those give: **any abort part-way through a multi-statement `up()` leaves the schema partly
changed and the migration unrecorded.** The database is in a state that is neither "before" nor
"after", and nothing detects it, announces it, or names the way out.

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
   thing that is wrong.
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

## Where this bites hardest

`bin/quality-clean-db` migrates from zero against a throwaway database, so it never exercises an
abort against real data — the same blind spot ADR 0053 records for the local enforcement floor.
Production is shared hosting on MySQL 5.7 where the `CHECK` clauses several migrations rely on were
parsed and discarded (`docs/finance/check-constraints-on-mysql-5-7.md`), so an abort there can arrive
from a direction local runs cannot reproduce.

## Not doing now, and why

The abort paths on the migration that raised this are defensive and expected never to fire — the
stray-row check documents itself as expecting 0 rows. Fixing the standing condition means touching
every migration in the tree or building a reconciler, and neither belongs in a supplementary-invoicing
commit.
