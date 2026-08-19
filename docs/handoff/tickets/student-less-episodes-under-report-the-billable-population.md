# Student-less episodes under-report the billable population by N − 1

**Raised** 2026-08-19, on `feat/u6-bulk-invoice-run` (U6 commit 3), by cold review + the project
lead's ruling on `unaccounted_count`.
**Severity** ticket. Nothing is wrong today at N ≤ 1, which is every environment we have measured.

## The shape

`student_curricula.student_id` is **nullable**, and MySQL's default **MATCH SIMPLE** skips a
composite foreign-key check when any component of the key is NULL — so `(NULL, school_id)` satisfies
`student_curricula_student_school_foreign` and an episode with no student is schema-legal. Raw SQL,
seeders and imports are how such rows arise; `StudentCurriculumObserver` fatals on the model path, so
the model path is not how they appear.

## The two readers, and why they disagree

| Reader | Sees a student-less episode? | Mechanism |
| --- | --- | --- |
| `BillableEnrollmentAdapter::listForCohort()` / `listUnplaceableForSchool()` | **No** | both route through `currentEnrollments()`, which adds an `EXISTS` through `students` on `(student_id, school_id)`; a NULL `student_id` matches no student row and the clause fails |
| `BillableEnrollmentAdapter::countBillableForSchool()` | **Yes, but collapsed** | it deliberately omits that `EXISTS` so the denominator is not blind to what the lists cannot see |

That asymmetry is intentional and is the whole reason the count method exists. The defect is in the
second row's *"but collapsed"*.

## The collapse

`billableEpisodes()` narrows to one episode per student with

```sql
WHERE id IN (SELECT MAX(id) FROM student_curricula WHERE status = 'active' GROUP BY student_id)
```

**`GROUP BY` puts every NULL in ONE group.** So N student-less episodes in a School yield exactly one
row from that subquery, not N. `countBillableForSchool()` therefore returns a figure short by
**N − 1** whenever N > 1, and `finance_bulk_invoice_runs.billable_count` and
`outside_coordinates_count` inherit the shortfall.

Practical effect: the run can *detect* that student-less episodes exist (the residual moves by 1) and
cannot *count* them. Reported as behaviour on
`BillableEnrollmentProvider::countBillableForSchool()`, on `BulkInvoiceRun`, and in the migration.

## Why it is not fixed here

The fix is not local to the count. `MAX(id) … GROUP BY student_id` is the ONE definition of "the
student's current episode" (`billableEpisodes()`), shared with `currentForStudent()` and both list
reads, and its whole job is one-row-per-student. A student-less episode has no student to be current
for, so it does not belong in that subquery at all — the honest repair is to exclude NULL
`student_id` from the tie-break and count those episodes on a separate leg
(`… OR student_id IS NULL`), which changes a shared primitive on behalf of a row shape nothing else
reads. That is a change to make deliberately, with its own tests, not inside a bulk-run commit.

## What would have to be true to make it worth doing

A School with **two or more** student-less episodes. None has been observed. Re-derive before acting:

```sql
SELECT school_id, COUNT(*) AS n
  FROM student_curricula
 WHERE student_id IS NULL AND status = 'active'
 GROUP BY school_id
HAVING n > 1;
```

Zero rows means the shortfall is zero everywhere and this stays a ticket.

## Also worth closing at the same time

Nothing forbids the row. If student-less episodes are never legitimate, the real fix is upstream —
make `student_curricula.student_id` `NOT NULL` — and then every reader agrees for free. That is a
migration with a stray-row pre-flight, and it is the option that removes the question rather than
counting around it.
