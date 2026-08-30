# feat/manual-invoice-run-claim-then-bill — commit 1 of 2

**Branch:** `feat/manual-invoice-run-claim-then-bill` off `staging` @ `233f252`.
**Scope:** the manual invoice run's domain machinery — schema, enums, models, job, proofs.
**Amended 30 August 2026** — the targets table was re-keyed from the enrollment to the STUDENT.
See §11, which is the change; everything above it is the original commit and still holds.
**Not in this commit:** the UI, the `student_ids` payload, the CSV paste/upload. Those are commit 2.
**Untouched, deliberately:** `ProcessBulkInvoiceRun`, `GenerateInvoice`, and everything the
scheduled run reads. Nothing on the 5 September path was edited.

Design source: `docs/handoff/bulk-manual-invoicing-brief.md` (§3 option B, §4 "within one run") and
`docs/handoff/tickets/a-supplementary-invoice-has-no-duplicate-backstop.md`.

---

## 1. Where the instruction turned out to be wrong, up front

**One correction, and it is small.** The instruction says the existing run-row outcome values are
`billed|already_billed|failed|unplaceable|sponsored`. That is correct **today** but not as of the
migration it cites: `2026_08_18_110000` created the column with **four** values; `sponsored` was
added by `2026_08_26_100001_bulk_invoice_run_rows_admit_sponsored.php`, together with
`finance_bulk_invoice_runs.sponsored_count`. The stated cohort equality
`billed + already + failed + sponsored == cohort_count` is the post-`2026_08_26` one and is the
right one to have quoted. Nothing downstream changes.

**Everything else in the instruction re-derived exactly**, at the exact line numbers given:

| Claim | Line | Verified |
|---|---|---|
| the invoice is created | `ProcessBulkInvoiceRun:446` | `$invoice = $generate->handle(...)` |
| the run row is written — AFTER | `:593` | `BulkInvoiceRunRow::create([...])` |
| `attempt()` catches and only LOGS | `:386` | `catch (Throwable $e) { Log::error(...) }` |
| `tries = 1` | `:147` | `public int $tries = 1;` |

The supplementary-backstop fact re-derived too: `SupplementaryInvoiceWireTest:217-218` inserts two
identical supplementary rows raw and both return driver code `null`.

**One thing the instruction did not anticipate**, raised rather than decided (§7 below): the job
needs *lines* and a *target list* to bill anything, and neither can come from a `student_ids`
payload that is out of scope. Both are now tables the run owns, which is what makes the four-table
shape rather than a two-table one.

---

## 2. What was built

| File | |
|---|---|
| `database/migrations/2026_08_30_100000_create_finance_manual_invoice_run_tables.php` | 4 tables, 1 generated column + unique index, 4 domain triggers, shape read-backs |
| `app/Finance/Enums/ManualInvoiceRunStatus.php` | `pending\|running\|completed\|failed` |
| `app/Finance/Enums/ManualInvoiceRunOutcome.php` | `claimed\|billed\|failed` |
| `app/Finance/Models/ManualInvoiceRun.php` `…RunTarget` `…RunLine` `…RunRow` | |
| `app/Finance/Jobs/ProcessManualInvoiceRun.php` | claim-then-bill |
| `tests/Feature/Finance/ManualInvoiceRunTest.php` | 13 arms, 68 assertions |

### The four tables and why four

- `finance_manual_invoice_runs` — no `term_id`, no `class_level_id`. Their **absence** is the reason
  the table exists: a manual list spans class levels, and a nullable slot on
  `finance_bulk_invoice_runs` would make one table mean two things (brief §3, option A).
- `finance_manual_invoice_run_targets` — **the instruction**. A manual run's list is *given*, where a
  scheduled run's is *computed* by `listForCohort()`. Storing it is what makes `target_count` an
  independent source for the equality rather than a tally the job kept about itself. **Keyed on the
  STUDENT** — see §11.
- `finance_manual_invoice_run_rows` — **the record**, and the claim. Same
  `UNIQUE(school_id, run_id, enrollment_id)` the scheduled run has; what moved is the money, to the
  other side of it.
- `finance_manual_invoice_run_lines` — one set of lines for the whole list. The scheduled run pins a
  `fee_schedule_id` and maps from the catalog; a manual run has no catalog row to point at.
  `bank_account_id` is **NOT NULL** here, narrower than `InvoiceLineSpec` permits, because S11 made a
  destination required on every charge line and there is no fee item to read a default off.

