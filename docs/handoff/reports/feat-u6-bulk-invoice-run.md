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

The rollback depth was **re-derived**, not assumed: on the database I ran it against —
`portal_testing`, **incrementally migrated**, where this migration was applied on its own —
`migrate:status` showed it alone in **batch 2**, so a bare `migrate:rollback` rolled back exactly it.

**That is a fact about that database, not about this migration**, and the first version of this
report stated it as though it were the latter. On a database migrated **from zero** — which is what
`bin/quality-clean-db` builds and what a fresh clone produces — every migration lands in batch 1, and
a bare `migrate:rollback` would revert **all of them**, not this one. Anyone repeating this audit
must re-derive the depth on the database in front of them, which is the whole point of the rule in
CLAUDE.md; naming a batch number here as if it travelled would be the same class of error as trusting
`--step=1`.

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

---

# Round 2 — cold review's findings, and the project lead's ruling

Second commit on this branch, on top of `87b3702`. One real defect, three claims measured false, a
naming ruling, one boundary slip, and three tickets. Everything below is what changed and what was
measured, not what was intended.

## R2.1 · FIX 1 — the defect: two `record()` call sites outside every `try`

Cold review measured it: a provider whose unplaceable list repeats one member produces **1062** on
the second insert, and the run dies in the **unplaceable loop — before the cohort loop runs at
all.** Two billable students, zero invoices, run `failed`, every count NULL.

The migration comment claimed that shape was *"refused with 1062 rather than silently
double-counted"*. **True of the index, false of the job.** Refusing a write and surviving the refusal
are two different properties and only one of them was built. Both the migration comment and the class
docblock's *"every per-student call is caught"* are corrected in place, in the files that carried
them.

**The fix.** `ProcessBulkInvoiceRun::attempt()` — every per-student unit of work, the invoice **and**
the row that records what happened to it, runs inside it. Both loops call it.

**The ruling, stated in the code rather than left to be inferred:** an unrecordable row is a
**per-student fault, not a run-level one**. The run bills everyone it still can. Same judgement
already made for a student whose *invoice* cannot be raised, and the same reasoning — thirty-nine of
forty is a partial result; thirty-one of forty because the ninth row hit a constraint is a defect.

**And it is not silent**, which is what makes the ruling defensible. A student whose row could not be
written is missing from the rows, so `billed + already + failed < cohort_count`, and that inequality
is the alarm (R2.5). No extra flag column: a flag the job sets is a flag the job can forget to set.

### The three planted reds — plant A: both `record()` sites back outside every `try`

```
tests 3  passed 0  failed 3

FIX1a  a duplicated unplaceable episode does not stop the cohort from being billed
  two billable students were in the cohort and neither was billed: a 1062 in the unplaceable
  loop killed the run before the cohort loop ran
  Failed asserting that 0 is identical to 2.

FIX1b  a duplicated cohort member does not stop the other students, and the alarm fires
  a 1062 in the already-billed branch left the rest of the cohort unbilled
  Failed asserting that 1 is identical to 3.

FIX1c  a cohort member whose row violates an unrelated constraint does not stop the others
  a 1452 on ONE row is a per-student fault; the run must still close and report its counts
  Failed asserting that two variables reference the same object.
  -App\Finance\Enums\BulkInvoiceRunStatus Enum (Completed, 'completed')
  +App\Finance\Enums\BulkInvoiceRunStatus Enum (Failed, 'failed')
```

Each test leads with **the harm**, so a red names what was lost rather than what a status field held.
FIX1c is deliberately **not** a duplicate: the phantom's row fails the composite enrollment FK
(**1452**), so the fix cannot have special-cased 1062. All three green after the fix
(`tests 3, passed 3, assertions 28`).

## R2.2 · FIX 2 — a killed worker no longer strands a run in `running`

