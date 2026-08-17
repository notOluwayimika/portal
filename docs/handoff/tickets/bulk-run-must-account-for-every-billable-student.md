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
