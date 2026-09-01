# `perf/manual-run-batch-enrollment-resolve` — one batch read on the ACL port

**Branched from** `staging` at `f79bb76`, clean tree.
**Scope, deliberately narrow:** add `currentForStudents()` to `BillableEnrollmentProvider`, implement
it in `BillableEnrollmentAdapter`, and make `StartManualInvoiceRun` call it once instead of calling
`currentForStudent()` per ticked student. No new request scope, no migration, no UI, no page-size
change.

**Why now.** The Action's docblock justified the per-student loop on a number: _"that is N queries
for N ticked students rather than one … the cost is bounded by what a person can tick."_ The number
was wrong by a factor of eight, and the justification is what stopped the batch being done.

---

## 1 · The measurements

Two databases, on purpose. The resolver was measured against the **dev copy** (`portaa10_portal`,
MySQL 8.0.43, school#1: 611 live students, 611 active episodes) because a resolver's cost is a
property of real data. The full Action was measured against `portal_testing` with a planted cohort,
because the dev copy is five migrations behind and does not have `finance_manual_invoice_run*`.
Everything ran inside an outer transaction that was rolled back; both databases were verified back to
their prior row counts afterwards.

### 1a · The resolver alone, on real data (611 students, school#1)

|                                    | queries | wall ms  | per student               |
| ---------------------------------- | ------- | -------- | ------------------------- |
| `currentForStudent()` in a loop    | 4888    | 1647     | **8.00 queries**, 2.70 ms |
| `billableEpisodes()->whereIn(...)` | **8**   | **82.7** | —                         |

**8N, not N.** `currentForStudent()` eager-loads `SNAPSHOT_RELATIONS` — five declared paths that
expand to seven eager loads on top of the root select. It is the same eight the cohort read pays
once, which `CohortEnrollmentPortTest` has pinned since U6.

The synthetic fixture is not flattering the resolver: the same loop over the planted 611 in
`portal_testing` measured 4888 queries / 1656 ms, against 4888 / 1647 on real data.

### 1b · The full Action at n=611, before and after

`portal_testing`, planted cohort: 611 students across **six** curricula, **12 of them with no
episode**. Three repetitions each, warm.

|                                        | queries  | txn open ms               | targets | placed | unplaceable | distinct enrollment ids |
| -------------------------------------- | -------- | ------------------------- | ------- | ------ | ----------- | ----------------------- |
| **before** (`f79bb76`, quiescent tree) | 6030     | 2757.9 / 2593.0 / 2560.1  | 611     | 599    | 12          | 599                     |
| **after**                              | **1234** | **764.3 / 748.1 / 761.7** | 611     | 599    | 12          | 599                     |

**−79.5 % queries, −70 % transaction-open time**, and the outcome is byte-for-byte the same on every
column that describes what the run did — including 599 _distinct_ enrollment ids, which is what dies
if a batch read returns one shared row for everybody.

### 1c · What the remaining 1234 are, and whose they are

613 of them — **half** — are `Schema::hasColumn()` from `BelongsToSchool`'s `creating` hook, one
uncached `information_schema.columns` query per model insert (611 targets + run + line). That is
repo-wide, predates this branch, and is **not fixed here**: it touches the trait every school-owned
model uses and earns its own change. Written up in full at
`docs/handoff/tickets/belongs-to-school-issues-a-schema-query-on-every-insert.md`.

Backtraced rather than guessed — the frame is
`BelongsToSchool.php:21 → Facade::__callStatic → Schema\Builder::hasColumn → getColumnListing →
getColumns → Connection::select`, fired from `Model::fireModelEvent('creating')`.

---

## 2 · What changed

| file                                                   | change                                                                                                                                    |
| ------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------- |
| `app/Finance/Contracts/BillableEnrollmentProvider.php` | `currentForStudents()` declared; the per-method AMBIENT/ARGUMENT split updated to list it                                                 |
| `app/Academics/BillableEnrollmentAdapter.php`          | implemented — `billableEpisodes()` with `whereIn` in place of `where`, `keyBy(student_id)`                                                |
| `app/Finance/Actions/StartManualInvoiceRun.php`        | one call before the loop; `$enrollments[$studentId] ?? null` inside it; the 8N correction and the removal of the justification it carried |
| `tests/Feature/Finance/CohortEnrollmentPortTest.php`   | two correctness arms + one cost arm for the new method                                                                                    |
| `tests/Feature/Finance/ManualInvoiceRunScreenTest.php` | § 7 and arm `7a`, the shape arm                                                                                                           |
| `tests/Feature/Finance/BulkInvoiceRunTest.php`         | the anonymous port double gains the method (it would be abstract-incomplete otherwise)                                                    |

### The shape of the map, and why absent rather than null

`student_id => BillableEnrollment`, **placeable only**. A student the resolver cannot place is simply
missing a key, which is the batch spelling of the `null` the single call returned, and the Action's
`?? null` is the only behavioural seam between the two shapes. A padded map would have been the
convenient choice and the wrong one: `target_count` means _what the bursar ticked_, not what survived
resolution, and the targets table is keyed on the student for exactly that reason.

`keyBy()` cannot silently collapse two rows into the later one, because `billableEpisodes()` admits
at most one row per student by construction (`MAX(id) … GROUP BY student_id`). A student-less episode
— schema-legal, and the shape `countBillableForSchool()` documents — can emit a row with a NULL
`student_id` from that subquery, and `whereIn` never matches NULL, so it cannot reach the key either.

### Isolation: ambient, and the open question is recorded rather than acted on

`currentForStudents()` keeps the ambient `SchoolScope`, matching `currentForStudent()`, because this
method replaces exactly that call. Giving it the ARGUMENT convention would have left the port
carrying both conventions for one question with the caller free to pick. **Whether the ambient half
of this port should move to the argument convention is open**, and it is written into the method's
docblock as an open question: if it is right it is right for `findByUuid()`, `currentForStudent()`,
`displayFor()`, `matchingStudentIds()` and `admissionNumberIndex()` together, as one change with its
own isolation proofs — not as a side-effect of a batch read.

Pint's `fully_qualified_strict_types` fixer wanted to import `App\Academics\BillableEnrollmentAdapter`
into `app/Finance/Contracts/` to satisfy a `{@see}` in the new docblock. That import is a
Finance → Academics compile-time reference and this port exists precisely so the arrow runs the other
way, so the reference is written in prose instead (as the class docblock's own line 11 already does)
and the fixer has nothing to fix.

---

## 3 · The arm, and why it is not a query count

**The regression to catch is one edit**: somebody putting `currentForStudent()` back inside the
target loop. It reads as obviously correct — it is what the Action did until this commit and its own
docblock defended it — so nothing in the diff would look wrong and the only symptom would be a bursar
waiting.

A literal total was rejected as the shape. A total drifts every time an eager load is added to
`SNAPSHOT_RELATIONS`, and the fix for a drifted total is to raise the number — which is
indistinguishable from raising it to accommodate a re-introduced N+1. What does not drift is the
**shape**:

- the Action's **data reads are flat** in the size of the selection;
- its **writes grow by exactly one per target**.

Arm `7a` measures two runs (3 students and 30, one in every three un-enrolled so the `?? null` branch
runs in both windows) and asserts `largeWrites - smallWrites === 27` **first** — that is the
non-vacuity guard, because "reads identical" is also satisfied by two runs that did nothing — then
`largeReads - smallReads === 0`. It closes by asserting the resolution actually split the selection:
30 targets, 20 placed, 10 NULL, 20 distinct enrollment ids.

A warm-up run is discarded before the two measured ones: Laravel pays the Money cast's column
introspection on first use, and counting it into one window and not the other would make a flat read
count look like a growing one — noise on the exact axis under test.

**Schema-catalogue reads are excluded by FROM-clause and the exclusion is named, not quiet.** They
are `BelongsToSchool`'s, they are already one-per-write for reasons this Action does not own, and
counting them would make the arm assert a defect in the framework layer instead of the property it is
about — and would go red the day that hook is fixed. Its magnitude is left **unasserted**: pinning a
defect's size is how a defect gets preserved.

The absolute count stays where it belongs — on the port, in
`CohortEnrollmentPortTest` ("the BATCH student read costs the same EIGHT, at any list size"), beside
the sibling arm that has pinned the cohort read's eight since U6. That is the number that
legitimately moves when `SNAPSHOT_RELATIONS` does, and the two arms being the same eight is itself a
claim: they are the same builder, differing only in their predicate.

### The port's correctness arms

`currentForStudents` is not proved by the shape arm — a batch read can be flat and wrong. Two arms in
`CohortEnrollmentPortTest`:

- **agreement, derived by the other code path.** Four shapes in one fixture: a placeable student, a
  student holding **two** active episodes (so the `MAX(id)` tie-break has to survive the widening
  from `where` to `whereIn`), a WITHDRAWN student and a student with **no episode row at all**. Each
  id's expectation comes from calling `currentForStudent()` on it, never from restating the batch's
  own rule. The loop is closed against vacuity in both directions — an empty map takes every null
  branch and a padded map takes none — by pinning the split (two present, two absent), the
  two-episode student's episode, and that the two present rows are _different_ episodes.
- **ambient isolation, with the mirror.** School B's id is absent under School A — which also passes
  for an implementation returning nothing — so the same two ids are resolved under School B and must
  come back the other way round.

---

## 4 · The mutation

One-line substitution in `StartManualInvoiceRun::handle()`, verified applied by reading the file back
before running anything:

```php
-                $enrollment = $enrollments[$studentId] ?? null;
+                $enrollment = $this->enrollments->currentForStudent($studentId);
```

**Red**, arm `7a`:

```
Failed asserting that 153 is identical to 0.
tests/Feature/Finance/ManualInvoiceRunScreenTest.php:1041
```

153 is the mechanism, not a magic number: of the 27 extra students, 18 are enrolled and cost eight
queries each (144), and 9 are un-enrolled and cost one each (9) — Laravel skips the eager loads when
there is no parent row to load them for.

**And nothing else caught it.** The same mutation, across
`ManualInvoiceRunScreenTest` + `ManualInvoiceRunTest` + `CohortEnrollmentPortTest`:

```
tests 50  passed 49  failed 1   (only 7a)
```

That is the argument for the arm existing. Every behavioural assertion about the run — the outcomes,
the equality, the report, the claims, the isolation refusals — is blind to whether the selection was
resolved once or six hundred times, because the result is identical either way. Only the shape
differs.

**Restored**, and green:

```
tests 88  passed 88  assertions 614
  ManualInvoiceRunScreenTest · ManualInvoiceRunTest · CohortEnrollmentPortTest
  BulkInvoiceRunTest · ManualInvoiceRunPageTest
```

---

## 5 · Gates run locally

`bin/quality` was **not** run here — Segun runs it in his own terminal. Individually:

| gate                                           | result                                 |
| ---------------------------------------------- | -------------------------------------- |
| `pest tests/Feature/Finance`                   | 966 passed, 5258 assertions            |
| `pest --group=arch`                            | 115 passed                             |
| `pint --test` (changed files only, array form) | passed                                 |
| `composer analyse` (Larastan)                  | 0 errors                               |
| `ci-authz-lint`                                | OK                                     |
| `ci-boundary-lint`                             | OK (8 known exceptions)                |
| `ci-citation-lint`                             | OK (165 baselined keys, 182 citations) |
| `ci-money-lint`                                | OK                                     |
| `ci-sql-clock-lint`                            | OK                                     |
| `ci-dev-namespace-lint`                        | OK                                     |
| `ci-identifier-generation-lint`                | OK                                     |
| `ci-runtime-zero-lint`                         | OK                                     |
| `ci-dependency-integrity-lint`                 | OK                                     |

The citation lint caught the new `BelongsToSchool.php:21` pointer twice before it was accepted: it
requires the symbol name in one of two exact spellings, **on the same line as the path**. A wrapped
docblock splits them and the lint reads the citation as bare.

---

## 6 · What this does NOT do

- **It does not make the Action fast enough to be worth calling synchronous at 611.** 748 ms of
  transaction-open time on a `POST` is better than 2.6 s and is still 748 ms; half of what is left is
  the `BelongsToSchool` hook and the rest is 613 individual `INSERT`s that could be one batched write.
  Neither is in scope here.
- **It does not touch the request contract.** `student_ids` is still `required`, there is still no
  filter scope, and the roster's 100-row ceiling is unchanged.
- **It does not resolve the port's isolation asymmetry** — see § 2, where it is recorded as open.
- **It does not address the divergence between the roster's filter and the biller's resolver**
  (`whereHas('currentCurriculum')` matches _any_ active episode, `currentCurriculum` is a `hasOne`
  with no tie-break, `billableEpisodes()` takes `MAX(id)`). That was measured while surveying option
  (c) and it is latent — zero students in the dev copy hold two active episodes — but it is the thing
  that decides who gets billed the moment a selection is resolved from a filter rather than ticked.