`handle()` sets `running` before its `try`, and there was no `failed()`, no `$backoff`, `tries = 1`,
`timeout = 3600`. A worker timeout or fatal ran neither the `catch` nor the `finally`, so
`BulkInvoiceRunStatus::Running`'s own docblock named a state — *"the worker died mid-cohort"* — that
nothing wrote.

Added `failed(Throwable $e)`. Its docblock says which deaths it covers (an uncaught throw out of
`handle()`, `MaxAttemptsExceededException` after the timeout alarm, `TimeoutExceededException` — every
death where the process is still alive) and **which it does not**: *a SIGKILL, an OOM kill or the
machine going away still strand the row*, because nothing in this process runs afterwards. Closing
that needs a sweeper over `started_at`, and there isn't one. Claiming otherwise would be the same
overclaim this branch has already had to correct twice.

It **establishes its own School context** (`ActiveSchool::runFor($this->schoolId, …)`): job middleware
does not wrap `failed()`, and `BulkInvoiceRun` is not in `rbac.fail_closed_models`, so its
`SchoolScope` would read *unscoped* rather than refuse. And it **refuses to overwrite a terminal
state** — a late queue-level death must not rewrite the outcome of a run that finished.

**Plant C** — `failed()` deleted: both FIX2 tests red (`tests 2, passed 0`).

## R2.3 · FIX 3 — the shadowed `fail()`

The private `fail()` shadowed `InteractsWithQueue::fail()`, silently, with Larastan seeing nothing —
and FIX 2 adding `failed()` beside it is exactly when that becomes a trap. Renamed to `failRun()`.

Pinned by reflection, and the assertion needed a second attempt: `getDeclaringClass()` reports the
**using** class for a trait method, so it cannot tell an override from the trait. `getFileName()`
can, and that is what the test compares.

**Plant D** — renamed back to `fail()`:

```
FIX3  ProcessBulkInvoiceRun::fail() must be the trait method, not a private override
  Failed asserting that false is true.
```

## R2.4 · FIX 4 — three claims measured false, corrected where they were written

| Claim | Where | Correction |
| --- | --- | --- |
| `Failed` means *"No invoice was raised"* / *"all of them before any invoice is raised"* | `BulkInvoiceRunStatus` | Now lists **four** routes in, split into the two that are pre-invoice (no active schedule; a mapper refusal) and the two that are not (a throw after the cohort loop; the worker dying — each invoice was its own transaction and none is rolled back). A `failed` run must be **read**, not assumed |
| *"Every per-student call is caught"* | `ProcessBulkInvoiceRun` class docblock | Now true, and the docblock says it was false when written, why, and that the two failure kinds are recorded differently — a bad invoice gets a `failed` row, an unwritable row gets a log line and a broken equality |
| *"this migration alone in batch 2"* | this report, §4 | Corrected in place: true of the **incrementally migrated** `portal_testing` I ran it on; **false of a database migrated from zero**, where everything is batch 1 and a bare rollback would revert 20+ migrations. Re-derive on the database in front of you |

## R2.5 · FIX 5 — the ruling: one figure became two, of two different kinds

The project lead ruled `unaccounted_count` out. A run covers **one** class level, so on a
seven-level school the residual is roughly six-sevenths of the roster on **every successful run** —
and it mixed students at un-named coordinates (normal), student-less episodes (a schema shape) and
unrecorded rows (a defect) into one number where only the third is a signal.

**1 · The run's own accounting — exact, and the defect signal.**

```
billed_count + already_billed_count + failed_count == cohort_count
```

New column `cohort_count` = the size of the list `listForCohort()` returned. The other three are
counted from the rows persisted. **Two independent sources**, which is the only reason asserting the
equality is worth anything.

**2 · The school-wide figure — renamed to say what it counts.**
`unaccounted_count` → **`outside_coordinates_count`** = `billable_count − cohort_count −
unplaceable_count`. Subtracted from the **list sizes**, never from the row counts, so an unrecordable
row cannot drain out of the equality — where it is loud — into a residual that is large and
unalarming on every healthy run. The student-less caveat (`billable_count` is short by N − 1 because
`GROUP BY student_id` collapses every NULL into one group) is carried on the column, on the model and
on the port method.