---

## 3. The claimed state: which shape, and why

**Chosen: a `claimed` value on the NOT NULL `outcome` column. Not a nullable `claimed_at`.**

Two reasons, neither of them taste:

1. **A nullable `outcome` makes a claim indistinguishable from a lost write.** `outcome` is NOT NULL
   and trigger-enforced; admitting NULL means the domain trigger must let NULL through, and from then
   on "the job claimed this row and died" and "something wrote a row with no outcome at all" are the
   same row. The state that has to be loudest would become the quietest.
2. **`created_at` already *is* `claimed_at`.** The row is INSERTed at the claim and at no other
   moment, so a separate timestamp is a second copy of a fact the table already holds — and a second
   copy is a thing that can disagree. "How long has this been stuck" is `now() - created_at`, exactly.

Recorded in the enum's docblock as the decision, with both alternatives named.

---

## 4. The new cohort equality, written out

```
billed_count + failed_count + unplaceable_count == target_count
```

`target_count` is the size of the list **walked** (`…_targets`) — and, because a target is keyed on
the student, it is **what the bursar ticked** rather than what survived resolution. The three counts
are counted from the rows **persisted**. Two independent sources — the only reason the equality can
fail and therefore the only reason asserting it is worth anything.

**`unplaceable_count` is a term; `claimed_count` is not.** The line between them is whether anything
is *unknown*. An unplaceable student is a finished, correct, reported outcome, so leaving them off
the left would fire the alarm on every healthy run that has one. A claimed row is a run that does not
know what happened, so `claimed_count` is recorded beside the equality as the diagnosis — it is
exactly the shortfall. Adding it to the left balances the sum on precisely the runs the sum exists to
catch; that is stated three times (migration, enum, job) because it is the one edit that would
silently switch the alarm off.

One other value was considered for the outcome enum and **refused because it has no producer**:
`already_billed` classifies a refusal the supplementary path never produces (that is the whole
ticket). `sponsored` is absent as a *skip* for the opposite reason — this feature exists partly to
bill those students (`scholarship-and-cutover-decisions.md` §4, §11), so a sponsored student is
billed like anyone else and lands in `billed`.

---

## 5. The new failure mode, stated as a trade

A death between the claim and the outcome write leaves the row `claimed` **forever**. That enrollment
is not billed, `tries = 1` means nothing retries it, and **there is no sweeper**. It is nonetheless
strictly better than what it replaces, and the comparison is written into the migration, the enum,
the row model and the job so a reviewer meeting a stuck row finds the reason rather than deriving it:

| | death between the two writes produces |
|---|---|
| **before** (bill-then-record) | an invoice with **no row** — money on a family's balance, absent from the run's counts, reported by nothing, and turned into a **second** charge by any re-execution |
| **after** (claim-then-bill) | a row with **no invoice** — nobody charged, the enrollment named, and the equality red |

A visible unknown in place of a silent double charge. Arm 3a asserts *both* halves: the equality goes
short **and** `mirInvoiceCount($stuck) === 0`.

Second residual, named: a stranded run also **holds `active_run_key`**, so the School cannot start
another manual run until a human resolves it. That is the correct direction to fail and it will be
met as "the button is stuck".

---

## 6. The run-level guard — it holds at the database

```sql
active_run_key BIGINT UNSIGNED GENERATED ALWAYS AS (IF(status IN ('pending','running'), school_id, NULL)) STORED
UNIQUE finance_manual_invoice_runs_active_run_unique (active_run_key)
```

**Measured, not assumed.** Read back out of `information_schema` after the ALTER:

```
[c] => active_run_key
[e] => STORED GENERATED
[g] => if((`status` in (_utf8mb4'pending',_utf8mb4'running')),`school_id`,NULL)
[t] => bigint unsigned

[i] => finance_manual_invoice_runs_active_run_unique   [n(NON_UNIQUE)] => 0   [s] => 1   [c] => active_run_key
```

**Refused at the engine, by raw insert** (arm 4a — `DB::table(...)->insert()`, no model, no scope):

- second `pending` for the same school → **1062**
- second `running` for the same school → **1062**
- a *different* school's `pending` → `null` (so the key isolates rather than serialising the platform)

**And it releases** (arm 4b, the mutation guard): after the first run reaches `completed`, a raw
`pending` insert is accepted; after `failed`, likewise.

