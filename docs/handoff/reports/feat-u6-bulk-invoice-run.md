# `feat/u6-bulk-invoice-run` — a bulk invoice run, and a record that accounts for every billable student

U6 **commit 3 of four**. Branched off `origin/staging` at `00b2717` (the #260 merge).

Scope delivered: a queued bulk invoice run, the two tables that record it, the two enums whose
domains are held by triggers, the port method the reconciliation needed, and the tests. **No HTTP
route, no screen, no dispatch outside the test suite** — commit 4 owns all three.

---

## 1 · Correction to my own work, before anything else

Two things I asserted were false when written, and both were caught by planting rather than by
reading:

1. **`countBillableForSchool`'s docblock claimed its `where('student_curricula.school_id', …)` was
   the load-bearing isolation clause and that removing it "must turn the isolation proof red".**
   The plant came back **GREEN**. The clause is redundant with the `$schoolId` already passed into
   `billableEpisodes()`'s subquery: the subquery restricts `MAX(id)` to this School's rows, so the
   outer `whereIn(id, …)` can only ever select rows that were already in it. The docblock now
   records the three measurements (each clause alone → green, both removed → red) instead of the
   claim. This is the exact failure the sibling method's own docblock warns about — *"a comment
   describing an impossible failure is worse than no comment"* — and I reproduced it one method
   over.

2. **The row-outcome trigger test was vacuous.** It inserted against a run that had already billed
   that enrollment, so `unique(school_id, run_id, enrollment_id)` refused every insert with **1062**
   whatever the `outcome` value was. Planted against a trigger-less schema it stayed **GREEN** — a
   test proving the index, wearing the trigger's name. Rewritten to insert against a `pending` run
   with no rows, and it now goes red without the trigger. The comment in the test records why the
   fixture is shaped that way.

---

## 2 · `bin/quality`

**PASS — 15/15.** Step count re-derived this run (`grep -c '^\s*step "' bin/quality` = 15; the
`[%d/15]` literal in `step()` agrees).

```
[15/15] tests (failure ratchet vs tests/ratchet-baseline.txt)   ✓ test-ratchet
✓ quality: PASS — per-push floor.
```

One **real regression** was caught by the gate on the first run and fixed, not baselined:

```
ratchet: 1 NEW test failure(s) not in the baseline (regression):
  ✗ tests/Feature/Quality/PestNegatedExpectationMessagesTest.php::it no test passes a custom
    failure message to a negated Pest expectation
```

I had written `->and($run->fee_schedule_id)->not->toBeNull('the refused schedule is the useful fact
on a failed run')`. Pest discards a message on a negated expectation, so the sentence that carried
the whole point of the assertion would never have printed. Rewritten as a positive
`expect($x !== null)->toBeTrue($message)`, the form that repo-wide test exists to force.

Also run individually before the gate: `pint` (via an explicit file list, guarded against an empty
substitution — 10 files, 6 reformatted), `ci-boundary-lint`, `ci-authz-lint`, `ci-sql-clock-lint`.

---

## 3 · What was built

### 3.1 The port method — `countBillableForSchool(int $schoolId): int`

`App\Finance\Contracts\BillableEnrollmentProvider` + `App\Academics\BillableEnrollmentAdapter`.

The ticket (`docs/handoff/tickets/bulk-run-must-account-for-every-billable-student.md`) offered two
shapes and named this commit as the consumer. I took the **count**, not the enumeration: the stated
requirement is that the run can say *how many* billable students were neither billed nor flagged, and
a number says it. `listBillableNotIn(school, pairs)` would let a screen name them, which nothing asks
for — and the ticket's own "not prescribed" section says the choice is decided by what commit 3
renders. Commit 3 renders nothing; commit 4 does, and can ask for the enumeration then.

Two properties are load-bearing and both are argued in the docblock:

- **It reuses `billableEpisodes()`**, as the ticket requires, so the denominator is not a fourth
  definition of "billable".
- **It is deliberately WIDER than the two list methods.** They route through `currentEnrollments()`,
  which adds an `EXISTS` through `students`; this does not. The difference is exactly the
  **NULL-`student_id` episodes** — schema-legal, because the column is nullable and MySQL's default
  MATCH SIMPLE skips a composite FK check when a component is NULL — which fail that `EXISTS` and
  appear in **neither** list. A denominator inheriting the same blindness would balance to zero over
  a population that had already dropped them: the §26 silent-omission defect reproduced inside the
  figure written to detect it. Planted (§5, plant 3) and red.

One consequence stated plainly in the docblock rather than glossed: this counts billable
**episodes**, and `billableEpisodes()` groups by `student_id`, so SQL puts *all* NULLs in one group.
Any number of student-less episodes contribute **1** between them. The reconciliation therefore
detects that such episodes exist; it does not count how many.

### 3.2 The record — `finance_bulk_invoice_runs` / `_run_rows`

Migration `2026_08_18_110000_create_finance_bulk_invoice_run_tables.php`. **No suitable table
existed** — `grep -rn "bulk_invoice\|BulkInvoice" database app tests` returned nothing before this
commit.

**Copied from `finance_opening_balance_batches` / `_rows` (2026_08_06_100000):**

| Copied | Why it transfers |
| --- | --- |
| parent + child, child carrying `school_id` **and** a composite `(parent_id, school_id)` FK to the parent's `(id, school_id)` unique key, `ON DELETE CASCADE` | same shape of thing: a long-running School-scoped job with a per-unit-of-work child. A row's School can only ever be its run's School, at the engine |
| `uuid` route key on both, `school_id` `restrictOnDelete`, `timestamps()` | the finance table conventions `SchemaConventionsTest` enforces |
| the actor as a **LOOKUP** column, not an FK (`started_by_user_id` ↔ `uploaded_by_user_id`) | same reason: attribution, not referential integrity |
| **counts NULL until the run finishes** | verbatim from the batch's control totals — *"a batch that aborted mid-parse must not present a total that was never summed"* |
| the parent `(id, school_id)` unique key added by raw `ALTER`, named explicitly | Laravel's derived names hit MySQL's 64-char ceiling on this family |
| the job takes `(recordId, schoolId)`, the record is inserted in a "not started" state **before** dispatch, and the job is the only thing that moves it | `ProcessOpeningBalanceImport`. The record of what was asked for **is** the record of what happened — no second table tracking the queue |

**Deliberately NOT copied:**

| Not copied | Why not |
| --- | --- |
| the `findings` **JSON** column, on either table | the batch stages a *file*, where one row can fail several independent §2/§7 rules at once and a list is honest. A run's row has exactly one outcome and at most one reason; `outcome` + `reason` is the shape. A JSON column would put the run's only failure text where no index reaches |
| **any money column** | the batch carries `control_total` because §1's L2 witness is operator-typed and nothing derived it. A run derives everything from the schedule it pinned and the amounts live on the invoices. A `total_billed_minor` here would be a second, unreconciled copy of `SUM(finance_invoices.total_minor)` — a money figure with none of the money-writing discipline behind it. Commit 4 sums the invoices |
| an idempotency key like `unique(school_id, batch_reference)` | the batch has one because re-importing a file double-posts arrears. A bulk run is **deliberately re-runnable**: double-billing is stopped per *episode* by `unique(school_id, active_enrollment_key)` on `finance_invoices`, which survives any number of runs. Refusing the second run would refuse the recovery path |
| approval columns, and therefore the maker≠checker trigger pair | a run raises invoices; it approves nothing. No `submitted_by*` / `decided_by*` pair means these tables correctly stay out of `SchemaConventionsTest`'s derived approval-table set rather than being an exception to it |
| an immutability (`no_update` / `no_delete`) trigger | `finance_bulk_invoice_runs` is mutated by design (pending → running → completed/failed, then the counts). The rows are written once but are left mutable rather than guarded: these tables record what a job observed and hold no money, so they are not in the append-only set the 1.4c triggers defend. `SchemaConventionsTest`'s append-only proof keys on a hardcoded list of those tables, so this is a **deliberate absence, not one hidden by a loop that never looked** |

**What each table knows.** Run: School, term, class level, the fee-schedule version it pinned, who
started it, `started_at` / `finished_at`, status, `failure_reason`, and six counts. Row: one
enrollment (id + uuid + student id), its outcome, the invoice for `billed`/`already_billed`, and the
reason for `failed`.

`unaccounted_count` is a **signed** `INT` while the other five are `UNSIGNED`. The subtraction cannot
go negative while the cohort and the unplaceable list are subsets of the population — so a negative
would mean that stopped being true, and an unsigned column would wrap it into a colossal positive
and hide precisely the fact worth seeing.

`fee_schedule_id` is pinned **the moment a schedule is resolved**, including on the mapper-refusal
path. It records *which price list this run read*, not which one it succeeded with; on a failed run
that is the most useful fact there is. An earlier draft pinned it only on success and the column
docblock said so — both were corrected together so the schema comment and the job agree.

### 3.3 Triggers, not CHECKs

Production is MySQL 5.7.23, which parses and discards `CHECK`
(`docs/finance/check-constraints-on-mysql-5-7.md`), so the two enum domains are held by four
triggers following `2026_08_17_100000`'s pattern — `COLLATE utf8mb4_bin` (case variants would
otherwise satisfy the guard while every `where('status', …)` read missed them), `COALESCE(…, 0)`
(NULL makes `IN (…)` NULL, and `NOT NULL` is NULL, so a bare `IF NOT (…)` lets it through), no
apostrophe in any `MESSAGE_TEXT` (MySQL stores the body with the escape stripped —
`TriggerBodiesAreDumpSafeTest`), and an `information_schema.TRIGGERS` read-back after every `CREATE`
that throws rather than record a green that means nothing (ADR 0052).