`docs/handoff/tickets/bulk-run-must-account-for-every-billable-student.md` now carries a **Resolution**
section recording that the denominator it asked for was the wrong shape, why, and what replaced it —
including which of its own acceptance criteria are superseded rather than met.

### The planted red — plant B: a failed student is not recorded

```
tests 1  passed 0  failed 1

FIX5  billed + already_billed + failed equals cohort_count, on a run where all three are non-empty
  the run walked 4 cohort members but only accounted for 3 of them — a student the run saw has
  no row, so nothing records what happened to them
  Failed asserting that 3 is identical to 4.
```

The equality is asserted **first** in that test, so a red names the invariant rather than a bucket
total; the individual bucket numbers are asserted after it, so the equality cannot be satisfied by a
wrong split. FIX1b and FIX1c assert the *other* direction — that the inequality **fires** when a row
is genuinely lost.

## R2.6 · FIX 6 — a compile-time Finance → Academics reference, added for a docblock

`BillableEnrollmentProvider.php` had gained `use App\Academics\BillableEnrollmentAdapter;` solely so a
`{@see}` would resolve — the exact direction the port exists to prevent, and arch rule 3 does not
catch it (it forbids Academics **models**). Import dropped; the method is named in prose, with the
reason written beside it so it does not come back.

## R2.7 · Tickets raised, not fixed here

- `docs/handoff/tickets/student-less-episodes-under-report-the-billable-population.md` — the two
  readers, the `GROUP BY` collapse, the N − 1 shortfall, the query that says whether any School has
  N > 1, and why the honest repair is upstream (`student_id NOT NULL`) rather than counting around it.
- `docs/handoff/tickets/already-billed-wins-over-a-failure-the-run-never-reaches.md` — stated as
  **behaviour**: the pre-check runs first, so a student who is both already-billed and would fail is
  classified `already_billed`. What it costs (a "retry the failures" screen must not read
  `failed_count` as the size of the problem) and what changing it would actually require.
- `docs/handoff/tickets/fee-schedule-lookup-first-rests-on-an-index-not-on-an-order.md` — `->first()`
  with no `orderBy` is determinate **by `finance_fee_schedules_active_unique`**, not by the query, and
  widening `FeeScheduleStatus::billable()` silently makes it non-deterministic. The coupling is
  written down nowhere else.

## R2.8 · One thing the reviewer did not raise, corrected anyway

The migration was **edited in place** rather than superseded by a rename migration. The two tables are
new on this unmerged branch and exist in no database but a throwaway test one, so a
`rename_column` migration would be shipping history for a column that never existed anywhere. The
four-path audit was re-run against the edited file (§R2.9). If that reasoning is rejected, the remedy
is a follow-up migration, not a rebase.

## R2.9 · Gate

`bin/quality`: **PASS 15/15**. Test file: `tests: 17, passed: 17`. Migration re-audited after the
edit — up, rollback (0 tables, 0 triggers), re-up, and the idempotent re-run against a live schema.

---

# Round 3 — the re-check's three findings

Third commit on this branch, on top of `cf1f9c9`. Two of the three are defects I introduced in
round 2 while fixing round 1; the third was there from the start and round 2's own commit message
made a claim that was false about it. All three are stated below as measured, not as intended.

## R3.1 · FIX A — the residual mixed a list size with a row count

`reconcile()` computed

```php
'outside_coordinates_count' => $billable - $cohortSize - $unplaceable,   // $unplaceable = a ROW count
```

`count($unplaceable)` — the list size, read three lines above the loop — was never passed in.
**Round 2's commit message said "subtracted from the LIST SIZES, never the row counts", and the
comment on the line said the same. The line did not.** So a lost unplaceable row did precisely the
thing that sentence exists to forbid: it drained out of an alarm and into a residual that is large
and unalarming on every healthy run.

