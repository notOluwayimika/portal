# feat/manual-invoice-run-selection-and-report — commit 2 of 2

**Branch:** `feat/manual-invoice-run-selection-and-report` off `staging` @ `7a495cc`.
**Scope:** the caller and the reader for commit 1's four tables — a store endpoint that turns a
bursar's selection into a run, and the RUN REPORT.
**Not in this commit:** the filter-and-tick screen, the CSV admission-number paste. Server side only.
**Untouched, deliberately:** `ProcessManualInvoiceRun`, `ProcessBulkInvoiceRun`, `GenerateInvoice`,
`BillableEnrollmentAdapter` and the migration. Nothing on the scheduled path was edited, and the
resolver was USED rather than re-written.
**No migration.** See §5.

---

## 1. Where the instruction turned out to be wrong, up front

**Three corrections. The second is the one that changed the code.**

**1.1 — an untranslated 1062 is a 409, not a 500.** The instruction says the one-active-run key's
1062 must become a friendly 422 "not a 500". `bootstrap/app.php:212-213` maps
`errorInfo[1] === 1062` to `response()->conflict('Duplicate entry detected.')`, so the untranslated
answer is a **409** carrying that sentence. Measured, verbatim, by removing the translation (Plant D,
§4). The requirement is unaffected — "Duplicate entry detected." names nothing and suggests nothing —
but the failure being described was the wrong one.

**1.2 — "the guard is the database, the pre-check makes it reachable" does NOT hold for the
cross-School refusal, and the first version of this code claimed it did.** The instruction's model —
S11's `assertDestinationsChosen()`, where the DB refuses and the request layer makes the refusal
legible — is exactly right for the *run key*. It is **false for the student ids**, and the difference
was measured rather than argued:

> With the isolation rule removed, the cross-School arm returned **201**.

The composite FK `finance_manual_invoice_run_targets (student_id, school_id) -> students (id,
school_id)` refuses a foreign student that is **written**. A foreign uuid resolved under a
School-scoped lookup is never written — it is **dropped**, and the run bills the rest and reports
success over a selection the bursar did not make. An FK cannot refuse what never reaches it.

Two things changed as a result. The docblocks now say the rule ENFORCES and the FK is the backstop
for any *other* caller of the Action (one handed raw ids) — a real guarantee, and a narrower one.
And `StoreManualInvoiceRunRequest::selectedStudentIds()` now **throws** on an unresolved id instead of
skipping it, so a weakened rule degrades loudly instead of into "told 240, billed 25". After that
change the same plant reds as a `LogicException`, not a 201.

**1.3 — the ACL port's determinacy claim is ENFORCED, but not where a reader would look.** §2.

---

## 2. The ACL port: is "at most one member" enforced or assumed? — MEASURED

**The claim** (`BillableEnrollmentAdapter::currentForStudent()`): *"student_id pinned, the result set
has at most one member and first() is determinate."*

**ENFORCED — by the query, not by the schema.** Both halves measured, in arm 6a:

| | |
|---|---|
| **Schema** | `student_curricula` carries `UNIQUE(student_id, curriculum_id)` and nothing else on this axis. Two ACTIVE episodes for one student in two curricula were **written and accepted** — the arm asserts the count is 2. So the instruction's suspicion was right: the schema does not stop it. |
| **Query** | `billableEpisodes()` is `whereIn(id, SELECT MAX(id) … GROUP BY student_id)`. With `student_id` pinned the outer query has at most one member **whatever the table holds**, and the winner is the highest id. |

So the docblock is true, and it is true by mechanism rather than by wish — which is the distinction
that decides whether a ticket is owed. **No ticket is owed**, and none was written. What *would* have
been owed is a ticket had the claim rested on a constraint that does not exist; instead it rests on a
subquery, and this commit now pins both of that subquery's clauses from a consumer:

- `MAX` → `MIN`: **red** (the target lands on the earlier episode).
- the whole `whereIn(…)` clause removed: **red** (same symptom, via natural row order).

This also closes brief §2's third open item — *"decide what a student with more than one current
enrollment means"*. It means **the latest ACTIVE episode by id, billed once**, and that is now
asserted from a fixture with two episodes rather than inferred from one with one. A one-episode
fixture cannot see any of this: the claim would read as covered while the axis it names was never
crossed.

---

## 3. What was built