### 3.4 The job — `App\Finance\Jobs\ProcessBulkInvoiceRun`

`ShouldQueue`, `public readonly int $schoolId`, `middleware(): [new SchoolAware]`, `tries = 1`,
`timeout = 3600`. Nothing dispatches it but the tests.

- **One schedule, one set of lines, mapped once.** `FeeScheduleLookup::activeFor` resolves the
  version, `FeeScheduleLineMapper::linesFor($schedule, $this->schoolId)` maps it once, every invoice
  in the run comes from that array. The School is an **argument**, and under `SchoolAware` the
  ambient context is the same School — so the mapper's second guard is satisfied by **equality**,
  not by luck.
- **A mapper refusal is per-run and reported once.** The resolve-and-map happens before the first row
  is written: on refusal the run is `failed` with that reason, **zero** rows exist, nothing was
  billed. All five of the mapper's refusals are facts about the price list; discovered inside the
  loop they would print once per child and bury the only actionable sentence.
- **A per-student failure never aborts the run.** Every per-student call is caught, the reason goes
  on that student's row, the loop continues.
- **A re-run is `already_billed`, not an error** — detected by a pre-check through
  `InvoiceReadModel::activeScheduledInvoiceIdForEnrollment()` and, because a pre-check cannot hold
  under concurrency, by re-asking the **same predicate** after a refusal. Never by reading the
  exception's message: matching on message text would let a copy edit in `GenerateInvoice` silently
  reclassify already-billed students as failures.
