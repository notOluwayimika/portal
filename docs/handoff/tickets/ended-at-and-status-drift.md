# `student_curricula.ended_at` and `.status` drift, and two readers disagree about it

Raised on `feat/u6-cohort-enrollment-port` (U6 commit 1) by cold review, finding F7. **Not
hypothetical — measured against the production copy `portaa10_portal` on 2026-08-17.**

## Two independent notions of "this episode is over"

| Reader | Column | Where |
| --- | --- | --- |
| `StudentCurriculum::isActive()` / `isEnded()` | `ended_at` | [app/Models/StudentCurriculum.php:192-195](../../../app/Models/StudentCurriculum.php#L192-L195) |
| the **billable** definition — `currentForStudent()`, `listForCohort()`, `listUnplaceableForSchool()` | `status` | [app/Academics/BillableEnrollmentAdapter.php](../../../app/Academics/BillableEnrollmentAdapter.php) (`billableEpisodes()`) |
| `Student::currentCurriculum` | `status` | `app/Models/Student.php` |

**Nothing ties the two columns.** There is no CHECK constraint, no trigger and no test relating them;
the only CHECK on `student_curricula` is `student_curricula_promoted_requires_link`, which is about
`promoted` / `promoted_to_id`. `softEnd()` writes both together
([app/Services/CurriculumEnrollmentService.php:78-80](../../../app/Services/CurriculumEnrollmentService.php#L78-L80)),
but that is a convention in one method, not a rule — and its own docblock records that the previous
`unenroll()` did not honour it.

## Both directions, and what is actually in the data

Measured on `portaa10_portal` (`SELECT status, COUNT(*), SUM(ended_at IS NULL) ... GROUP BY status`):

| `status` | rows | of which `ended_at IS NULL` |
| --- | ---: | ---: |
| `active` | 921 | 921 |
| `promoted` | 366 | 366 |

**Direction A — terminal status, `ended_at` NULL. 366 rows, live today.** Every `promoted` episode in
the copy has a NULL `ended_at`. So `isEnded()` returns **false** and `isActive()` returns **true** for
all 366, while the billable definition correctly excludes them. Any code branching on `isActive()`
treats a promoted episode as current.

**Direction B — `status = active`, `ended_at` set. 0 rows today, but the code says it was produced.**
`CurriculumEnrollmentService::softEnd()`'s docblock, verbatim:

> Sets the terminal status AND ended_at/ended_by together (previously unenroll set only ended_at,
> leaving status=active — so ended enrollments still read as "current").

A row in this direction is the dangerous one for Finance: `isEnded()` says the episode is over, and
the billable definition says it is **billable** — so bulk generation would invoice a withdrawn
student. None exist in the copy right now; nothing prevents one from being written again, because the
guarantee is a method's habit, not a constraint.

## Why U6 commit 1 did not "fix" it

`listForCohort()` / `listUnplaceableForSchool()` are contractually a mirror of `currentForStudent()`,
which has always filtered on `status` and never consulted `ended_at`. Adding an `ended_at` test to the
cohort reads only would have created a **third** definition of billable — the exact drift that
commit's F2 fix closed in the other direction. The right fix is one rule for the whole table, not a
patch in one reader.

## What to do

Pick one column as authoritative and enforce it, in this order:

1. **Decide the invariant.** Most likely: `status = 'active'` ⟺ `ended_at IS NULL`. Confirm against
   the Option-B episode vocabulary before assuming it (`completed`/`withdrawn`/`repeated`/`promoted`/
   `transferred` are all terminal).
2. **Converge the 366 rows** in a named migration — backfill `ended_at` for terminal statuses. Note
   what timestamp is defensible; `promoted_to_id`'s target episode `created_at` is one candidate.
3. **Enforce it.** A CHECK is the natural mechanism, but production is MySQL 5.7 and ignores CHECK
   (`2026_08_17_100000_maker_checker_and_payment_origin_as_triggers.php` records this) — so a **named
   trigger signalling SQLSTATE 45000** is the mechanism that actually holds, matching the `finance_*`
   immutability pattern.
4. **Then** make `isActive()` / `isEnded()` and the billable definition read the same column, and
   delete whichever is redundant.

Until step 3 lands, this is a convention, and a convention is wallpaper.

## Acceptance

- Zero rows violating the chosen invariant, proven by a query, not by the migration exiting 0.
- The enforcing trigger bite-proved: attempt a violating write, watch it be refused, paste the error.
- A test that the billable definition and `isActive()` agree on every status in `StudentStatusEnum`.