**What it does not stop, unchanged and still open in the brief §4:** two runs raised *sequentially*
over the same list. The first completes, its key goes NULL, the second is admitted and bills everyone
again. That is a deliberate act rather than an accident, and the answer to it is not decided here.

**Not substituted with an application check.** The generated-column approach worked; no
`Action`-level guard was added.

---

## 7. Bite-proofs — verbatim red text

Every plant was verified **applied** (grepped for its marker) before the run, and every one was a
single-line edit except where noted. Reverted after each; the working tree matches the index exactly
and the suite is 13/13 green.

### Plant A — the claim write removed (bill first, record after: the scheduled path's ordering)

```
tests 13 passed 11 failed 2 errors 0
--- it_1a_—_a_re_execution_of_the_same_run_raises_NO_second_invoice__and_writes_no_second_row | line 282
Failed asserting that 2 is identical to 1.
--- it_3a_—_a_run_finishing_with_a_claim_outstanding_does_NOT_satisfy_billed_+_failed_==_target_count | line 369
Failed asserting that 1 is identical to 0.
```

Line 282 is `expect(mirInvoiceCount($a))->toBe(1)` **after a re-execution** — the re-execution
produced the duplicate invoice. Line 369 is `expect(mirInvoiceCount($stuck))->toBe(0)` — under
bill-then-record the previously-stranded enrollment was charged. **The arm discriminates.**

### Plant B — `UNIQUE(school_id, run_id, enrollment_id)` on the rows table removed

```
tests 13 passed 10 failed 3 errors 0
--- it_1a_… | line 282   Failed asserting that 2 is identical to 1.
--- it_1c_… | line 306   Failed asserting that null is identical to 1062.
--- it_3a_… | line 355   Failed asserting that two arrays are identical.
                         -    'billed' => 1,
                         +    'billed' => 2,
                              'claimed' => 1,
```

### Plant C1 — `active_run_key` expression made unconditional (`school_id`)

Caught **before any test ran**, by the migration's own read-back:

```
Refusing to record this migration: active_run_key is generated over an expression that does not name
`status` [`school_id`]. A key that does not go NULL when a run reaches a terminal state would refuse
every SECOND run this School ever has.
```

### Plant C2 — expression names `status` but never releases (`IF(status IS NOT NULL, school_id, NULL)`)

```
tests 13 passed 12 failed 1 errors 0
--- it_4b_—_the_key_RELEASES_on_a_terminal_status__so_a_School_can_run_again | line 437
Failed asserting that 1062 is null.
```

**Arm 4a stayed green.** This is the point of 4b: a permanently-held key passes the refusal arm
perfectly while being a permanent outage for every School's second run. Only the positive arm crossing
the axis 4a removes can tell the two apart.

### Plant C3 — the run-key index made non-UNIQUE

Caught by the read-back first:

```
Refusing to record this migration: finance_manual_invoice_runs_active_run_unique is missing, not
UNIQUE, or does not cover exactly (active_run_key) — got [active_run_key]. An index with an extra
column constrains a wider key and admits the second pending run this guard exists to refuse.
```

With the read-back additionally neutered (**two-line plant**, so arm 4a could be reached at all):

```
tests 13 passed 12 failed 1 errors 0
--- it_4a_—_a_second_non_terminal_run_is_refused_1062_by_the_generated_column_index | line 417
Failed asserting that null is identical to 1062.
```

### Plant D — `claimed_count` folded into the left-hand side (`'billed_count' => $billed + $claimed`)

```
tests 13 passed 12 failed 1 errors 0
--- it_3a_… | line 357   Failed asserting that 2 is identical to 1.
```

That is the count arm firing first. With the three count assertions silenced so the **equality
assertion itself** is what is under test:

```
--- it_3a_… | line 360   Expecting 2 not to be 2.
```

The equality arm reds on exactly the edit that would switch it off. **This is the mutation the
docblocks warn about three times, and it is caught.**

---

## 8. Migration audit — the four paths

Re-derived per run rather than assumed: `migrate:status` showed this migration as the branch's latest
(batch 2), so `--step=1` rolls back *this* one.

| Path | Result |
|---|---|
| **up** | `2026_08_30_100000_… 298.39ms DONE` — the four read-back assertions passed |
| **shape** | generated column `STORED GENERATED` over the `status` expression; index `NON_UNIQUE = 0` over exactly `(active_run_key)`; four triggers, all `BEFORE`, correct events, correct tables |
| **down** | all four tables `gone`, `triggers left: 0` |
| **re-up** | `245.57ms DONE` — every DDL statement is individually guarded, so the rollback/re-up leg of `bin/quality-clean-db` re-asserts rather than 1359/1060 |