- **The unplaceable list and the reconciliation are taken at run time**, not at read time.
- **The four outcome counts are re-read from the rows actually persisted**, not from an in-memory
  tally — so a row whose insert failed shows up as a discrepancy in `unaccounted_count` rather than
  as work that happened.

### 3.5 One change outside the three deliverables

`InvoiceReadModel::hasActiveScheduledInvoiceForEnrollment()` now **delegates** to a new
`activeScheduledInvoiceIdForEnrollment()`, which is the same predicate returning the id. The run
records `already_billed` **naming** the invoice that blocked it, so it needed an id; the two ways to
get one were a fourth copy of that `where` chain or this. A copy is exactly how the modal preview and
`GenerateInvoice`'s pre-check came to disagree (that file's own docblock records the incident), so
the delegation keeps "same answer" a fact rather than a comment. Widening the predicate still moves
all three consumers.

---

## 4 · `information_schema.TRIGGERS` read-back

Read after `migrate`, from `portal_testing`, by the same query the migration uses to refuse to record
itself:

```
finance_bulk_invoice_run_rows_outcome_shape_bi  BEFORE INSERT  finance_bulk_invoice_run_rows
finance_bulk_invoice_run_rows_outcome_shape_bu  BEFORE UPDATE  finance_bulk_invoice_run_rows
finance_bulk_invoice_runs_status_shape_bi       BEFORE INSERT  finance_bulk_invoice_runs
finance_bulk_invoice_runs_status_shape_bu       BEFORE UPDATE  finance_bulk_invoice_runs
```