| File | |
|---|---|
| `app/Finance/Http/Requests/StoreManualInvoiceRunRequest.php` | the payload, the isolation refusal, the line rules |
| `app/Finance/Actions/StartManualInvoiceRun.php` | run + lines + targets in one transaction; resolution through the port |
| `app/Finance/Http/Controllers/ManualInvoiceRunController.php` | `store` and `show` — **`show` is the report** |
| `app/Finance/Http/Resources/ManualInvoiceRunResource.php` | the run row; nullable counters passed through uncast |
| `routes/endpoints/finance.php` | 2 routes, both `permission:finance.invoice.generate` |
| `config/rbac.php` | 4 manual-run models added to `fail_closed_models` |
| `app/Finance/Enums/ManualInvoiceRunStatus.php` | `isTerminal()` |
| `tests/Feature/Finance/ManualInvoiceRunScreenTest.php` | 17 arms, 124 assertions |

### The payload

`student_ids` is an array of **student uuids** the caller names in full — every id on this API surface
is a uuid, and the client that will POST this holds student uuids (`displayFor()` is what every
Finance row serializer and the students index return). **No filter payload, and none must be added
here later**: brief §1 rules that "invoice all N matching", if it is ever offered, is resolved
server-side from the filter and never from a client id list, because that is the live
`guardians/bulk-action-bar.tsx` defect and here it bills families.

`lines.*.bank_account_id` is **`required`**, not `sometimes` — S11. `GenerateInvoiceRequest` needs a
separate `assertDestinationsChosen()` pass because its lines may be reductions; a manual run has no
reduction line to make room for, so the requirement collapses into the rule list and cannot be reached
around. `amount_minor` is `min:1` for the same reason: every line is a `Charge`, and a negative amount
would be a reduction with no policy to authorise it — a credit note's job.

**No cap on the selection size**, deliberately: the scheduled run has none, and a number invented here
would have no consumer's evidence behind it.

### The resolver — the port, used

`StartManualInvoiceRun` calls `BillableEnrollmentProvider::currentForStudent()` once per ticked
student. **No second resolver was written**, and no batch method was added to the port either — adding
one here would be the second resolver the rule forbids, and the consumer is one bursar pressing one
button over a list they typed by hand. A student the port cannot place becomes a target row with
`enrollment_id` NULL, which is what commit 1's re-key bought.

### The report — what `show` returns and why each field is there

```
target_count                          COUNT(finance_manual_invoice_run_targets) — the bursar's own
                                      number, available from the moment the run exists
counts { billed, failed, unplaceable, claimed }
                                      re-derived from the ROWS table, not read off the run's counters
reconciliation.accounted_for          billed + failed + unplaceable
reconciliation.balances               accounted_for === target_count, or NULL while non-terminal
reconciliation.recorded_matches_rows  the job's five counters against the tables they describe
recorded { … }                        the job's counters, nullable, passed through UNCAST
buckets { billed, failed, unplaceable, claimed }
                                      each: total, truncated, and rows NAMED by admission number,
                                      with enrollment_uuid, invoice_uuid and reason
lines [ … ]                           what everyone was charged, with the destination account
```

**`target_count` comes from the TARGETS table and not from `runs.target_count`.** That is the whole
report. The run's counter is the job's own tally, written at the end of the walk, so it is NULL while
the run is in flight and it is not independent of the sum it would be checked against. Reading it
instead is the "90 of 90" defect, and Plant F reds two arms on exactly that edit — including a run
that failed whole, which under the plant reports **"0 of 0 — balanced"**.

**`claimed` is shown and is never a term.** The line is whether anything is unknown. Plant E folds it
in and, with the count assertion silenced so the equality itself is under test, the arm reds with
`Failed asserting that true is false.`

**`balances` is NULL while the run is not terminal.** Mid-run a shortfall is the normal state;
reporting `false` there fires the alarm on every healthy run and teaches a bursar to ignore the one
signal standing where a second signature would otherwise be.

### The one-active-run refusal — ONE control, not two, and that is a measured decision

A pre-check (`whereIn(status, [pending, running])` before the insert) **was written and then
removed**. It could not be told apart from the 1062 catch by any arm:

| plant | arm 3a |
|---|---|
| pre-check removed, catch kept | **GREEN** |
| catch removed, pre-check kept | **GREEN** |
| both removed | **RED** — 409 "Duplicate entry detected." |

Redundant twins: each sufficient, neither necessary, neither individually provable. Keeping both
would have shipped two controls of which no test could see either. The **catch survived**, because it
covers the race a pre-check cannot (two bursars, one instant) and because a pre-check is by
construction a time-of-check/time-of-use read — the thing the generated column exists to make
unnecessary. Re-planted against the single survivor, arm 3a is **red**.