---

## 9. Gates run locally

| Gate | Result |
|---|---|
| `pest tests/Feature/Finance/ManualInvoiceRunTest.php` | **17 passed, 104 assertions** (was 13 / 68 before the amendment) |
| `pest tests/Feature/Finance` | **903 passed, 4852 assertions** — a clean run with no mid-run edits |
| `pint` (changed files, array form) | passed; the earlier pass fixed 3 of my own files only — **no unrelated sweep** |
| `php bin/ci-authz-lint.php` | OK — 0 known |
| `php bin/ci-boundary-lint.php` | OK — 8 known temporary exceptions, unchanged |
| `pest --group=arch` | 115 passed, 600 assertions |
| `composer analyse` (Larastan) | `{"tool":"phpstan","result":"passed","errors":0}` |
| `bin/quality` | **NOT run** — reserved for your terminal |

One suite run was **discarded rather than reported**: the test file was edited (a cosmetic alias
removal) while it was in flight, which is the "gate runs on the working tree" failure. It was killed
and re-run from a quiescent tree; the 903 above is the re-run.

---

## 10. Open, and deliberately not decided here

1. **The across-runs duplicate.** Sequential runs over the same list are still admitted. Brief §4.
2. ~~**A selected student who resolves to no current enrollment has nowhere to be reported.**~~
   **CLOSED by the amendment — see §11.**
3. **What a student with more than one current enrollment means.** Brief §2, untouched.
4. **Whether a bulk manual run is a governance act.** Brief §6, untouched — "ask; do not infer".
5. **No sweeper for a stuck claim or a stranded `running` run.** Same residual
   `ProcessBulkInvoiceRun::failed()` records, for the same reason.

---

## 11. AMENDMENT — the targets table is keyed on the STUDENT

### Why

§10 item 2 of this report flagged it as an open question for commit 2. It is a hole, not a note.
Keyed on the enrollment, `target_count` counted **what survived resolution**: a run could report
"90 of 90" after the bursar selected 96 — balanced, complete, and six families short. Brookstone
ruled on 30 August 2026 that this feature issues **directly, with no maker-checker**, so there is no
second human and the run report is the only place a wrong selection can surface.

### What changed

| | before | after |
|---|---|---|
| `…_targets.student_id` | nullable lookup | **NOT NULL**, composite FK to `students (id, school_id)` |
| `…_targets.enrollment_id` | NOT NULL | **nullable** — resolution is an outcome, not a precondition |
| `…_targets.enrollment_uuid` | NOT NULL | nullable, exactly when `enrollment_id` is |
| `…_targets` unique | `(school_id, run_id, enrollment_id)` | **`(school_id, run_id, student_id)`** |
| `…_rows` same three columns | as above | as above |
| `…_rows` unique | `(school_id, run_id, enrollment_id)` | **plus** `(school_id, run_id, student_id)` — see below |
| outcome domain | `claimed\|billed\|failed` | **`claimed\|billed\|failed\|unplaceable`** |
| `…_runs` | — | **`unplaceable_count`** added |

An unresolvable student now **is** a target row, and the run records them `unplaceable` — the
scheduled run's own name for "the run saw this person and could not place them", reused rather than
re-invented.

### The rows table now has TWO unique indexes, and nothing was removed

The instruction said the claim mechanism on the rows table must be untouched. **The retained
enrollment index is untouched; a student index was added beside it**, because the unit of work is now
a target and a target is a student. Both earn their place on their own axis:

- **`(school_id, run_id, student_id)` is the claim.** `enrollment_id` is nullable now, and **NULLs do
  not collide in a MySQL unique index** — so an enrollment-keyed claim would admit any number of
  duplicate `unplaceable` rows for one child. The new outcome would have arrived with a hole shaped
  exactly like itself.
- **`(school_id, run_id, enrollment_id)` still earns its keep.** It is the last thing between a
  resolver that maps two ticked students onto ONE episode and that episode being billed twice inside
  a single run — on a path whose invoice kind has no duplicate backstop at all.

`recordUnplaceable()` writes its row in **one INSERT and never claims first**: the claim exists to
bracket a call that moves money, and there is none here, so an unplaceable target cannot become a
stuck claim.

### MEASURED — the nullable composite FK on 8.0.43