Four triggers, all `BEFORE`, both events on both tables, on the tables asked for. Longest identifier
is 46 characters, under MySQL's 64-char cap.

Keys and constraints, read back the same way:

```
finance_bulk_invoice_runs      finance_bulk_invoice_runs_id_school_unique
finance_bulk_invoice_runs      finance_bulk_invoice_runs_fee_schedule_school_foreign
finance_bulk_invoice_runs      finance_bulk_invoice_runs_term_id_foreign
finance_bulk_invoice_runs      finance_bulk_invoice_runs_class_level_id_foreign
finance_bulk_invoice_run_rows  finance_bulk_invoice_run_rows_run_school_foreign
finance_bulk_invoice_run_rows  finance_bulk_invoice_run_rows_enrollment_school_foreign
finance_bulk_invoice_run_rows  finance_bulk_invoice_run_rows_invoice_school_foreign
finance_bulk_invoice_run_rows  finance_bulk_invoice_run_rows_school_run_enrollment_unique
```

### Migration verified by shape (ADR 0052), four paths

The rollback depth was **re-derived**, not assumed: `migrate:status` showed this migration alone in
**batch 2**, so a bare `migrate:rollback` rolls back exactly it — no `--step=N` guess, which is the
mistake that let an audit pass while testing another stream's migration.

| Path | Result |
| --- | --- |
| `migrate` | `DONE`; four triggers + eight keys present as above |
| `migrate:rollback` (batch 2 = this migration only) | `finance_bulk%` tables: **0**, triggers: **0** |
| `migrate` again | `DONE`; everything back |
| **idempotent re-run**: `migrations` row deleted, `migrate` re-run against the live schema | `DONE` in 42.7 ms — no 1050, 1061, 1826 or 1359. Every `CREATE TABLE` is `Schema::hasTable`-guarded, both composite FKs and the unique key are `information_schema`-guarded, and every `CREATE TRIGGER` is preceded by `DROP TRIGGER IF EXISTS` |

That last path is what `docs/handoff/tickets/aborted-migration-leaves-schema-changed-and-unrecorded.md`
asks for, and it is worth being precise about what it buys: **re-runnability is not atomicity.** The
read-backs are exactly the statements that can abort, and an abort still leaves the schema changed
and the `migrations` table disagreeing with it. This migration makes the *retry* work; it does not
solve the standing condition, and does not claim to.

---

## 5 · The planted reds

Eleven plants. **Three came back green** and all three are reported, because a plant that stays green
is the finding.

