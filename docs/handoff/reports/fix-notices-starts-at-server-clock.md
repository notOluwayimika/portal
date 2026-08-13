# Implementation report — `fix/notices-starts-at-server-clock`

**Base:** `staging` @ `4928064`. **Branch:** `fix/notices-starts-at-server-clock`.
**Shape:** one migration, one test file, one controller method, one new ticket. One commit.

> **⚠️ READ THE ADDENDUM AT THE END FIRST.** A second pass on 2026-08-13, in a session that did not
> write this, **widened the migration from one column to three** after production was read and
> disagreed with the local copy, renamed it, and changed one assertion in
> `tests/Feature/Finance/CaptureColumnsTest.php`. Two claims in the body below are corrected in place
> and marked; the "Shape" line above describes the first pass only.

---

## Headline

Done, with two deviations, one of which is an incident in the working directory rather than a choice
I made. `notices.starts_at` no longer carries `ON UPDATE CURRENT_TIMESTAMP` on `portaa10_portal` —
the schema-wide count of columns carrying that attribute went **1 → 0** — and the derived half of the
finding is now observed: with the clause imposed and the pre-fix `end()` in place, `starts_at` moved
**19,323,776 s**.

**This is full-review tier — subagent review attached, recommend a cold session before merge.** It is
a migration against a production copy, plus a test that runs DDL inside the suite.

## Read this first: the working directory changed branch under me

**A concurrent session switched this checkout to `fix/sql-clock-lint-v2` after I branched and before
I made my first edit, and committed to it.** The reflog is unambiguous:

```text
8285f13 HEAD@{0}: commit: fix(notices): starts_at stops following the server clock   <- MINE, landed on the WRONG branch
497530d HEAD@{1}: commit: docs(quality): the completeness claim becomes a schema-wide reading, …   <- not mine
d42812f HEAD@{2}: checkout: moving from fix/notices-starts-at-server-clock to fix/sql-clock-lint-v2  <- not mine
4928064 HEAD@{3}: checkout: moving from fix/sql-clock-lint-v2 to fix/notices-starts-at-server-clock  <- mine
```

**What this means for everything below, and it is not cosmetic.** All of my reading, my first gate
run and my first commit happened against `fix/sql-clock-lint-v2`, not `staging`. Three files I had
been treating as context **do not exist on this base**:

```text
docs/handoff/tickets/notice-end-destroys-starts-at.md          ABSENT from staging
docs/handoff/tickets/server-settings-the-code-cannot-see.md    ABSENT from staging
bin/ci-sql-clock-lint.php                                      ABSENT from staging
tests/Arch/SqlClockLintCoverageTest.php                        ABSENT from staging
docs/handoff/tickets/stored-epoch-offset.md                    ON STAGING
```

**What I did about it.** Moved `fix/sql-clock-lint-v2` back to `497530d` with `git branch -f` — a
pointer move, not a reset, so nothing of that session's was touched and my commit stays reachable by
SHA — then re-created the work on `fix/notices-starts-at-server-clock` at `4928064` and re-ran
everything against it: Pint, the arm, the watched red, and `bin/quality`. **Every number in this
report is from the re-run on the correct base.** The single quoted output that is not is the failing
sql-clock lint under "Deviations", labelled as such.

**What a reviewer should check rather than take from me.** That `fix/sql-clock-lint-v2` is at
`497530d` and carries none of this change; that `git diff origin/staging...HEAD` is five files and
not twenty-five; and that the concurrent session has not lost anything. **A shared working directory
with two agents in it is the actual finding here**, and it is not fixed by this branch.

## Deviations from the brief

### 1. The migration's `ALTER` is not the one the brief prescribed

The brief prescribed declaring a `DEFAULT` explicitly to suppress the automatic `ON UPDATE`, and
warned that a bare `MODIFY` may re-add both. **Both halves of that are correct and I measured them.**
I shipped a third formulation instead: **set `explicit_defaults_for_timestamp` for the session
issuing the DDL, then a bare `MODIFY`.** Measured on 8.0.43, scratch tables created under
`explicit_defaults_for_timestamp = OFF`, dropped in a `finally`:

```text
MODIFY TIMESTAMP NOT NULL                          default='CURRENT_TIMESTAMP'   extra='DEFAULT_GENERATED on update CURRENT_TIMESTAMP'   <- UNCHANGED (the trap, confirmed)
MODIFY TIMESTAMP NOT NULL DEFAULT <server clock>   default='CURRENT_TIMESTAMP'   extra='DEFAULT_GENERATED'                               <- the brief's fix: clause gone, default acquired
MODIFY TIMESTAMP NOT NULL DEFAULT '1970-01-02 …'   default='1970-01-02 00:00:01' extra=''                                                <- clause gone, sentinel acquired
SET SESSION explicit_defaults_for_timestamp = ON, then bare MODIFY
                                                   default=NULL                  extra=''                                                <- clause gone, nothing acquired
```

**Why, and the first reason is one I only saw because I was on the wrong branch.** The brief's
formulation leaves the column carrying a **server-clock default** that no writer asked for. On
`fix/sql-clock-lint-v2` there is a lint that refuses exactly that, and it refused mine:

```text
[12/15] sql-clock lint (no MySQL clock functions in raw SQL — two frames, one table)
   ✗ sql-clock-lint
       sql-clock-lint: 1 SQL-side clock read(s) / frame conversion(s) / server-clock column default(s):
         ✗ database/migrations/2026_08_13_100000_notices_starts_at_drops_implicit_on_update.php:56  [CURRENT_TIMESTAMP — clock-read]
```

**That lint is NOT on this base**, so on `staging` the brief's formulation would have passed 14/14
and nobody would have looked. I am keeping the change anyway, on its own merits and not on the gate's
authority: a default MySQL evaluates lands in the session zone, a different frame from every
timestamp the application writes (`docs/handoff/tickets/stored-epoch-offset.md`), so suppressing one
clock hazard by introducing a weaker one is not a fix. And the shipped formulation leaves the column
with **no default at all** — byte-identical to what a fresh `migrate` produces on a host where the
setting is ON, so an altered database and a migrated-from-zero one converge on one shape instead of
two, which is what `bin/quality-clean-db`'s migrate-from-zero leg compares across.

**The rule I am asserting, stated as a rule so it can be checked:** *declaring any explicit `DEFAULT`
suppresses the automatic `ON UPDATE`, and so does setting `explicit_defaults_for_timestamp` for the
session that issues the DDL; a bare re-declaration suppresses neither.* Measured, not read from
documentation. **The one thing I have not verified** is whether the production host's migration user
holds `SESSION_VARIABLES_ADMIN`. The migration treats a refusal as non-fatal and lets its shape check
produce a named diagnosis, with the constant-`DEFAULT` `ALTER` given as the manual remedy.

### 2. The ticket edits the work implied could not be carried, and were dropped

I corrected two claims in `notice-end-destroys-starts-at.md` and two in
`server-settings-the-code-cannot-see.md` while on the wrong branch. **Neither file exists on this
base**, so those edits are not in this commit. Their content is preserved in "Contradictions of the
premise" below and named in "Not done". The brief anticipated exactly this — *"Cross-reference
`server-settings-the-code-cannot-see.md` if that file exists on staging by now; if it does not, say
so rather than creating it here."* **It does not exist on staging.** I have not created it, and the
new ticket names both files without linking them, because a link that does not resolve is worse than
a name that does not pretend to.