The catch is keyed on the **index name** as well as on 1062: the targets table's
`(school_id, run_id, student_id)` and the lines table's `(school_id, run_id, sort_order)` both answer
1062 from this same write, and reporting either as "a run is already in flight" would be a wrong
diagnosis dressed as a helpful one.

### `fail_closed_models` — four entries, and the arm that proves them had to be rebuilt

All four manual-run models were added. The first version of arm 2c **passed with the entries
removed** — a false green: `Invoice` is already on the list, so a run with a billed row 409s on the
eager-load of the invoice regardless. The arm now reads an **all-unplaceable** run, which never
touches `finance_invoices`, so the refusal can only come from the new entries. Re-planted, it is red
with **200** — a super admin with no School reading another School's run report.

---

## 4. Bite-proofs — verbatim red text

Every plant was verified applied (grepped for its marker) before the run and reverted after; the
working tree carries no `PLANT_` marker and `git diff --stat` is 80 insertions across three tracked
files plus five new ones.

### Plant A — the unplaceable bucket dropped from the report

```
tests 17 passed 16 errors 1
--- it_4a_—_names_the_UNPLACEABLE_by_admission_number,_counts_them,_and_the_equality_still_balances
Undefined array key "unplaceable"
```

### Plant B — the scheduled run's sponsored-student exclusion copied onto this path

```
tests 17 passed 16 failed 1
--- it_1b_—_a_SPONSORED_student_is_BILLED,_because_this_feature_exists_to_bill_them | line 319
Failed asserting that two arrays are identical.
--- Expected
+++ Actual
     'billed' => 2,
+    'billed' => 1,
```

### Plant C — the cross-School isolation rule removed

Before the `selectedStudentIds()` fix, this plant produced **201** — the finding in §1.2. After it:

```
tests 17 passed 14 failed 3
--- it_2a_… Expected response status code [422] but received 500.
LogicException: A selected student could not be resolved after validation passed. The isolation
rule on student_ids is what must refuse this, and it did not.
--- it_2b_… Expected response status code [422] but received 500.
--- it_2d_… Expected response status code [422] but received 500.
```

Arm 2b is the same refusal on a `super_admin` seat with `auth.gate_before_superadmin` ON, so the
bypass actually reaches the controller rather than the arm measuring a 403 from the permission
middleware.

### Plant D — the 1062 translation removed (the only remaining control)

```
tests 1 passed 0 failed 1
--- it_3a_—_a_second_run_while_one_is_in_flight_is_a_422_NAMING_it,_not_a_bare_duplicate_entry_conflict
Expected response status code [422] but received 409.
PDOException: SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '1' for key
'finance_manual_invoice_runs.finance_manual_invoice_runs_active_run_unique'
```

**D2 / D3** — pre-check alone removed, then catch alone removed: both **GREEN**. That is the
measurement behind removing the pre-check; see §3.

### Plant E — `claimed` folded into the equality

```
tests 1 passed 0 failed 1        (run --filter=4b; no other arm crosses this axis)
--- it_4b_… | line 632   Failed asserting that 2 is identical to 1.
```

That is the count assertion firing first. With it silenced so the **equality assertion itself** is
what is under test:

```
--- it_4b_… | line 632   Failed asserting that true is false.
```

### Plant F — `target_count` read from `runs.target_count`

```
tests 17 passed 15 failed 2
--- it_4c2_—_a_run_with_NO_lines_fails_WHOLE… | line 708   Failed asserting that 0 is identical to 1.
--- it_4d_—_a_run_still_in_flight… | line 746   Failed asserting that 0 is identical to 2.
```

Arm 4a stayed green, and that is the honest shape: on a healthy completed run the two sources agree,
so the discriminating arms are the ones where they **diverge** — a run still in flight, and a run that
failed before reconciling. Under the plant the second reports "0 of 0, balanced" over a real
selection.

### Plant G — the four `fail_closed_models` entries removed

```
tests 1 passed 0 failed 1
--- it_2c_—_the_report_of_another_School's_run_is_a_404,_and_with_no_School_at_all_a_409
Expected response status code [409] but received 200.
```

### Plant H — `MAX(id)` → `MIN(id)` in `billableEpisodes()`

```
tests 1 passed 0 failed 1
--- it_6a_—_two_ACTIVE_episodes_for_one_student_are_ADMITTED_by_the_schema,_and_resolve_to_exactly_one
line 905   Failed asserting that 1 is identical to 2.
```