Measured by the reviewer, and reproduced here: block one unplaceable row → the cohort equality stays
green, `unplaceable_count` reads 1 where the truth is 2, and the residual reads 1 where the truth
is 0.

**And the unplaceable list had no alarm at all.** The cohort had one and the unplaceable list did
not, which is why the loss was silent in both directions. There are now **two lists and two
equalities**:

```
billed_count + already_billed_count + failed_count == cohort_count
unplaceable_count                                  == unplaceable_listed_count
```

New column `unplaceable_listed_count` (the list size), and the residual now subtracts
`cohort_count` and `unplaceable_listed_count` — both list sizes, as the comment always claimed.

### Planted reds

**A1 — the code plant: subtract the row count again.**

```
tests 2  passed 1  failed 1

FIXA  a blocked unplaceable row fires the unplaceable alarm and does NOT move the residual
  the residual must be computed from the unplaceable LIST size, so a lost row stays in the
  unplaceable alarm instead of draining into a number nobody reads as a problem
  Failed asserting that 2 is identical to 1.
```

**A2 — the fixture plant: block one unplaceable row inside the healthy test.**

```
tests 1  passed 0  failed 1

FIXA  both equalities hold and the residual subtracts the LIST sizes on a whole run
  the run listed 2 unplaceable enrollments and recorded 1 — an unplaceable student the run saw
  has no row
  Failed asserting that 1 is identical to 2.
```

The block is a `BulkInvoiceRunRow::creating` listener naming one enrollment id — a **block closure,
not an arrow fn**, because `creating` is a halting event and an arrow fn returning a value cancels
the rest of the chain silently (the `halting-event-arrow-fn` failure mode). A decorated provider
would have been the wrong tool: the list must stay exactly what the real adapter returned, since the
whole question is what happens when the list and the rows disagree.

## R3.2 · FIX B — a refused `update()` left its payload dirty and `failRun()` persisted it

`Model::update()` is `fill()` then `save()`. When the `save()` throws, **the attributes stay
filled** — and `failRun()` was another `$run->update()`, so it flushed the refused payload on the
way out. Two measured states, both the same bug:

| Refused write | What the row said afterwards |
| --- | --- |
| the run transition (`pending → running`) | `status = failed` carrying a `started_at` the database never held |
| the closing write (`reconcile()`) | `status = failed` carrying `finished_at` **and every one of the eight counts, correct and complete**, on a run whose status says it did not finish |

The second is the one a screen would render, and it is the worse of the two precisely because it is
**credible**: a full, accurate report under the word "failed".

**The fix is `writeFailure()`** — a query-builder update carrying `status`, `finished_at` and
`failure_reason`. Not "we remembered to refresh", but *there is nothing to refresh from*: the
model's attribute bag is not consulted at all, so inheriting a figure is unrepresentable rather than
unlikely. (**This paragraph said "naming exactly `status`, `finished_at` and `failure_reason`" and
was overstated by one column — corrected in R4.1 below.**) A `refresh()` before a `save()` would have fixed these two cases and would still write
whatever the next caller happened to have pending. `failRun()` calls it and then `refresh()`es the
in-memory model so callers see the row; `failed()` (the queue hook) routes through the same writer —
two ways to fail a run is how one of them ends up being the one nobody audited.

The status trigger fires on this write exactly as on a model save (the guard is on the table, not
the ORM), and `status` is passed as its backed value because an Eloquent mass update does not run
casts.

### Planted red — `failRun()` back to a model update

```
tests 2  passed 0  failed 2

FIXB  a refused run-transition leaves a failed run with no started_at it never persisted
  the transition was refused, so the database never held a started_at — a failed run must not
  report one
  Failed asserting that '2026-08-19 17:53:30' is null.

FIXB  a refused closing write leaves a failed run with NONE of the figures it never stored
  [cohort_count] was written by an update that was REFUSED — a failing run must not inherit the
  figures of a run that did not close
  Failed asserting that 2 is null.
```