## Contradictions of the premise

**The brief's facts all held.** `information_schema` on `portaa10_portal` read exactly
`COLUMN_DEFAULT = CURRENT_TIMESTAMP`, `EXTRA = DEFAULT_GENERATED on update CURRENT_TIMESTAMP`;
~~the schema-wide query returned exactly **1 row**; `finance_ledger_transactions.posted_at` was clean
(`default=NULL, extra=''`)~~; the three damaged-row deltas match to the second.

> **CORRECTED 2026-08-13 (second pass).** The struck clause is the COPY's answer reported as a
> completeness claim. It was the advisor's original "exactly one row", re-derived here against the
> same copy and inheriting the same limit. **Production carries the attribute on THREE columns**, read
> by the project lead on 2026-08-13: `notices.starts_at`, `notification_actions.expires_at` and
> `finance_ledger_transactions.posted_at`. The copy predates the latter two tables, which is why it
> read them clean. See the addendum at the end of this file.

### `NoticeController::update()` is exposed too, and the belt does not work in its obvious form

`update()` does send `starts_at` — but **sending is not writing**. Eloquent drops a clean attribute
from the `SET` list (`originalIsEquivalent()` normalises both sides of a date attribute through
`fromDateTime()`), so an edit that resubmits the same start time emits no `starts_at` at all.
Measured against this model, with the query log:

```text
$notice->update(['ends_at' => now(), 'starts_at' => $notice->starts_at])
  -> update `notices` set `ends_at` = ?, `notices`.`updated_at` = ? where `id` = ?      <- starts_at ABSENT
$notice->newQuery()->whereKey($id)->update([... 'starts_at' => $notice->starts_at ...])
  -> update `notices` set `ends_at` = ?, `starts_at` = ?, `notices`.`updated_at` = ?    <- present
```

Two consequences.

**`update()` is a second live trigger** for the same defect on any edit that leaves the start time
alone — the common case. I did **not** change it, per the brief; the migration closes it anyway.

**The belt the brief asked for is a no-op in its obvious form.** `$notice->update([... 'starts_at' =>
$notice->starts_at])` does not defend the column. I watched it fail (red 2) and wrote the belt
through the query builder instead, which is the only shape that puts the column in the statement.
`Notice` has no observer and its two concerns hook `creating` only, so no model event is lost; the
query still carries `SchoolScope`.

**What is now unexplained:** why notice#3 survived its edit. The row is consistent with an edit that
*did* change `starts_at`; the data cannot distinguish that from the alternatives, and I did not try
to make it. The honest statement is that the clause has never fired on any row that exists, and that
`end()` is not its only possible trigger.

### The new ticket overlaps one that exists on another branch

`server-settings-the-code-cannot-see.md` (on `fix/sql-clock-lint-v2`) already carries the class and
already names the same three latent declarations. I wrote the requested ticket anyway, **narrowed to
what that one does not establish**: how the assignment is decided, the remediation trap with its
measurement, and two corrections to that ticket's own table. It says so in its opening paragraph
rather than presenting itself as new ground.

## What changed

| File | Lines | What |
|---|---|---|
| `database/migrations/2026_08_13_100000_notices_starts_at_drops_implicit_on_update.php` | +130 | **The fix.** Session-scoped `explicit_defaults_for_timestamp = ON` + bare `MODIFY`, then an `information_schema` shape check on `EXTRA` and `IS_NULLABLE` that throws rather than record a meaningless green. `down()` a deliberate no-op. |
| `tests/Feature/Notices/NoticeEndPreservesStartsAtTest.php` | +178 | **The arm.** Imposes the clause, asserts it landed, back-dates a notice, calls the route, asserts `starts_at` is byte-identical. Plus a second test that the first left the table as it found it. |
| `app/Http/Controllers/NoticeController.php` | +36 −1 | **The belt.** `end()` writes `starts_at` through the query builder alongside `ends_at`. Docblock says explicitly that the migration is the fix and this is not, and why the obvious form fails. |
| `docs/handoff/tickets/implicit-timestamp-defaults-on-rebuild.md` | +146 | New ticket — the positional rule, the remediation trap, the three latent declarations and their corrected states. |
| `docs/handoff/reports/fix-notices-starts-at-server-clock.md` | this file | |

## Proof

### The premise, before anything was changed

```text
ENV: {"d":"portaa10_portal","g":1,"s":1,"v":"8.0.43"}
BEFORE: {"t":"timestamp","n":"NO","d":"CURRENT_TIMESTAMP","e":"DEFAULT_GENERATED on update CURRENT_TIMESTAMP"}
notice#1 starts_at-created_at=-130185 starts_at-updated_at=-130185
notice#2 starts_at-created_at=-171   starts_at-updated_at=-171
notice#3 starts_at-created_at=-911310 starts_at-updated_at=-912844
```

`starts_at` across all four local schemas, same query — this is what makes the environment split
concrete rather than argued:

```text
brookstone_portal_db  default='CURRENT_TIMESTAMP' extra='DEFAULT_GENERATED on update CURRENT_TIMESTAMP'
portaa10_portal       default='CURRENT_TIMESTAMP' extra='DEFAULT_GENERATED on update CURRENT_TIMESTAMP'
portal_drive          default=NULL                extra=''
portal_testing        default=NULL                extra=''
```

Schema-wide on `portaa10_portal` — the brief's "exactly one row" claim, re-derived. **This block is
left as it was run and is NOT production's answer; production has three. See the correction above and
the addendum at the end of this file.**

```text
rows: 1
notices    starts_at    DEFAULT_GENERATED on update CURRENT_TIMESTAMP
--- posted_at ---
[{"TABLE_NAME":"finance_ledger_transactions","COLUMN_NAME":"posted_at","COLUMN_DEFAULT":null,"EXTRA":"","IS_NULLABLE":"NO"}]
```

### The exact `ALTER`, and the reading after it

```sql
SET SESSION explicit_defaults_for_timestamp = ON;
ALTER TABLE `notices` MODIFY `starts_at` TIMESTAMP NOT NULL;
SET SESSION explicit_defaults_for_timestamp = <restored>;
```

Applied with `--path` (see "Not done" for why not a bare `migrate`), then rolled back and re-applied
after deviation 1, which also exercises the re-up leg:

```text
 INFO Rolling back migrations.
 2026_08_13_100000_notices_starts_at_drops_implicit_on_update .. 2.52ms DONE
 INFO Running migrations.
 2026_08_13_100000_notices_starts_at_drops_implicit_on_update . 202.06ms DONE
```

```text
DB=portaa10_portal
AFTER: {"t":"timestamp","n":"NO","d":null,"e":""}
notice#1 starts_at-created_at=-130185 starts_at-updated_at=-130185
notice#2 starts_at-created_at=-171   starts_at-updated_at=-171
notice#3 starts_at-created_at=-911310 starts_at-updated_at=-912844
schema-wide ON UPDATE rows: 0
```