| # | Plant | Result |
| --- | --- | --- |
| 1 | `countBillableForSchool` loses `where('student_curricula.school_id', $schoolId)` | **GREEN** — see §1. Redundant with the subquery narrowing |
| 1b | …loses only the `$schoolId` passed to `billableEpisodes()` | **GREEN** — redundant the other way |
| 1c | …loses **both** | **RED** ×2: `countBillableForSchool` `5 !== 3`; cross-School run `3 !== 1` |
| 2 | `countBillableForSchool` keeps the ambient `SchoolScope` | **RED**: under a disagreeing context the count reads `0`, not `3` — the empty-intersection mode, in the figure meant to detect omissions |
| 3 | the denominator built on `currentEnrollments()` (inheriting the `EXISTS` blindness) | **RED** ×2: `unaccounted_count` `1 !== 2` and `billable_count` `2 !== 3`. **This is the plant that proves the widened denominator, and the NULL-`student_id` shape the ticket names** |
| 4 | the per-student `catch` removed | **RED**: run status `Failed`, expected `Completed` — one student took the run down |
| 5 | `already_billed` classified as `Failed` | **RED** ×2: re-run `already_billed_count` `0 !== 2`; reconciliation `0 !== 1` |
| 6 | mapper refusal continued into the loop instead of returning | **RED**: run status `Completed`, expected `Failed` — the refusal became three per-student failures |
| 7 | `SchoolAware` removed from `middleware()` | **RED**: `billed_count` `0 !== 3`. Every test dispatches **outside** any `ActiveSchool::runFor`, so the middleware is under test rather than assumed |
| 9 | `currentEnrollments()` loses `where('student_curricula.school_id', …)` alone | **GREEN** — the `EXISTS` and the subquery still isolate. Consistent with commit 1, which is careful about this: its plant deleted **all three** School statements at once, and the adapter docblock says so in as many words — *"removing it (with the others) turned all three isolation tests red"* (`BillableEnrollmentAdapter.php:296`). Clause 1 alone is not falsifiable by any test in this repo |
| 9b | `currentEnrollments()` loses **all three** School statements | **RED**: cross-School run goes `Failed`. Worth naming — it fails because the composite `finance_bulk_invoice_run_rows_enrollment_school_foreign` refuses a School B episode under School A's `school_id` (1452), so the *engine* is what stopped it, one layer below the assertion |
| 10 | the four triggers `DROP`ped from `portal_testing` before the run | **GREEN, and the plant was invalid** — `RefreshDatabase` runs `migrate:fresh` once per process and recreated them. Re-done as 10b/10c below |
| 10b/10c | the migration installs no domain triggers | **RED** ×2: both raw writes go through unrefused. (10b exposed the vacuous outcome test of §1; 10c is the run after it was fixed) |
| 11 | the unplaceable list not recorded | **RED**: `unplaceable_count` `0 !== 1` |

Every plant was restored from a snapshot and the file re-verified (`php -l`), and the suite is green
at 15/15 after all of them.

### The six tests the brief asked for, and where each is

| Requirement | Test | Planted by |
| --- | --- | --- |
| a run over a cohort bills every student once | `a run over a cohort bills every student once` — asserts **per student**, since three invoices could also be two for one child | 7 |
| a re-run bills nobody twice and records already-billed | `a re-run bills nobody twice and records already-billed, not failed` — asserts the invoice id set is byte-identical before and after, and that the rows name the *first* run's invoices | 5 |
| one student failing does not stop the others, reason recorded | `one student failing does not stop the others, and the reason is recorded` | 4 |
| a mapper refusal fails the run once, not per student | `a mapper refusal fails the run once, not once per student` — three students in the cohort, **zero** rows, zero invoices, counts still NULL. Plus a sibling arm for "no active schedule at these coordinates" | 6 |
| the reconciliation adds up over a fixture with all five buckets non-empty | `the reconciliation adds up over a fixture where all five buckets are non-empty` | 3, 5, 11 |
| cross-School: a run for A touches nothing of B's | `a run for School A touches nothing of School B` | 1c, 9b |
| (added) the two enum domains bite at the engine | two `TRIGGER` tests | 10b/10c |
| (added) the port method is decided by its argument | `countBillableForSchool is decided by its argument, not by an ambient context` | 1c, 2 |

