# The bulk run must account for every billable student, not just the ones it queried

**Consumer: U6 commit 3** (the bulk-generation screen). Raised on `feat/u6-cohort-enrollment-port`
(U6 commit 1) by cold review, finding F5.

## The requirement

The bulk-run screen must be able to state **how many billable students in the School were neither
billed nor flagged as unplaceable.** If it cannot, it reports success over a silent omission — the
same defect class (`docs/ui-ux-design-system.md` §26) that `listUnplaceableForSchool()` was added to
close, one level up.

That number must be **zero or displayed**. Not "assumed zero".

## Why the two existing methods do not give it

`App\Finance\Contracts\BillableEnrollmentProvider` currently offers:

- `listForCohort(int $schoolId, int $termId, int $classLevelId)` — billable enrollments at **one**
  named coordinate pair;
- `listUnplaceableForSchool(int $schoolId)` — billable enrollments whose `termId` or `classLevelId`
  is null, so no coordinate pair can ever contain them.

`listUnplaceableForSchool` is the exact complement of `listForCohort`'s filter **for a single call**.
It is not the complement of "every call the caller happened to make". Two shapes fall outside both:

1. **A placeable enrollment at coordinates nobody asked about.** In a School with seven billable
   enrollments, a caller naming one (term, level) pair accounts for at most four; the other three are
   placeable *elsewhere* and no method enumerates them. Only the caller knows which coordinates it
   iterated over, so only the caller can close the gap.
2. **An episode with a NULL `student_id`.** Schema-legal: `student_curricula.student_id` is nullable
   (`information_schema`, 2026-08-17), and MySQL's default **MATCH SIMPLE** skips a composite FK check
   when any component is NULL — so `(NULL, school_id)` satisfies
   `student_curricula_student_school_foreign`. Such a row fails the EXISTS-through-`students` clause
   in `currentEnrollments()` and appears in neither list.

## Why it was not built in commit 1

Building the primitive ahead of its consumer produces an abstraction shaped by imagination rather than
use. Commit 3 is the consumer and it is two commits away; commit 1 corrected the docblock that
overclaimed a partition, and wrote this, rather than shipping a third method with no caller.

## Shape when it is built

Not prescribed. Two candidates, decided by what commit 3's screen actually renders:

- a **reconciliation count** — total billable in School minus (sum of cohorts iterated + unplaceable),
  computed by the caller from a `countBillableForSchool(int $schoolId)`; cheapest, and it answers the
  requirement exactly as stated;
- an **enumeration** — `listBillableNotIn(int $schoolId, array $coordinatePairs)` — more useful for a
  screen that wants to name the students, more surface to get wrong.

Whichever lands must reuse `BillableEnrollmentAdapter::billableEpisodes()`, or it becomes a third
definition of "billable" and re-opens what commit 1's F2 fix closed.

## Acceptance

- The screen displays the unaccounted count, or the count is structurally zero and a test proves it.
- A test seeds a School with billable enrollments at coordinates the run does **not** iterate, and
  asserts the screen does not report unqualified success.
- A test covers the NULL-`student_id` episode specifically — it is the shape no coordinate reasoning
  reaches.

---

## Resolution — 2026-08-19, and the shape this ticket asked for was the WRONG one

Closed by U6 commit 3 (`feat/u6-bulk-invoice-run`), **but not as specified.** The correction is the
point of this addendum, so read it before citing the section above.

### What this ticket asked for

A single school-wide residual: *"total billable in School minus (sum of cohorts iterated +
unplaceable)"*, computed from a `countBillableForSchool(int $schoolId)`, and described as *"cheapest,
and it answers the requirement exactly as stated"*. Commit 3 built precisely that and named the
column `unaccounted_count`.

### Why that was wrong, ruled by the project lead

**A run covers ONE class level.** On a school with seven of them, the residual is roughly
six-sevenths of the roster on **every successful run**. So the number this ticket asked for is large
and meaningless on every healthy run, and a screen rendering it under a name like "unaccounted" would
be telling an operator that hundreds of children were missed, every time, correctly. The one number
that would matter — a student this run actually lost — would be invisible inside it.

It also **mixed three unlike things** into one figure: students priced at coordinates the run did not
name (normal), student-less episodes (a schema shape), and rows the run failed to record (a defect).
Only the third is a signal, and it was the smallest term.

The requirement in the section above — *"how many billable students were neither billed nor
flagged"* — reads as a defect count and is not one. That framing is what this ticket got wrong.

### What replaced it

**Two numbers, of two different kinds, on `finance_bulk_invoice_runs`.**

1. **The run's own accounting — EXACT, and the defect signal.**

   ```
   billed_count + already_billed_count + failed_count == cohort_count
   ```

   Four true headcounts of one set. `cohort_count` is the size of the list `listForCohort()`
   returned; the other three are counted from the rows the run persisted. Independent sources, so
   the equality can genuinely fail — and it fails in exactly one way: a per-student row that could
   not be written. There is no separate "something went wrong" flag, because a flag the job sets is
   a flag the job can forget to set. Asserted, and planted red, in `BulkInvoiceRunTest`.

2. **The school-wide figure — RENAMED, and explicitly not a defect signal.**
   `unaccounted_count` → **`outside_coordinates_count`**, defined as
   `billable_count − cohort_count − unplaceable_count`, and named so nobody reads it as "N children
   were missed". It is subtracted from the LIST SIZES, never from the row counts, so an unrecordable
   row cannot drain out of the equality above into a residual that is large and unalarming.

`countBillableForSchool()` survives, unchanged in shape, as the source of `billable_count` — so the
port method this ticket asked for is the one that shipped. What changed is what the run *does* with
it, and what it is called.

### The caveat that travels with the figure

`billableEpisodes()` takes `MAX(id) GROUP BY student_id` and SQL collapses every NULL into ONE
group, so N student-less episodes contribute 1 between them and `billable_count` is short by
**N − 1** whenever N > 1. The run can detect that such episodes exist; it cannot count them.
Recorded separately as
`docs/handoff/tickets/student-less-episodes-under-report-the-billable-population.md`.

### Against the acceptance criteria above

- *"The screen displays the unaccounted count, or the count is structurally zero and a test proves
  it."* — Superseded. There is no screen yet (commit 4), and the figure a screen will display is
  `outside_coordinates_count`, which is neither zero nor a miss count. What a screen must surface as
  a problem is the **inequality** in (1).
- *"A test seeds a School with billable enrollments at coordinates the run does not iterate, and
  asserts the screen does not report unqualified success."* — Met at the record level: the
  reconciliation test builds all five buckets non-empty, including a student at a class level the run
  does not name, and asserts each bucket's own expected number before asserting either identity.
- *"A test covers the NULL-`student_id` episode specifically."* — Met, and it is the arm that proves
  `countBillableForSchool()` had to be wider than the two list reads (planted red).