**Expected:** clause gone, `NOT NULL` kept, no row's value moved. **Observed:** all three. The three
deltas are byte-for-byte the ones read before the migration ran.

### Driving the real route against the real copy

Not only the arm: `end()` was called on a live row of `portaa10_portal` inside a transaction that was
rolled back, so the post-migration column shape was exercised by the actual code path.

```text
notice#1  starts_at  delta across the call =         0 s   <- the hand-typed schedule did not move
notice#1  ends_at    delta across the call = -2,113,401 s   <- rewritten from a future date to the call's instant
notice#1  updated_at delta across the call = +3,376,434 s   <- followed the call, as it should
starts_at moved? NO
ends_at written? YES
rolled back; rows on the copy untouched
```

*(Amended 2026-08-13 — this block originally pasted the row's literal `starts_at`, `ends_at` and
`updated_at` values off `portaa10_portal`. The standing rule is counts, ids and deltas, never row
contents; the deltas carry the same evidence. See "Remediation" below, fix 5.)*

### The arm, on this base

```text
{"tool":"pest","result":"passed","tests":2,"passed":2,"assertions":11,"duration_ms":71447}
```

### `bin/quality`

Step count re-derived on **this** base before quoting it: `grep -c '^\s*step "' bin/quality` → **14**,
and the `[%d/14]` literal at `bin/quality:59` agrees. (`finance-context` says 14 too; the 15 I saw
earlier was `fix/sql-clock-lint-v2`'s.)

**PASS, 14/14, exit 0**, on `fix/notices-starts-at-server-clock` with the fix restored after both
reds:

```text
quality gate — base 4928064

[1/14] dependency integrity (composer.lock vs composer.json vs vendor/)      ✓ dependency-integrity-lint
[2/14] wayfinder:generate --with-form                                        ✓ wayfinder:generate
[3/14] lint changed files (Pint / Prettier / ESLint, check mode)             ✓ lint-changed
[4/14] types (tsc ratchet vs tsc-baseline)                                   ✓ tsc-ratchet
[5/14] frontend build (vite)                                                 ✓ build
[6/14] authorization guard (no new commented-out checks)                     ✓ authz-lint
[7/14] boundary lint (§17.2)                                                 ✓ boundary-lint
[8/14] grants-convergence lint                                               ✓ grants-convergence-lint
[9/14] money lint                                                            ✓ money-lint
[10/14] runtime-zero lint (S7 legacy access sources)                         ✓ runtime-zero-lint
[11/14] identifier-generation bypass guard (1.4b)                            ✓ identifier-generation-lint
[12/14] architecture tests (§17.1)                                           ✓ arch
[13/14] static analysis (Larastan level 5 vs baseline)                       ✓ larastan
[14/14] tests (failure ratchet vs tests/ratchet-baseline.txt)                ✓ test-ratchet

✓ quality: PASS — per-push floor. Promoting to main? run bin/quality-promote.
```

(The ✓ column is aligned for width; step names and the verdict are verbatim. Note there is **no
sql-clock step on this base** — that is the point made under "Deviations".)

> ~~**The suite ran unfiltered, and the arm's DDL did not poison it.** That was the specific risk of
> this change: the arm commits `ALTER TABLE` inside the suite, which commits `RefreshDatabase`'s
> transaction and removes the usual undo. A green ratchet over the whole suite is the check that its
> hand-rolled cleanup held for every test that ran after it.~~
>
> **RETRACTED 2026-08-13.** The second sentence is right and the third does not follow. When the DDL
> commits the transaction, `RefreshDatabase` finds no open transaction at teardown and resets
> `RefreshDatabaseState::$migrated`
> (`vendor/laravel/framework/src/Illuminate/Foundation/Testing/RefreshDatabase.php:158-159`), so
> Laravel runs a full `migrate:fresh` before the next RefreshDatabase test. A green suite is
> therefore evidence about `migrate:fresh`, not about this test's cleanup. See "Remediation" below,
> fix 1.

**What the unfiltered suite does establish** is narrower and still worth having: the arm's DDL does
not break the tests that follow it. What guards the cleanup itself is the expectation at the bottom
of the arm's own `finally`, which runs before the framework can intervene — bite-proved red below.

## The watched red

**Red 1 — the defect itself.** Mutation: `end()` reverted to the shipped pre-fix body,
`$notice->update(['ends_at' => now()]);`. Run on this base, on the final tree:

```text
Failed asserting that two strings are identical.
--- Expected
+++ Actual
@@ @@
-'2026-01-02 03:04:05'
+'2026-08-13 18:47:01'
```

**Observed delta: 19,323,776 s.** The value the arm planted was replaced by the database server's
clock at the moment of the UPDATE. The failure names `starts_at`, at the assertion that claims it,
not a downstream symptom. Restored; re-run green (above).

**Red 2 — the belt in the shape the brief asked for.** Mutation:
`$notice->update(['ends_at' => now(), 'starts_at' => $notice->starts_at]);`

```text
Failed asserting that two strings are identical.
--- Expected
+++ Actual
@@ @@
-'2026-01-02 03:04:05'
+'2026-08-13 18:05:34'
```

**Observed delta: 19,321,289 s.** Passing `starts_at` to `update()` does not protect the column,
because Eloquent removes it again. This is why the belt goes through the query builder — bite-proof
rather than argument. Restored. *(This red was run before the branch correction; the mutation, the
arm and the assertion are byte-identical on both bases, and only the wall-clock instant differs. If
that is not good enough for a reviewer, it reproduces in one command.)*

> ~~**The second test in the file is itself a guard I watched work.** The arm commits DDL, which
> commits everything around it, so `RefreshDatabase` cannot undo its fixtures. The follow-up test
> asserts the `notices` and `schools` tables are as they were and the column is clean. It passed on
> every run, **including both reds** — i.e. the hand-rolled cleanup ran even when the arm failed
> mid-way.~~
>
> **RETRACTED 2026-08-13, and the test is deleted.** It passed on every run because it *could not
> fail*, not because the cleanup ran. `RefreshDatabase` resets `RefreshDatabaseState::$migrated` when
> it finds no open transaction at teardown — exactly what the arm's DDL leaves — so Laravel runs a
> full `migrate:fresh` before it, and all three of its assertions read a brand-new schema. The cold
> review bite-proved it: with `restoreNoticesStartsAt()` deleted from the arm's `finally`, the file
> still reported `{"result":"passed","tests":2,"passed":2}`. **"It passed on every run, including
> both reds" was the strongest-sounding sentence in this report and it was evidence of nothing.**
> See "Remediation" below, fix 1, for the guard that replaces it and its own watched red.

## What the arm proves, and what it does not

**It proves the code path is safe when the attribute is present.** It imposes
`ON UPDATE CURRENT_TIMESTAMP` on the column, asserts the attribute actually landed (a non-vacuous
precondition — without it every assertion below it would be green for free on this host), exercises
`POST /api/notices/{uuid}/end`, and asserts the stored string is byte-identical.

**It does not prove the attribute is gone.** Nothing in a test file can: a fresh `migrate` on this
host produces a clean column, so the condition the arm needs does not exist unless the arm creates
it. **The migration is what removes the attribute**, and the evidence for that is the
`information_schema` reading above, not the suite. Two separate guarantees. A reader who takes either
as covering the other will eventually delete one of them.

## Database observations

Under the privacy rule — counts, ids and deltas only.

| | Before | After |
|---|---|---|
| Columns in `portaa10_portal` with `on update CURRENT_TIMESTAMP` | 1 | 0 |
| `notices.starts_at` | `NOT NULL`, `default=CURRENT_TIMESTAMP`, `extra='DEFAULT_GENERATED on update CURRENT_TIMESTAMP'` | `NOT NULL`, `default=NULL`, `extra=''` |
| `notices` rows | 3 | 3, all deltas unchanged |
| `notice#3` `starts_at − created_at` | −911,310 s | −911,310 s |

`explicit_defaults_for_timestamp` on this machine: **global 1, session 1**, MySQL **8.0.43**. That is
why a freshly-migrated `portal_testing` is clean and the copy was not.

**`brookstone_portal_db` still carries the dirty column** — a second local database, not the default
connection, and I did not migrate it. Named rather than silently left.

## Not done

- **The two ticket corrections are not in this commit**, because neither file exists on this base.
  They must be applied to `fix/sql-clock-lint-v2` (or after it merges). Both are content, not
  cosmetics:
  1. `notice-end-destroys-starts-at.md` states that `update()` is unaffected because it sends
     `starts_at`. **That is wrong** — see "Contradictions". Its DERIVED row also becomes OBSERVED,
     and its status becomes fixed-awaiting-merge.
  2. `server-settings-the-code-cannot-see.md` lists `curricula.registration_deadline` flatly as
     latent; the column is **dropped** by
     `2026_05_06_111742_update_terms_and_curricula_dates_table.php:42` and does not exist. Its
     `posted_at` row is right but for an unstated reason — the column is *positionally* first
     (ordinal 11, ahead of `created_at` at 13), which is what makes the attribute apply at all.
- **`php artisan migrate` was not run bare on `portaa10_portal`.** `--pretend` showed **one other
  pending migration** from staging (`2026_08_10_120000_finance_bank_account_foreign_keys`), which
  adds a `NOT NULL` column and two composite FKs to tables holding real rows. Applying somebody
  else's schema change to the ground-truth copy is not this task's to take, and if it failed mine
  would never have run. I applied mine with `--path`. **Consequence to check:** the copy now has a
  migration recorded out of order relative to that one. It is order-independent (different tables),
  but confirm rather than take my word.
- **`update()` is unchanged**, per the brief — even though I found it exposed.
- ~~**`notification_actions.expires_at` and `finance_ledger_transactions.posted_at` are not fixed.**
  Latent, clean on every database this project can observe, and a migration that "fixes" a clean
  column would give it a shape it does not have today.~~ **SUPERSEDED 2026-08-13 (second pass): both
  ARE dirty on production and both are now fixed by the same migration.** "Clean on every database
  this project can observe" was true and was the wrong set — the copy predates both tables, so it
  could not have known. See the addendum at the end of this file.
- **The production host's `SESSION_VARIABLES_ADMIN` grant is unverified**, so whether the migration's
  session-set will be permitted there is unknown. It fails loudly and diagnosably if not.
- **No browser drive.** The route was exercised through the HTTP test stack and through a real
  controller call against the copy; the notices admin screen itself was not opened.

## Findings raised, not fixed

- **Two agents share one working directory, and one silently changed the branch under the other.**
  This cost a wrong-branch commit, a gate run against the wrong `bin/quality`, and four doc edits
  that had to be dropped. Nothing in the repository prevents a recurrence, and the failure is silent
  — `git status` was clean throughout. **ticket**
- `app/Http/Controllers/NoticeController.php:150-178` — `update()` drops `starts_at` from the `SET`
  list whenever the resubmitted value equals the stored one, so it is a second trigger for this
  defect on any schema still carrying the clause. Closed by the migration on every observable
  database; the code stays dependent on the schema. **ticket**

---

# Remediation — 2026-08-13

Four findings from the cold review, plus an addendum that turned one of my predictions into a
measurement. All applied and amended into the branch's single commit. **Everything above this line is
the original report, with two claims struck through in place rather than deleted** — a report that
silently loses its wrong claims teaches nothing.

**The collision is closed.** The project lead verified independently that `8285f13` sits on no remote
branch, that `origin/fix/sql-clock-lint-v2` and the local branch both point at `497530d`, and that
the lint PR is uncontaminated. Nothing further was done to it.

## Fix 1 — the second test is deleted, not repaired

**The finding.** With `restoreNoticesStartsAt()` removed from the arm's `finally`, the file still
reported `2 passed`. `RefreshDatabase` resets `RefreshDatabaseState::$migrated` when it finds no open
transaction at teardown (`vendor/laravel/framework/src/Illuminate/Foundation/Testing/RefreshDatabase.php:158-159`)
— which is exactly the state the arm's DDL leaves — so Laravel runs a full `migrate:fresh` before the
next RefreshDatabase test and all three of test 2's assertions read a brand-new schema.

**What I did.** Deleted it. A test that cannot fail is a claim of coverage, and that is worse than an
absence. The two report passages that presented it as watched evidence are struck through above, each
with the bite-proof beside it. The file's docblock now records *why* a following test cannot guard
this cleanup, so the next reader does not re-add one.

**The guard that survives, and it bites.** The expectation at the bottom of the arm's own `finally`
— inside the same test, before the framework can intervene. Bite-proved by deleting the restoring
call:

```text
Expecting 'default_generated on update c…estamp' not to contain 'on update'.
  at tests/Feature/Notices/NoticeEndPreservesStartsAtTest.php:178
```

Restored; the file is green again. **The file is now one test, not two**, so the arm's own numbers
move: `tests: 1, passed: 1, assertions: 8`, where the original report recorded `tests: 2, passed: 2,
assertions: 11`. A reader comparing the two should see the difference and know why.

## Fix 2 — the other database, and the scope the claim inherited

`brookstone_portal_db` — the database CLAUDE.md names for driving flows — still carried the clause,
so `update()` was still destroying hand-typed start times there. Migration applied to it. Both
readings, after:

```text
portaa10_portal        {"ct":"timestamp","n":"NO","d":"1970-01-02 00:00:01","e":""}
                       schema-wide ON UPDATE columns = 0
brookstone_portal_db   {"ct":"timestamp","n":"NO","d":"1970-01-02 00:00:01","e":""}
                       schema-wide ON UPDATE columns = 0
```

And across **every** local schema, any table, which is the query the original completeness claim
should have run:

```text
rows: 0
```

`portaa10_portal`'s three notices rows, unchanged by the re-application:

```text
notice#1 starts_at-created_at=-130185 starts_at-updated_at=-130185
notice#2 starts_at-created_at=-171    starts_at-updated_at=-171
notice#3 starts_at-created_at=-911310 starts_at-updated_at=-912844
```

**The ticket's status line is now scoped.** It said "nothing in the schema carries the attribute
today", which reads as general and was only ever true of one schema — the query names a single
`TABLE_SCHEMA` and the completeness reading inherited that limit without saying so. It now names the
four databases read, with the date, and states plainly that nothing has been read on production.

## Fix 3 — the migration needs nothing but `ALTER`

**The hypothesis was measured and it is dead.** `ALTER COLUMN … DROP DEFAULT` does not leave a clean
column; it re-adds both attributes. Three readings, harness-forced OFF (`SET SESSION
explicit_defaults_for_timestamp = OFF` **in the probe, never in the migration** — this host is ON, so
without forcing, every formulation looks clean and the measurement would be worthless):

```text
session edft = 0

READING 1 (before, dirty):  type=timestamp nullable=NO default='CURRENT_TIMESTAMP'   extra='DEFAULT_GENERATED on update CURRENT_TIMESTAMP'
READING 2 (between):        type=timestamp nullable=NO default='1970-01-02 00:00:01' extra=''
READING 3 (after DROP):     type=timestamp nullable=NO default=NULL                  extra='DEFAULT_GENERATED on update CURRENT_TIMESTAMP'

restored session edft = 1
```

**Reading 3 decides it.** Dropping the default leaves the column declaring neither attribute again,
and an OFF server immediately re-applies both. **On an OFF host there is no reachable state that has
neither the clause nor a default.** So the sentinel stays, per the instruction, and the migration is
one statement:

```sql
ALTER TABLE `notices` MODIFY `starts_at` TIMESTAMP NOT NULL DEFAULT '1970-01-02 00:00:01';
```

Confirmed on an ON host and against the **real** column, not only a scratch table — including
idempotence, since `bin/quality-clean-db`'s rollback/re-up leg re-runs `up()`:

```text
ON host, created:            nullable=NO default=NULL                  extra=''
ON host, constant DEFAULT:   nullable=NO default='1970-01-02 00:00:01' extra=''
ON host, re-applied (idem):  nullable=NO default='1970-01-02 00:00:01' extra=''
REAL notices, dirtied:       nullable=NO default='CURRENT_TIMESTAMP'   extra='DEFAULT_GENERATED on update CURRENT_TIMESTAMP'
REAL notices, after ALTER:   nullable=NO default='1970-01-02 00:00:01' extra=''
REAL notices, re-applied:    nullable=NO default='1970-01-02 00:00:01' extra=''
```

**Why the sentinel is harmless, and what it costs — stated rather than assumed.** A default only
fires for an INSERT that omits the column, and none does: `NoticeController::store()` validates
`starts_at` as `required|date` and always assigns it, and the only other writer is a test fixture
that supplies it. The column stays `NOT NULL`. What changes is the failure mode of a *future* writer
that forgets the column: on a clean column that is a loud `1364 Field 'starts_at' doesn't have a
default value`; here it becomes a silent row dated 1970. That is a real regression in one narrow
case, and it is preferable to what it replaces — a row silently dated *now*, which looks like a real
schedule. A 1970 date is wrong on sight. `1970-01-02` rather than `1970-01-01` because `TIMESTAMP`'s
lower bound is `1970-01-01 00:00:01` UTC and a default is interpreted in the session zone; a day of
margin keeps it legal in every zone.

**The privilege question is now a measurement, not a prediction.** My original text said production
"will not hold" `SESSION_VARIABLES_ADMIN`, which was an inference about shared hosting. The project
lead read the grants, 2026-08-13:

```text
GRANT USAGE ON *.* TO 'portaa10'@'localhost'
GRANT ALL PRIVILEGES ON `portaa10_portal`.* TO 'portaa10'@'localhost'
(plus ALL PRIVILEGES on ~17 dated backup schemas)
```

`USAGE` is the empty global grant, and dynamic privileges can only be granted at `*.*` — so that user
holds none of them. The session-setting draft would have been **refused** on production, not merely
at risk of refusal, and `php artisan migrate` would have exited non-zero mid-release with this
migration unrecorded. Schema-level `ALL PRIVILEGES` includes `ALTER`, which is what makes the shipped
formulation available. **This reading is now in the migration's docblock and in the ticket**, because
the next person reaching for `SET SESSION` in a migration should meet the grant list rather than an
inference.

One consequence worth naming: the shape check is now stricter, not looser. `up()` verifies `EXTRA`,
`IS_NULLABLE` **and** `COLUMN_DEFAULT`, because with this formulation the default is not incidental —
it is the mechanism, and a column that lost it could silently regain the clause.

## Fix 4 — the harness helper, folded in

`restoreNoticesStartsAt()` is now the migration's `ALTER`, byte-identical, and sets no session
variable at all — so the failure mode the review named (a refused `SET SESSION` inside the `try`
skipping the restoring `ALTER`, then throwing again from the `finally` and masking the original
error) cannot occur rather than being handled. The docblock says why, and the parity claim it makes
is now true.

## Fix 5 — the report no longer pastes row contents

The live-drive block gave the literal `starts_at`, `ends_at` and `updated_at` of a row on the
production copy. Replaced in place with the deltas, which carry the same evidence — `starts_at`
delta 0, `ends_at` and `updated_at` both moved to the call's instant — and none of the data. Marked
as amended rather than silently swapped.

## Gate, and the watched reds

Both reds re-run as the last action before committing, on the remediated tree.

**Red 1 — the defect.** `end()` reverted to `$notice->update(['ends_at' => now()]);`

```text
Failed asserting that two strings are identical.
--- Expected
+++ Actual
@@ @@
-'2026-01-02 03:04:05'
+'2026-08-13 19:58:08'
```

Delta **19,328,043 s**, at `…Test.php:148`, the assertion that claims `starts_at`.

**Red 2 — the belt in its obvious form.** `$notice->update(['ends_at' => now(), 'starts_at' => $notice->starts_at]);`

```text
Failed asserting that two strings are identical.
--- Expected
+++ Actual
@@ @@
-'2026-01-02 03:04:05'
+'2026-08-13 19:58:41'
```

Delta **19,328,076 s**. Eloquent still removes the clean attribute; the query-builder write is what
defends the column.

**Red 3 — the cleanup guard**, which is new and is what replaces the deleted test: quoted under fix 1
above.

Fix restored after each; the arm green: `{"result":"passed","tests":1,"passed":1,"assertions":8}`.

**`bin/quality`: PASS, 14/14, exit 0**, raw and unedited — the whole run, not an aligned rendering
of it:

```text
quality gate — base 4928064

[1/14] dependency integrity (composer.lock vs composer.json vs vendor/)
   ✓ dependency-integrity-lint
[2/14] wayfinder:generate --with-form (must match vite.config.ts formVariants)
   ✓ wayfinder:generate
[3/14] lint changed files (Pint / Prettier / ESLint, check mode)
   ✓ lint-changed
       Pint (check) on 3 changed PHP file(s)
       Prettier: no changed frontend files
       ESLint: no changed JS/TS files
[4/14] types (tsc ratchet vs tsc-baseline)
   ✓ tsc-ratchet
[5/14] frontend build (vite — catches what the tsc ratchet structurally cannot)
   ✓ build
[6/14] authorization guard (no new commented-out checks)
   ✓ authz-lint
[7/14] boundary lint (§17.2)
   ✓ boundary-lint
[8/14] grants-convergence lint (a pre-existing permission added to grantsMap() ships a migration)
   ✓ grants-convergence-lint
[9/14] money lint (UI: money via formatNaira, no JS money math)
   ✓ money-lint
[10/14] runtime-zero lint (S7 legacy access sources)
   ✓ runtime-zero-lint
[11/14] identifier-generation bypass guard (1.4b)
   ✓ identifier-generation-lint
[12/14] architecture tests (§17.1)
   ✓ arch
[13/14] static analysis (Larastan level 5 vs baseline)
   ✓ larastan
[14/14] tests (failure ratchet vs tests/ratchet-baseline.txt)
   ✓ test-ratchet

✓ quality: PASS — per-push floor. Promoting to main? run bin/quality-promote.
```

**Step 3 is worth reading closely, and it exposes something the original report should have caught.**
The pre-remediation run on this base reported `Pint: no changed PHP files` — it linted **nothing**,
because `bin/lint-changed.sh` diffs against the committed range and `HEAD` was still `origin/staging`
at that moment. That is the known `lint-changed-cannot-see-uncommitted-work` ticket biting, and the
original report quoted that step as a pass without noticing it was a pass over an empty set. This run
reads `Pint (check) on 3 changed PHP file(s)` because `HEAD` is now the commit — so the gate has
linted the controller, the migration and the arm as committed. Pint was also run directly over the
working tree at every stage of this remediation (`{"tool":"pint","result":"passed"}`).

**And the gate was run once more AFTER the amend**, so that the 3 files step 3 lints are the
*remediated* versions rather than the pre-remediation ones — the run pasted above happened while the
remediation was still uncommitted, which is the same blind spot in a different position. Same
verdict, `exit 0`, `✓ quality: PASS`, with step 3 again reading `Pint (check) on 3 changed PHP
file(s)`. The only thing no gate run has covered is the paragraph you are reading, which is
unavoidable and true of every report.

## Not verified, still

- **Production itself.** No schema, no row and no gate on this branch has read it. The grant list
  above is the only production fact in this report and it came from the project lead, not from me.
- **`bin/quality-clean-db` / `bin/quality-promote`**, including the migrate-from-zero and
  rollback/re-up legs on a throwaway database. `up()`'s idempotence is measured above; the promote
  gate is not run.
- **The two ticket corrections** — `notice-end-destroys-starts-at.md`'s wrong `update()` claim and
  `server-settings-the-code-cannot-see.md`'s `registration_deadline` row — still belong to
  `fix/sql-clock-lint-v2`, where those files live. Unchanged by this remediation.
- **`update()` itself**, still deliberately unchanged.
- **The notices admin screen in a browser.**

---

# Addendum — 2026-08-13, second pass: the migration widens to three columns

**This section was written in a session that did not implement the original change.** It carries a
cold review of `5eeefa2` and then the widening the project lead directed after reading production.
Everything above this line is left as it stood.

## The completeness claim was the COPY's answer, presented as production's — corrected, attributed

**The advisor's "exactly one row" was a reading of `portaa10_portal`, the local copy.** It appears in
the original report under "Contradictions of the premise" ("the schema-wide query returned exactly
**1 row**") and in "Proof" ("rows: 1"), and it was inherited by the ticket. **It was never a
statement about production.** Production was read on **2026-08-13** by the project lead and carries
the attribute on **three** columns:

```text
finance_ledger_transactions.posted_at    default=CURRENT_TIMESTAMP  extra='on update CURRENT_TIMESTAMP'
notices.starts_at                        default=CURRENT_TIMESTAMP  extra='on update CURRENT_TIMESTAMP'
notification_actions.expires_at          default=CURRENT_TIMESTAMP  extra='on update CURRENT_TIMESTAMP'
```

**Why the copy disagreed, which is worth more than the correction.** A dump restored from production
carries production's MATERIALISED column shapes; tables created *afterwards* by running migrations
locally carry THIS host's, and this host has `explicit_defaults_for_timestamp = ON`, so they come out
clean. `notification_actions` (created `2026_08_04_140000`) and `finance_ledger_transactions.posted_at`
(added `2026_08_09_120000`) both post-date the copy. That is the whole of the disagreement — and it
means **a copy is not a witness for any table younger than the copy**, silently, because both
schemas answer the same query without complaint. The original report's own line that the copy "read
exactly `COLUMN_DEFAULT = CURRENT_TIMESTAMP`" for `notices.starts_at` was true and told nobody that
the two columns it also checked were too young for the copy to know about.

Corrected in `docs/handoff/tickets/implicit-timestamp-defaults-on-rebuild.md` (new section at the
top, plus the three-declaration table, which now separates the copy's reading from production's).

## What changed in this pass

| File | What |
|---|---|
| `database/migrations/2026_08_13_100000_timestamp_columns_drop_implicit_on_update.php` | **Renamed from `…_notices_starts_at_drops_implicit_on_update.php` and widened to three columns.** Same formulation, same sentinel, three statements, a shape check after each. |
| `tests/Feature/Notices/NoticeEndPreservesStartsAtTest.php` | Docblock only — the migration's new name, why this arm stays scoped to `notices`, and that the restore matches the migration's `notices` statement specifically now that it issues three. |
| `app/Http/Controllers/NoticeController.php` | Docblock only — the migration's new name. |
| `docs/handoff/tickets/implicit-timestamp-defaults-on-rebuild.md` | The correction above; status moves from "latent" to "production carries it, migration written, not run"; #1's live consequence written out; the superseded "no fix is proposed" paragraph struck in place. |

**The rename means the old migration name is recorded on databases that already ran it.** Handled
rather than left: `portaa10_portal` was rolled back under the old name before the file was removed
(`down()` is a no-op, so this only withdrew the record), and the stale row was deleted from
`brookstone_portal_db`. `portal_testing` still holds one and does not need it — the suite's
`migrate:fresh` rebuilds that database from zero. **Production has never run either name**, so
nothing there is affected.

## Why each column, since the three are not alike

- **`notices.starts_at`** — the live defect, already established and armed.
- **`notification_actions.expires_at`** — live, and not cosmetic. `NotificationActionResolver::resolve()`
  claims a tapped action with one conditional UPDATE whose `WHERE` reads `expires_at > ?`
  (`app/Notifications/Services/NotificationActionResolver.php:60-68`), and the class docblock calls
  that statement the entire concurrency design. Neither it nor the writes at `:102-108` and
  `:127-143` sets `expires_at`, so on production every state change rewrote the expiry to the server
  clock.
- **`finance_ledger_transactions.posted_at`** — benign, fixed anyway. The `no_update` trigger makes
  `ON UPDATE` unreachable and both writers supply the column, so nothing is at risk today. The reason
  to remove it is that **the margin is a trigger rather than the schema** — safety supplied by
  something written for an unrelated reason, which an append-only exemption or a trigger-dropping
  restore removes without anyone connecting the two — and that nobody wrote the attribute, so nobody
  will remember it is there. Zero rows on production; the ALTER is instant.

### Asked and answered: does anything read `expires_at` AFTER the first claim?

**No — not today, and the search is the evidence rather than the reasoning.** Two readers exist in
the whole tree: the claim's own `WHERE` (`:63`), and `NotificationAction::isClaimable()`
(`app/Notifications/Models/NotificationAction.php:68-72`). **`isClaimable()` has no production
caller** — the only references are its own definition, two prose mentions in the resolver's docblock,
and `tests/Feature/Notifications/NotificationActionResolverTest.php`. `NotificationFeedResource`
exposes no action fields at all, and `NotificationActionController::store()`'s JSON returns
`id/status/outcome/resolved_by/resolved_at` and never the expiry. No sweeper, job or command touches
the table.

**So the guard holds and the record does not.** MySQL evaluates the `WHERE` against the pre-update
row, so the exactly-once decision is correct even on production; the same statement then overwrites
the value it decided on. Two things follow, and only the first is bounded:

1. **Today** the loss is unobserved, because nothing reads it back.
2. **The first post-claim reader will be wrong**, and the resolver's own docblock names it: the
   reconciliation pass for `RESOLVING`/`UNCONFIRMED` rows ("a process that dies mid-relay must leave
   a row that says who was acting, or the reconciliation pass has a claimed action with no
   claimant"). On production that pass would read, on every already-resolved row, an `expires_at`
   equal to the moment of the last write rather than the window that was offered. A row settled as
   `EXPIRED` additionally records the tap that *discovered* the expiry, not the deadline itself.

That is why this one is "live" and not "latent": the destruction is happening on every write now, and
what it destroys is only unread because the reader has not been built yet.

## Verified by shape, per table, before and after

**Three readings per column, on a HOSTILE host.** This machine runs `explicit_defaults_for_timestamp
= ON`, so the condition does not exist until it is imposed — and the dirty state is produced the way
production produced it, by a bare re-declaration under OFF letting the **server** assign the
attribute, rather than by pasting an explicit `ON UPDATE` clause. Throwaway schema built with
`CREATE TABLE … LIKE`, dropped afterwards:

```text
session edft = 0

--- notices.starts_at ---
  READING 1 (dirty, server-assigned):    nullable=NO default='CURRENT_TIMESTAMP'   extra='DEFAULT_GENERATED on update CURRENT_TIMESTAMP'
  READING 2 (after the migration ALTER): nullable=NO default='1970-01-02 00:00:01' extra=''
  READING 3 (re-applied, idempotence):   nullable=NO default='1970-01-02 00:00:01' extra=''
--- notification_actions.expires_at ---
  READING 1 (dirty, server-assigned):    nullable=NO default='CURRENT_TIMESTAMP'   extra='DEFAULT_GENERATED on update CURRENT_TIMESTAMP'
  READING 2 (after the migration ALTER): nullable=NO default='1970-01-02 00:00:01' extra=''
  READING 3 (re-applied, idempotence):   nullable=NO default='1970-01-02 00:00:01' extra=''
--- finance_ledger_transactions.posted_at ---
  READING 1 (dirty, server-assigned):    nullable=NO default='CURRENT_TIMESTAMP'   extra='DEFAULT_GENERATED on update CURRENT_TIMESTAMP'
  READING 2 (after the migration ALTER): nullable=NO default='1970-01-02 00:00:01' extra=''
  READING 3 (re-applied, idempotence):   nullable=NO default='1970-01-02 00:00:01' extra=''

schema-wide ON UPDATE columns in the probe schema, after: 0
probe schema dropped; session edft restored to 1
```

**And the real `up()`, not a re-typed ALTER.** The migration file itself was `require`d and its
`up()` called against a throwaway schema on a session with the setting OFF and all three columns
dirtied:

```text
connected to portal_migration_bite_0813, session edft = 0
BEFORE: all three  default='CURRENT_TIMESTAMP'  extra='DEFAULT_GENERATED on update CURRENT_TIMESTAMP'
up() RETURNED — no throw.
AFTER:  all three  default='1970-01-02 00:00:01' extra=''
schema-wide ON UPDATE columns in the probe schema, after: 0
```

### The schema-wide query, re-run afterwards — every local schema, any table

```text
EVERY local schema, any table, ON UPDATE columns: 0
```

(`portaa10_portal`, `brookstone_portal_db`, `portal_testing`, `portal_drive`. The per-schema reading
after the migration: all three columns `nullable=NO default='1970-01-02 00:00:01' extra=''` on
`portaa10_portal`; `notices.starts_at` the same on `brookstone_portal_db`, whose other two are not
present; all three on `portal_drive`; `portal_testing` rebuilt from zero by the suite.)

### The ALTER on a POPULATED table, which production cannot exercise

Production has zero rows in `finance_ledger_transactions`, so the populated case was exercised where
rows exist. Counts, a sum and a spread — never contents:

```text
BEFORE the re-applied ALTER: rows=15 sum=26797134120 distinct=1 spread=0s
AFTER  the re-applied ALTER: rows=15 sum=26797134120 distinct=1 spread=0s
```

## The watched reds

**Red 4 — the shape check on the FIRST column.** Mutation: the sentinel removed from the `ALTER`
(`MODIFY … TIMESTAMP NOT NULL`), which is the formulation that silently achieves nothing on an OFF
host. Real `up()`, hostile probe schema:

```text
up() THREW RuntimeException:
  notices.starts_at still carries ON UPDATE after the ALTER (EXTRA = DEFAULT_GENERATED on update
  CURRENT_TIMESTAMP). Refusing to record this migration as applied: the column would keep
  overwriting its own value on any UPDATE whose SET list omits it.
```

**Red 5 — the shape check on a LATER column**, because red 4 alone only proves the check runs once.
Same mutation, probe schema containing only `notification_actions` so the loop must reach the second
entry:

```text
up() THREW RuntimeException:
  notification_actions.expires_at still carries ON UPDATE after the ALTER (…)
```

Both restored; the green run above is the re-run.

## A crash found by driving it, not by reading it

**`Schema::hasTable()` alone was not enough, and the run died.** `brookstone_portal_db` HAS
`finance_ledger_transactions` and does NOT have `posted_at` — that column arrives in
`2026_08_09_120000_finance_capture_columns_s2_s3.php` and the database is behind. With a table-only
guard:

```text
2026_08_13_100000_timestamp_columns_drop_implicit_on_update .. 82.09ms FAIL
SQLSTATE[42S22]: Column not found: 1054 Unknown column 'posted_at' in 'finance_ledger_transactions'
```

The `notices` ALTER had already committed when it died: **half applied, and unrecorded** — the exact
failure this change exists to avoid. Guard is now `! hasTable() || ! hasColumn()`, and the migration
records why. On a correctly-ordered from-zero run neither guard fires; both are for the environment
that is mid-catch-up, which is where a release breaks.

## Incidental, and it argues for the sentinel

`CREATE TABLE … LIKE` of a **clean** `timestamp NOT NULL` column is REFUSED on a session with
`explicit_defaults_for_timestamp = OFF` — `1067 Invalid default value` — while the sentinel shape
copies without complaint. Measured on the way past. The shape this host produces naturally is one an
OFF host cannot reproduce; the shape the migration leaves is portable to both.

## The arm stays scoped to `notices`, deliberately

It imposes the attribute on `notices.starts_at` and exercises `POST /api/notices/{uuid}/end`. **That
is a behavioural proof for one route and it generalises to nothing.** The other two columns are fixed
**by schema and are unarmed**, and no arm was written for them by analogy:

- The proof that their attribute is gone is the migration's own `information_schema` check, which is
  the same instrument that proves it for `notices` — no test can prove it, because a fresh `migrate`
  on this host produces clean columns and the condition has to be manufactured.
- An arm for `finance_ledger_transactions.posted_at` would have to perform the UPDATE that the
  table's `no_update` trigger exists to refuse. There is no behaviour there to protect.
- An arm for `notification_actions.expires_at` would assert that a claim does not rewrite the expiry
  — true, and it would pass on this host whether or not the migration existed, because the column is
  clean here. Imposing the clause to make it non-vacuous would rebuild the same DDL-inside-the-suite
  machinery the notices arm carries, for a value that (per the search above) nothing currently reads.
  If the reconciliation pass is ever built, **that** is the moment for an arm, and it should assert
  what the pass reads rather than what the schema declares.

Writing two more arms by analogy would produce two tests that pass for reasons unrelated to the
thing they name. One armed path, honestly labelled, is the stronger artefact.

## Not verified in this pass

- **Production.** Not read by me, not altered by me. The three-column reading and the grant list both
  came from the project lead. **The migration ships; running it is the project lead's.**
- **`bin/quality-clean-db` / `bin/quality-promote`** — still not run. `up()`'s idempotence is measured
  on all three columns and on populated data; the promote gate is not.
- **A populated `notification_actions`.** Zero rows in every local schema, so the ALTER on that table
  has only been exercised empty.
- **The two ticket corrections** on `fix/sql-clock-lint-v2` — unchanged, still owed to that branch.
- **The notices admin screen in a browser** — unchanged.

## ⚠️ THE ONE THING TO PUSH BACK ON: a finance shape oracle asserts the opposite, and I changed it

**`bin/quality` went red on the first run of this pass, and it was right to.**

```text
[14/14] tests (failure ratchet vs tests/ratchet-baseline.txt)
   ✗ test-ratchet
       ratchet: 1 NEW test failure(s) not in the baseline (regression):
         ✗ tests/Feature/Finance/CaptureColumnsTest.php::it ships the five capture columns NOT NULL
           with no defaults, … with data set "('finance_ledger_transactions', 'posted_at', 'NO')"
```

`tests/Feature/Finance/CaptureColumnsTest.php:113-142` pins `finance_ledger_transactions.posted_at`
as `NOT NULL` **with no default**, and its own comment argues the case: *"A default would do the same
damage more quietly: the writer omits, the database fills in, nobody is told."* The sentinel is
exactly a default. **These two decisions collide, and the collision is real rather than a technicality.**

**Why the migration wins, and the argument is in the arm's own terms.** `posted_at` is the table's
first `TIMESTAMP` column, so on a host with `explicit_defaults_for_timestamp` OFF MySQL assigns it
`DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`, and there is no reachable state with
neither the clause nor a default (measured — `DROP DEFAULT` re-adds both). **"NOT NULL with no
default" is a shape production cannot have.** The assertion passed here only because every local
host is ON; it never described production, where the column has carried `DEFAULT CURRENT_TIMESTAMP`
since it was added. So the choice on production is *which* default, not *whether* — and
`CURRENT_TIMESTAMP` **is** the arm's own nightmare, precisely: a ledger row silently dated NOW,
indistinguishable from a correct one, on an append-only table where it can never be corrected.
`1970-01-02` is the same omission made visible.

**What I changed, stated as a changed assertion because that is what it is.** The data row gains a
fourth parameter pinning the exact expected default; the other six rows are untouched and still
assert `COLUMN_DEFAULT IS NULL`. **It is tighter than what it replaces, not looser** — "no default"
would have accepted `NULL` and nothing else, and this accepts one exact string, so a drift to
`CURRENT_TIMESTAMP` *or* back to `NULL` (from which the clause can return on an OFF host) both fail.
The reasoning is written into the test, not only here.

**Red 6 — the changed assertion bites.** Mutation: expected default moved one day.

```text
finance_ledger_transactions.posted_at does not carry the sentinel default this column is pinned to.
…
Failed asserting that two strings are identical.
-'1970-01-03 00:00:01'
+'1970-01-02 00:00:01'
```

Restored; green.

**A reviewer should still weigh this**, because it is the only place in this change where a
deliberate, argued decision by someone else was overruled rather than accommodated. If the project
lead disagrees, the fallback is to drop `finance_ledger_transactions.posted_at` from the migration's
list and leave the clause in place there — it is structurally unreachable behind the `no_update`
trigger, which is the reason it was called benign in the first place. That would leave the schema's
safety resting on a trigger, which is what widening the migration was meant to stop.

**An open question this raised and did not answer, for the project lead.** The other six rows in that
oracle assert `COLUMN_DEFAULT IS NULL` for columns on the same OFF host. Non-first `TIMESTAMP NOT
NULL` columns behave differently from first ones, so those rows may or may not describe production
either — **nothing in this branch has read them there**. One `information_schema` query on production
against those six answers it.

## `bin/quality` on the final tree

**PASS, 14/14, exit 0.** Raw, and the run after the amend so step 3 lints the committed versions:

```text
quality gate — base 4928064

[1/14] dependency integrity (composer.lock vs composer.json vs vendor/)      ✓ dependency-integrity-lint
[2/14] wayfinder:generate --with-form                                        ✓ wayfinder:generate
[3/14] lint changed files (Pint / Prettier / ESLint, check mode)             ✓ lint-changed
           Pint (check) on 4 changed PHP file(s)
           Prettier: no changed frontend files
           ESLint: no changed JS/TS files
[4/14] types (tsc ratchet vs tsc-baseline)                                   ✓ tsc-ratchet
[5/14] frontend build (vite)                                                 ✓ build
[6/14] authorization guard (no new commented-out checks)                     ✓ authz-lint
[7/14] boundary lint (§17.2)                                                 ✓ boundary-lint
[8/14] grants-convergence lint                                               ✓ grants-convergence-lint
[9/14] money lint                                                            ✓ money-lint
[10/14] runtime-zero lint (S7 legacy access sources)                         ✓ runtime-zero-lint
[11/14] identifier-generation bypass guard (1.4b)                            ✓ identifier-generation-lint
[12/14] architecture tests (§17.1)                                           ✓ arch
[13/14] static analysis (Larastan level 5 vs baseline)                       ✓ larastan
[14/14] tests (failure ratchet vs tests/ratchet-baseline.txt)                ✓ test-ratchet

✓ quality: PASS — per-push floor. Promoting to main? run bin/quality-promote.
```

The four PHP files are the controller, the migration, the notices arm and the capture-columns
oracle. Targeted runs alongside it: the notices arm `{"tests":1,"passed":1,"assertions":8}`, the
whole `tests/Feature/Notifications` directory plus `BankAccountForeignKeysTest`
`{"tests":107,"passed":107,"assertions":266}`, and `CaptureColumnsTest`
`{"tests":17,"passed":17,"assertions":47}`.
