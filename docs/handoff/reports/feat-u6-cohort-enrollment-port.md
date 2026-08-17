# Implementation report — the ACL port can name a cohort

Branch `feat/u6-cohort-enrollment-port`, base `eb7506f` (`origin/staging`, PR #257 merged).
U6 commit 1 of four. No user-facing surface, no bulk generation, no job, no screen, no invoice list.

## The brief's isolation premise is stale, and I corrected it rather than executing it

The brief says:

> `student_curricula` has no `school_id` and `StudentCurriculum` does not use `BelongsToSchool`. The
> comment in `BillableEnrollmentAdapter::findByUuid` records that an earlier version of this file
> claimed a `SchoolScope` existed and that it does not.

**Both halves stopped being true before this branch.** Measured, not remembered:

- `student_curricula.school_id` exists and is `NOT NULL`
  ([2026_07_19_130000_add_school_id_to_student_curricula.php:57,:80](../../../database/migrations/2026_07_19_130000_add_school_id_to_student_curricula.php#L57)),
  disciplined by the composite FK `student_curricula_student_school_foreign (student_id, school_id)
  -> students (id, school_id)` (`:86`). The engine rejects an episode whose School differs from its
  student's.
- `StudentCurriculum::booted()` registers `new SchoolScope` ([StudentCurriculum.php:78](../../../app/Models/StudentCurriculum.php#L78)).
  It is the bare scope, not `BelongsToSchool`, and the model's own docblock explains why (the trait's
  `creating` hook would fill `school_id` from ambient context and make an episode's School a function
  of who is logged in).

So the adapter comment the brief cites has now been **wrong in both directions** — slice 2 claimed a
scope that did not exist, the correction claimed no column and no scope, and slices (i)/(ii) made the
correction wrong too. I rewrote it to state today's fact with its citations
([BillableEnrollmentAdapter.php:35-50](../../../app/Academics/BillableEnrollmentAdapter.php#L35-L50)).

**This did not make the brief's demand unnecessary — it made it more necessary**, and the design
below turns on that. See *Isolation*.

## What "billable" resolves to

Quoted from `currentForStudent`, the only prior definition
([BillableEnrollmentAdapter.php:65-75](../../../app/Academics/BillableEnrollmentAdapter.php#L65-L75)):

```php
$enrollment = StudentCurriculum::query()
    ->where('student_id', $studentId)
    ->where('status', StudentStatusEnum::ACTIVE)
    ->with(self::SNAPSHOT_RELATIONS)
    ->latest('id')
    ->first();
```

Billable is **two** things, not one, and reusing only the first would have been the drift the brief
warned about:

1. **the filter** — `status = StudentStatusEnum::ACTIVE`;
2. **the tie-break** — `latest('id')`, i.e. at most one row per student.

Both are reproduced in a single shared private base query, `currentEnrollments(int $schoolId)`
([:185](../../../app/Academics/BillableEnrollmentAdapter.php#L185)), which both new methods build on:
`status = ACTIVE`, plus `id IN (SELECT MAX(id) ... GROUP BY student_id)`. `MAX(id)` per student is
`latest('id')` expressed as a set.

**The coordinate filter is applied after the tie-break, never alongside it.** A student whose current
episode is at (t2, c2) but who still holds an older ACTIVE episode at (t1, c1) would otherwise appear
in the (t1, c1) cohort while `currentForStudent` reports (t2, c2) — two definitions disagreeing about
one student. Pinned by *a student holding TWO active episodes is billed once*, which asserts the
cohort membership and `currentForStudent`'s answer against each other directly.

## Withdrawn / soft-ended enrollments: EXCLUDED, by the status column, on one line

**Excluded.** The deciding line is `->where('student_curricula.status', StudentStatusEnum::ACTIVE)`
([:195](../../../app/Academics/BillableEnrollmentAdapter.php#L195)) — the same line `currentForStudent`
uses. Withdrawal sets `status` to `WITHDRAWN`, and `PROMOTED` / `REPEATED` are excluded by the same
line. Asserted for all three non-active statuses in *the cohort filter is exactly currentForStudent's
definition*, which also asserts the equivalence per student against `currentForStudent`.

**`ended_at` is NOT consulted, deliberately.** `StudentCurriculum::isActive()` is
`is_null($this->ended_at)` ([StudentCurriculum.php:193](../../../app/Models/StudentCurriculum.php#L193))
— a *second*, independent notion of active. `currentForStudent` has never used it, so neither do
these. **This is a residual worth naming**: a row with `status = ACTIVE` and a non-null `ended_at`
would be billable to both code paths and "ended" to `isActive()`. Nothing in the schema forbids that
pair (no CHECK ties them; the only CHECK on the table is
`student_curricula_promoted_requires_link`, on `promoted`/`promoted_to_id`). I did not add a third
rule here, because a read whose contract is "a mirror of `currentForStudent`" is the wrong place to
invent one. **Ticket**, not a blocker for this commit: if the two columns can diverge in production,
that is a fact about the withdraw path, not about this port.

**Soft-deleted students are INCLUDED**, again matching `currentForStudent`, which queries
`student_curricula` and never touches `students`. The `EXISTS` clause I added is written to ignore
`deleted_at` for exactly that reason. Note the eager-loaded `student` relation still applies
`SoftDeletes`, so such a row's DTO falls back to `'Student #<id>'` for the name and to the
curriculum's `school_id` for the School — which, given the composite FK, is the right School, not 0.
**Whether bulk generation should invoice a soft-deleted student is commit 2's question**, at the
point an invoice is actually raised.

## Isolation — explicit, doubled, and the ambient scope deliberately stripped

`currentEnrollments()` constrains `$schoolId` twice:

1. `student_curricula.school_id = $schoolId` — FK-backed, per above;
2. `EXISTS (SELECT 1 FROM students WHERE students.id = student_curricula.student_id AND
   students.school_id = $schoolId)` — "through the student", as the brief asked by name. Redundant
   today; it is the clause that survives the FK being dropped.

The `MAX(id)` subquery carries the School filter too, so it cannot pick a foreign row that the outer
clauses then discard — which would drop that student out of the cohort silently.

**And `withoutGlobalScope(SchoolScope::class)` is applied, at every level including the nested
relation closures.** That is a removal I chose, so here is the reasoning rather than the assertion:

A method whose School is an **argument** must not also carry a second, ambient opinion about which
School it is reading. When the two disagree the intersection is empty — and an empty cohort is not an
error anywhere. Bulk generation would raise zero invoices and report success. That is the same
silent-partial-result defect class as billing 47 of 50 (`docs/ui-ux-design-system.md` §26), in its
total form. Keeping the ambient scope as "defence in depth" buys nothing in the correct case (in a
`SchoolAware` job under `runFor($schoolId)` the two agree by construction) and converts a wrong-School
bug from *visible leak* into *invisible nothing*. So the argument is made authoritative and the tests
prove it holds alone. `isolation holds when the ambient School context is the WRONG one` asserts both
failure modes at once: School A's cohort under School B's ambient context returns A's student and
only A's student.

The snapshot relations are eager-loaded unscoped for the same reason
([:222](../../../app/Academics/BillableEnrollmentAdapter.php#L222)): `schoolId()` derives the DTO's
School from the eager-loaded student, then the curriculum, then falls back to **0**. Under a
disagreeing ambient context both relations would resolve null and every DTO would carry `schoolId 0`,
which commit 2 would stamp onto an invoice. The `cohortIsolation` test asserts `$enrollment->schoolId`
per row for that reason, not only the student ids.

## The planted red — and the first plant caught my own test, not the code

This is the part worth reading.

**Plant:** deleted all three School constraints from `currentEnrollments()` — the `school_id` column
clause, the `EXISTS` through `students`, and the `school_id` filter inside the `MAX(id)` subquery —
leaving only `status = ACTIVE` and the tie-break.

**First run — 1 of 2 isolation tests went red:**

```text
tests: 10, passed: 9, failed: 1
✗ unplaceableIsolation: School B's unplaceable enrollments are not in School A's list
    Failed asserting that two arrays are identical.
    --- Expected
    +++ Actual
     Array &0 [
         0 => 3,
    +    1 => 4,
     ]
```

`cohortIsolation` **stayed green under the plant**, and that is a defect in the test, not a pass. I
had built School B's student on School B's own term and arm — so the ids differed, and the
*coordinate* filter did the excluding while the School constraint was never exercised. A green there
would have been a claim dressed as a proof: the exact thing the plant exists to expose, found on the
first attempt.

**Fix:** School B's student now sits on a School B curriculum pointing at **School A's own `term_id`
and arm id**. Nothing in the schema prevents that collision — `curricula_term_id_foreign` and
`fk_curricula_class_level_arm_id` are both SINGLE-column FKs (`information_schema`, 2026-08-17), so a
curriculum may legally reference another School's term and arm. After the fix, `school_id` is the
only thing separating the two students.

**Second run of the same plant — all three isolation tests red:**

```text
tests: 10, passed: 7, failed: 3
✗ cohortIsolation: a School B student at School A's exact coordinates is not in School A's cohort
    Array &0 [ 0 => 1, +    1 => 2, ]
✗ unplaceableIsolation: School B's unplaceable enrollments are not in School A's list
    Array &0 [ 0 => 3, +    1 => 4, ]
✗ isolation holds when the ambient School context is the WRONG one
    Array &0 [ 0 => 5, +    1 => 6, ]
```

Each failure is the leak itself: School B's student appended to School A's result. Constraints
restored, `tests: 10, passed: 10, assertions: 36`.

## `listUnplaceableForSchool` — one negation, not four cases

`whereDoesntHave('curriculum', <placeable>)` is `NOT EXISTS` over a `belongsTo`, so it is true when
the curriculum is missing entirely, when `term_id` is null, when `class_level_arm_id` is null, and
when the arm's `class_level_id` is null. It is the exact complement of `listForCohort`'s filter by
construction, rather than four `orWhere` branches that are four chances to forget one. Asserted as a
**partition**: cohorts ∪ unplaceable = the billable set, with no gap and no overlap.

All four shapes are covered, plus the arm with a null `class_level_id` — the hop an `orWhere` chain
forgets. Withdrawn students are in neither list, which matters for the screen: a withdrawn student is
not a placement failure and must not be counted as one.

**No new field on `BillableEnrollment`.** The reason is already readable off the DTO — `termId ===
null`, `classLevelId === null`, or both — and the port promises commit 3 exactly that, with a test
asserting the promise holds. Adding a field no consumer needs would be the adapter inventing
vocabulary, which the brief forbade and which I agree with.

## Query cost — measured, 8, flat

Measured with `DB::getQueryLog()` around a 30-student cohort (throwaway probe, removed):

```text
COHORT SIZE: 30  QUERIES: 8
1) select * from student_curricula where school_id = ? and exists (select 1 from students ...)
     and status = ? and id in (select MAX(id) from student_curricula where school_id = ? and status = ? group by student_id)
     and exists (select * from curricula where ... and term_id = ? and exists (select * from class_level_arms where ... and class_level_id = ?))
     order by student_curricula.student_id asc
2) select * from students where students.id in (30 ids) and students.deleted_at is null
3) select * from curricula where curricula.id in (30 ids)
4) select * from class_level_arms where class_level_arms.id in (1 id)
5) select * from class_levels where class_levels.id in (1 id)
6) select * from arms where arms.id in (1 id)
7) select academic_sessions.*, ... inner join terms ... where terms.id in (?)
8) select * from terms where terms.id in (1 id)
```

**Eight queries: one root + seven eager loads, exactly the seven paths `SNAPSHOT_RELATIONS`
declares.** Not N+1 — queries 2-8 are batched `whereIn`s over the whole page. Pinned by a test that
runs the read at cohort size 3 and again at 30 and asserts the count is **identical**, so an
accidental lazy load in a later commit fails rather than merely slows.

I did not optimise beyond what `SNAPSHOT_RELATIONS` already eager-loads, per the brief. Two honest
notes for commit 2, neither actioned here:

- **This is a whole-School read per call and commit 2 loops it.** Eight queries per cohort × N class
  levels. That is fine at a school's real class-level count and would not be fine as a per-student
  loop; the shape is already right.
- The root query's `IN (SELECT MAX(id) ... GROUP BY student_id)` is a dependent-free derived set and
  MySQL materialises it. I did not read a live `EXPLAIN` against a production-sized table — the
  measurement above is a query **count** on a test fixture, and I am not claiming a plan.

## Gates

| Gate | Result |
| --- | --- |
| `pest tests/Feature/Finance/CohortEnrollmentPortTest.php` | 10 passed, 36 assertions |
| `pest --group=arch` | **32 passed, 181 assertions** (2 pre-existing warnings, unrelated: duplicate constants in `tests/Feature/Rbac/ForcingMigrationsDoNotStripLaterGrantsTest.php`) |
| `bin/ci-boundary-lint.php` | OK — no new violations (4 known temporary exceptions) |
| `bin/ci-authz-lint.php` | OK — 0 known |
| `bin/quality` | see the branch's final run |

**The arch boundary holds and was never at risk of not holding**: all academic reading stays in
`App\Academics\BillableEnrollmentAdapter`, and no Finance file gained an import. The rule that would
have caught a violation is *Finance does not reach into Academics models*
([tests/Arch/ArchitectureBoundaryTest.php:48-55](../../../tests/Arch/ArchitectureBoundaryTest.php#L48-L55)),
live because `app/Finance` exists. I did not bite-prove it on this branch — it is a pre-existing rule
this change does not touch, and manufacturing a violation to watch it fail would be theatre. Its
enforcement is a claim I am inheriting, not one I am making.

`withoutGlobalScope` is used in `app/Academics`, not `app/Finance`; the `finance-escape-hatches` lint
rule is scoped to `app/Finance/` ([bin/ci-boundary-lint.php:131](../../../bin/ci-boundary-lint.php#L131))
and the lint confirms no new finding.

## Two defects found in passing, neither this commit's to fix

1. **`StudentCurriculumObserver::created()` fatals on a legal row.** `curriculum_id` is nullable
   (`information_schema`), but creating such a row through the model reaches
   `StudentSubjectService::autoAttachCompulsorySubjects()`, which calls `->curriculumSubjects()` on a
   null curriculum: `Call to a member function curriculumSubjects() on null`
   ([StudentSubjectService.php:35](../../../app/Services/StudentSubjectService.php#L35)). The observer's
   own docblock names raw SQL / seeders / imports as how these rows arise — so the shape is real and
   the model path cannot produce it. The test builds that fixture with a raw insert for exactly this
   reason, with the reason written at the fixture. **Ticket.**
2. **The stale comment class.** The `findByUuid` note has been wrong twice, in opposite directions,
   because it restated schema facts in prose with no mechanism to notice when the schema moved. I
   rewrote it with file:line citations, which makes it checkable but not enforced. **Ticket**, and
   the general form is the wallpaper principle: a comment about a column is a wish, a test about a
   column is a rule.

## What this commit deliberately does not contain

No bulk-generation job, no screen, no invoice list, no `InvoiceStatus::Draft` (U6 ships without the
draft batch; invoices are issued directly — ruling not revisited). `listUnplaceableForSchool` has **no
caller yet**, which is the one place this branch knowingly front-loads a primitive ahead of its
consumer; the brief made that call explicitly and named the consumer (commit 3's "N students could not
be placed"), and the consumer is named in the port's own docblock so it cannot be quietly dropped.