Both tests read the row through `DB::table(...)`, past the model, so nothing in memory can dress it
up, and assert **column by column**. The eight figure columns are asserted one at a time by name
(`birExpectNoFigures()`) rather than in a chain, because a chained `->and(...)->toBeNull()` reports
only *"2 is null"* and leaves the reader to work out which of ten columns that was.

The second test also pins that the two invoices the run DID raise are untouched — the run failed,
the money it made did not un-happen, and re-running returns them as already billed.

## R3.3 · FIX C — an environmental fault read as N ordinary per-student failures

Injected at the first read inside `GenerateInvoice`, a connection error produced three `failed`
rows, `status = completed`, `failure_reason` NULL, and a cohort equality that balanced perfectly. A
screen would have said **"Completed — 0 billed, 3 failed"** — a green word over a total outage.

This is the cost of FIX 1's own medicine: every per-student catch the run needs in order to survive
one bad episode is also what lets an outage wear the costume of N ordinary failures.

**The rule:** a run over a **non-empty** cohort where `billed + already_billed == 0` and
`failed == cohort_count` is recorded `failed`, with a reason saying so.

**It is a heuristic about SHAPE, not a diagnosis**, and the docblock says so in those words. It
**cannot** tell an environmental fault from a genuine domain case where every student legitimately
fails — the two produce byte-identical rows — and it deliberately does not try. Both are reported
the same way, because in both cases the honest thing to tell an operator is *"nothing was billed, go
and read the reasons"*. The row-level reasons are the diagnosis; this is only the word on the run.

**What it does not catch**, written into the docblock and pinned by a test rather than left to be
discovered:

- **A partial outage.** Half the cohort failing still reports `completed`, and that is the realistic
  shape of a flaky connection. The rule fires only on total failure.