Run against a scratch table with the exact two-FK shape, then re-pinned as test arm 3d against the
real tables. `information_schema.REFERENTIAL_CONSTRAINTS.MATCH_OPTION = NONE` on every FK (MySQL
implements only MATCH SIMPLE).

| probe | result |
|---|---|
| `enrollment_id` NULL, own student | **accepted** |
| `enrollment_id` = own school's enrollment | accepted |
| `enrollment_id` = **another school's** enrollment | **1452 refused** |
| `enrollment_id` = a non-existent id | **1452 refused** |
| `student_id` = **another school's** student, `enrollment_id` **NULL** | **1452 refused** |
| `student_id` NULL | 1048 (column is NOT NULL) |
| DELETE a referenced enrollment while a NULL-enrollment row exists | **1451** — RESTRICT still enforced |

**The guarantee is intact, and it is intact for EVERY row, not merely every non-null one.** The
nullable component leaves the *enrollment* check unenforced only on rows that name no enrollment; the
School binding is carried by `student_id`, which is NOT NULL and has its own composite FK — measured
refusing a cross-School student **on a row whose `enrollment_id` was NULL**. No application-level
check was substituted anywhere.

### New and changed arms

17 tests, 104 assertions (was 13 / 68).

- **3c** a ticked student who resolves to nothing is counted, lands `unplaceable`, the row names them
  with `enrollment_id`/`enrollment_uuid`/`invoice_id`/`reason` all NULL, and the equality balances.
- **3d** the FK measurements above, against the real table, plus the target-uniqueness refusal.
- **1c** re-written to exercise **each index on its own axis**: same student + different episode, and
  different student + same episode.
- **1e** two `unplaceable` claims for one student → the second is 1062 — the case an enrollment-keyed
  index provably cannot catch.
- **6d** a selection nobody could be placed from is `completed`, not `failed`: `failed === target_count`
  keeps unplaceable rows out of the nobody-billed rule without a clause of its own, exactly as
  `ProcessBulkInvoiceRun::reconcile()` gets that property from sponsored rows.
- **0 / 1a / 3a / 3b / 6c** equality assertions widened to the three-term form.

### Bite-proofs for the amendment — verbatim red

**Plant E — `unplaceable_count` dropped from the left-hand side (`'unplaceable_count' => 0`):**

```
tests 17 passed 15 failed 2
--- it_3c_… | line 493   Failed asserting that 0 is identical to 1.
--- it_6d_… | line 696   Failed asserting that 0 is identical to 2.
```

With 3c's count assertions silenced so the **equality assertion itself** is what fires:

```
--- it_3c_… | line 492   Failed asserting that 1 is identical to 2.
--- it_6d_… | line 692   Failed asserting that 0 is identical to 2.
```

**Plant F — the student claim index removed, enrollment index left in place:**

```
tests 17 passed 15 failed 2
--- it_1c_… | line 358   Failed asserting that null is identical to 1062.
--- it_1e_… | line 385   Failed asserting that null is identical to 1062.
```

**Arm 1a stayed green** — the retained enrollment index still catches a straightforward
re-execution over a *placed* target, which is why 1a is not the discriminating arm for the claim and
1c/1e are. That is the claim-mechanism proof the instruction asked for, from both ends: the mechanism
still refuses the duplicate (17/17 green), and removing the index it now rests on reds exactly the
two arms that can see it.

**Plant G — the retained enrollment index removed, claim index left in place:**

```
tests 17 passed 16 failed 1
--- it_1c_… | line 364   Failed asserting that null is identical to 1062.
```

So neither index is redundant: each is the sole thing catching at least one arm.

**Plant H — the target key reverted to the enrollment:**

```
tests 17 passed 16 failed 1
--- it_3d_… | line 543   Failed asserting that null is identical to 1062.
```

The same child ticked twice under two different episodes is admitted again — the defect the re-key
closes, made explicit.

### Migration audit re-run against the amended schema

`up 332.18ms` → shape read-back (both tables: `student_id` NOT NULL, `enrollment_id`/`enrollment_uuid`
nullable; targets carry one unique index on `(school_id, run_id, student_id)`; rows carry both; four
composite FKs on rows, four on targets; `runs.unplaceable_count` present) → `down` (all four tables
gone, `triggers left: 0`) → `re-up 332.18ms`. `--step=1` was re-derived from `migrate:status`, which
showed this migration as the branch's only one.