### Plant H2 — the `whereIn(MAX(id) … GROUP BY student_id)` clause removed entirely

```
tests 1 passed 0 failed 1
--- it_6a_… | line 905   Failed asserting that 1 is identical to 2.
```

**No plant came back green.** Three arms were rebuilt because a plant showed them non-discriminating
(2a/2b under Plant C, 2c under Plant G, and arm 4c which was named for a per-target failure while
asserting a run-level one — it now reconstructs the per-target refusal the way
`ManualInvoiceRunTest`'s arm 3b does, and 4c2 carries the run-level case beside it).

---

## 5. Migration — none, and the CHECK stays

**This commit carries no migration.** Commit 1's four tables hold everything the endpoint and the
report need: `finance_manual_invoice_runs` already has the five counters and the generated run key,
the targets table is already keyed on the student, and the rows table already carries `unplaceable`.

So `finance_manual_invoice_run_lines_amount_currency_shape` was **left as a CHECK**, per the
instruction's own condition. It remains inert on Percona 5.7.23 with only `Money`'s constructor behind
it, and it remains flagged in commit 1's report. A standalone migration in cutover week is exactly the
shape Finding 0 warns about, and this commit gives it no other reason to exist.

---

## 6. Gates run locally

| Gate | Result |
|---|---|
| `pest tests/Feature/Finance/ManualInvoiceRunScreenTest.php` | **17 passed, 124 assertions** |
| `pest tests/Feature/Finance/ManualInvoiceRunTest.php` | 17 passed, 104 assertions — commit 1 untouched |
| `pest tests/Feature/Finance` | **947 passed, 5116 assertions** (was 903 at commit 1) |
| `pest --group=arch` | 115 passed, 600 assertions |
| `pest tests/Feature/Rbac/RouteAccessParityTest.php` + `RouteMiddlewareBaselineTest.php` | 19 passed — **no oracle regeneration needed**; the two new routes are additive and neither baseline reds |
| `php bin/ci-authz-lint.php` | OK — 0 known |
| `php bin/ci-boundary-lint.php` | OK — 8 known temporary exceptions, unchanged |
| `composer analyse` (Larastan) | `{"tool":"phpstan","result":"passed","errors":0}` |
| `pint` (changed files, array form) | passed; 8 files, **no unrelated sweep** — `git diff --stat` is 80 insertions in 3 tracked files |
| `bin/quality` | **NOT run** — reserved for your terminal |

**One gate caught a real defect rather than a formatting one.** `bin/ci-boundary-lint.php` refused
`Student::withTrashed()` in the request (`finance-escape-hatches`, §17.1 rule 4 — `withTrashed()` is
an alias of `withoutGlobalScope(SoftDeletingScope::class)`, and the rule covers `app/Academics` too,
so moving the lookup behind the port would not have escaped it). The refusal was right on the merits:
every roster a bursar picks from already excludes trashed students, so a trashed uuid can only arrive
from a stale client, and **declining to charge a deleted student is the safe direction**. The lookup
dropped `withTrashed()`, the refusal was reworded to say the ids could not be **found** in this school
rather than that they belong to another one, and arm 2d pins it.

---

## 7. Residuals, and what is still open

1. **The across-runs duplicate is untouched.** Two runs raised *sequentially* over the same list are
   still admitted: the first completes, the key goes NULL, the second bills everyone again. Brief §4
   says this is not decided by whoever writes the code, and it has not been.
2. **A stuck claim still has no sweeper**, and a stranded `running` run still holds the School's
   `active_run_key` — now with the endpoint's 422 as the thing an operator will meet ("the button is
   stuck"), which names the run so they can go and read it.
3. **`show` truncates each bucket at 200 rows** with a `truncated` flag, matching the bulk run. On the
   unplaceable bucket that is the one place the report can still hide a name — from row 201.
4. **No index route**, so a bursar who loses the uuid has no list. The 422 names the in-flight run and
   `store` returns the uuid; a list is the screen's requirement and belongs with the screen.
5. **`store` resolves N students in N queries.** Bounded by what a person can tick, and a batch read
   belongs on the port rather than inlined at the caller.
6. **Brief §6's governance question is answered and the answer is recorded, not enforced.** Brookstone
   ruled on 30 August 2026 that this issues directly. Nothing in the code would stop a maker-checker
   being added later; nothing asserts that one is absent either.