- An outage landing after the cohort loop (that throws, and is a run-level failure the ordinary way).
- An outage during the unplaceable loop (those rows are not in this rule; their alarm is R3.1's).
- An empty cohort, excluded on purpose: a class level with nobody in it bills nobody, and firing on
  that would cry wolf every term.

### Planted red — the rule removed

```
tests 4  passed 2  failed 2

FIXC  an environmental fault that fails every student leaves the run FAILED, not completed
  three students, three failures, nothing billed: "Completed — 0 billed, 3 failed" is a green
  word over a total outage
  -BulkInvoiceRunStatus Enum (Failed, 'failed')
  +BulkInvoiceRunStatus Enum (Completed, 'completed')

FIXC  a genuine all-students-fail domain case is reported IDENTICALLY, because the run cannot
      tell them apart
  -BulkInvoiceRunStatus Enum (Failed, 'failed')
  +BulkInvoiceRunStatus Enum (Completed, 'completed')
```

Two of the four FIXC tests stay green under the plant, and that is the point: they are the ones
asserting the rule does **not** fire — the partial outage and the empty cohort.

## R3.4 · A note with no code: a database that ran the earlier migration is stranded

The migration file has been edited in place twice on this branch (see R2.8 for why: the tables are
new, unmerged, and exist in no database but throwaway ones). **A database that already applied
`87b3702`'s version keeps `unaccounted_count` and never receives `cohort_count`,
`unplaceable_listed_count` or `outside_coordinates_count` — `migrate` reports "Nothing to
migrate."** The migration is recorded, so nothing will ever re-run it.

Only throwaway databases are affected. But if **`portal_testing` or `portal_drive`** ever ran the
earlier version, they need:

```bash
DB_DATABASE=portal_testing php artisan migrate:fresh
```

`portal_testing` self-heals in practice — `RefreshDatabase` runs `migrate:fresh` once per test
process — so this bites a database someone migrated by hand and then queried directly. The
production copy `portaa10_portal` and `brookstone_portal_db` have never had this migration and are
unaffected.

## R3.5 · Gate and migration audit

`bin/quality`: **PASS 15/15**. Test file: `tests: 25, passed: 25, assertions: 196`.

Four-path audit re-run against the edited migration, with the rollback depth **re-derived on the
database in front of me** (a from-zero `portal_testing`: batch 1, **0 migrations sitting after
mine**, so `--step=1` is mine — the reasoning R2.4 corrected, applied):

| Path | Result |
| --- | --- |
| `migrate:fresh` | `DONE` |
| `migrate:rollback --step=1` | `finance_bulk%` tables **0**, triggers **0** |
| `migrate` | `DONE` |
| idempotent re-run (migrations row deleted, live schema) | `DONE` in 46.4 ms — no 1050/1061/1826/1359 |

Columns read back from `information_schema` afterwards:

```
cohort_count               int unsigned
billed_count               int unsigned
already_billed_count       int unsigned
failed_count               int unsigned
unplaceable_listed_count   int unsigned
unplaceable_count          int unsigned
billable_count             int unsigned
outside_coordinates_count  int            (signed, deliberately)
triggers: 4
```

---

# Round 4 — wording only, no behaviour

Fourth commit on this branch, on top of `7a37f2a`. The re-check **proved the property** FIX B was
built for and found the claim about it overstated by one column. Nothing executable changed.

## R4.1 · The failure write names FOUR columns, and only three come from the caller

`writeFailure()`'s docblock — and `7a37f2a`'s commit message — said it wrote *"EXACTLY `status`,
`finished_at` and `failure_reason`"*. The measured `SET` clause is **four** columns: Eloquent's
builder appends `updated_at` itself via `Builder::addUpdatedAtColumn()`.

Re-measured here on 8.0.43 with `DB::pretend()` rather than taken on report — six bindings, four in
the `SET` and two in the `WHERE`:

```sql
update `finance_bulk_invoice_runs`
   set `status` = ?, `finished_at` = ?, `failure_reason` = ?,
       `finance_bulk_invoice_runs`.`updated_at` = ?
 where `finance_bulk_invoice_runs`.`id` = ? and `finance_bulk_invoice_runs`.`school_id` = ?
```

**The fourth column is not an exception to the property; "exactly three" was describing the wrong
thing.** What FIX B needs is not a COUNT of columns but a SOURCE: nothing in this statement is read
from the model's attribute bag. `updated_at` is stamped from the framework's clock at statement-build
time, on a **fresh builder** that consults no model instance, so a figure left dirty by a refused
write has no route in. The re-check confirmed it from the other end — a sentinel `updated_at` set on
the model does not land.

`7a37f2a`'s commit message stands as written; this is the correction, recorded here rather than
rewritten there.

## R4.2 · `reconcile()`'s payload is eleven keys, not ten

`status`, `finished_at`, `failure_reason` and the **eight** counts. Ten reach the wire on a healthy
run because `failure_reason` goes NULL to NULL and is therefore not dirty.

The failRun docblock said *"all ten counts"*, conflating the payload's size with the number of
figures in it. There are eight figures: `cohort_count`, `billed_count`, `already_billed_count`,
`failed_count`, `unplaceable_listed_count`, `unplaceable_count`, `billable_count`,
`outside_coordinates_count`. Corrected in the job docblock and in R3.2's table above; the
`birExpectNoFigures()` helper already asserted exactly those eight, so no test changed.

## R4.3 · The `WHERE` clause carries `school_id`, not just the key

`BulkInvoiceRun` uses `BelongsToSchool`, so `SchoolScope` adds `school_id = <active school>` beside
`whereKey()` — visible in the SQL above, and worth a line because a reader counting six bindings for
a three-column update will meet it.

Harmless, and mildly welcome: under `SchoolAware` the active School is the job's own, and in
`failed()` it is the one that method sets for itself before reading the run, so the extra predicate
can only ever match the row already named by its primary key. It is not load-bearing isolation — the
primary key is — but it is not a surprise either once it is written down.

## R4.4 · Gate

`bin/quality`: **PASS 15/15**. No behaviour changed, so the planted reds of rounds 1–3 stand as
recorded.