**The reconciliation fixture, and why it is not self-satisfying.** `unaccounted` is computed by
subtraction, so the identity alone would be a tautology. The test therefore asserts **each bucket's
own expected number first** — `billed 2`, `already 1`, `failed 1`, `unplaceable 1`, `unaccounted 2`,
`billable 7` — and *then* the identity. The two unaccounted students are of the two different shapes
the ticket names: one **placeable at a class level the run does not name**, and one
**NULL-`student_id` episode** inserted raw (the observer fatals on a null curriculum, and raw
SQL/imports are the production path for that shape — the observer's own docblock says so).

**The per-student failure is a real path, not a stub.** The provider is decorated so that
`findByUuid()` returns null for one enrollment — which is the genuine race between the
**argument-scoped** cohort read and the **ambient-scoped** `findByUuid` (the port's docblock splits
its methods on exactly that line), and `GenerateInvoice` then throws its own production sentence,
*"No billable enrollment found for the given reference."* The decorator delegates every other method
to a real `BillableEnrollmentAdapter` — which is `final`, hence a decorator and not a subclass — so
the cohort read, the unplaceable list and the population count under test are all the genuine ones.

---

## 6 · Two things a cold review should attack first

**6.1 The seventh escape hatch is invisible to the boundary lint, and I did not add a baseline entry
for it.** `countBillableForSchool` adds a seventh `withoutGlobalScope(SchoolScope::class)` to
`app/Academics/BillableEnrollmentAdapter.php` (`grep -c` = **7**, was 6). `boundary-lint` reported
**"OK — no new boundary violations (7 known temporary exceptions)"** and the baseline still carries
only **3** keys for that file. That is not the lint clearing the call: the baseline key is
`rule \t path \t trim($line)`, and my line is byte-identical to the already-baselined
`->withoutGlobalScope(SchoolScope::class)`. This is the documented hole
(`docs/handoff/tickets/boundary-lint-baseline-keys-on-line-text.md`), and the lint's own header says
the point of covering `app/Academics` is *"that the SEVENTH one has to be argued for"*. The seventh
slipped through the mechanism, so it is argued here instead: the method's School is an argument, a
second ambient opinion would empty the intersection, and a zero population makes every reconciliation
read "nothing unaccounted for" — the exact defect the figure exists to catch. **Ticket**, not
**fix**: the hole is pre-existing and closing it is a change to the lint, not to this commit.

**6.2 `unaccounted_count` detects student-less episodes but does not count them.** Because
`billableEpisodes()` groups by `student_id` and SQL puts all NULLs in one group, five such episodes
raise the figure by **1**, not 5. The ticket's acceptance criterion is that the count is displayed or
structurally zero, which this meets — but a commit-4 screen must not print "1 student unaccounted
for" as though it were a headcount. Named in the port docblock and worth a **ticket** if commit 4
wants the true number.

Two smaller ones, both **ticket**:

- The run rows carry **no immutability trigger** (§3.2). Nothing writes them twice today, and they
  hold no money, but the deliberate absence is recorded here rather than left to be discovered.
- `bin/quality`'s determinism residual (ADR 0053) applies as always: this branch's PASS is one run.
  The suite artefacts for the failing run and the passing run are both under
  `/var/folders/…/quality-runs/`.

---

## 7 · Explicitly not done

No HTTP route. No screen. No `dispatch()` outside `tests/`. No `withoutGlobalScope` inside
`app/Finance`. No `CHECK` constraint. No RBAC permission, no policy, no grant-map edit — the run has
no authorization surface until it has a route, and inventing one now would be a permission with no
gate behind it.
