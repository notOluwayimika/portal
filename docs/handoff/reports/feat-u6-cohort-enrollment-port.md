# Implementation report — the ACL port can name a cohort

Branch `feat/u6-cohort-enrollment-port`, base `eb7506f` (`origin/staging`, PR #257 merged).
U6 commit 1 of four. No user-facing surface, no bulk generation, no job, no screen, no invoice list.

> **Cold review round 1 landed on `346f662`. Ten findings; all ten addressed in the follow-up
> commit. The response is in the *Cold review round 1* section at the end of this file,
> and the sections above it are corrected in place where they were wrong.** Two findings were
> ship-blocking and both were real: the snapshot-relation eager load was unproven (F1), and the
> "one shared base query" the port promised did not exist (F2).

## The brief's isolation premise is stale, and I corrected it rather than executing it

The brief says:

> `student_curricula` has no `school_id` and `StudentCurriculum` does not use `BelongsToSchool`. The
> comment in `BillableEnrollmentAdapter::findByUuid` records that an earlier version of this file
> claimed a `SchoolScope` existed and that it does not.

**Both halves stopped being true before this branch.** Measured, not remembered:

- `student_curricula.school_id` exists and is `NOT NULL`
  ([2026_07_19_130000_add_school_id_to_student_curricula.php:57,:80](../../../database/migrations/2026_07_19_130000_add_school_id_to_student_curricula.php#L57)),
  disciplined by the composite FK `student_curricula_student_school_foreign (student_id, school_id)
  -> students (id, school_id)` (`:83-88`). The engine rejects an episode whose School differs from its
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
([as it stood at `346f662`](https://github.com/notOluwayimika/portal/blob/346f662/app/Academics/BillableEnrollmentAdapter.php#L65-L75); the method is now [:77-89](../../../app/Academics/BillableEnrollmentAdapter.php#L77-L89)):

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

> **CORRECTED after review (F2).** This paragraph originally read "Both are reproduced in a single
> shared private base query" — and *reproduced* was the whole problem. `currentForStudent` did not
> call that base query; it was a second, independent expression of the same rule, with the port
> docblock promising they could not drift. Both now route through **`billableEpisodes()`**, which
> holds `status = ACTIVE` plus `id IN (SELECT MAX(id) … GROUP BY student_id)` and is called by
> `currentForStudent()` directly and by the cohort reads through `currentEnrollments()`. `MAX(id)`
> per student is `latest('id')` expressed as a set. Deleting either clause now turns **both** paths
> red — measured, under *Cold review round 1 · F2*.

**The coordinate filter is applied after the tie-break, never alongside it.** A student whose current
episode is at (t2, c2) but who still holds an older ACTIVE episode at (t1, c1) would otherwise appear
in the (t1, c1) cohort while `currentForStudent` reports (t2, c2) — two definitions disagreeing about
one student. Pinned by *a student holding TWO active episodes is billed once*, which asserts the
cohort membership and `currentForStudent`'s answer against each other directly.

## Withdrawn / soft-ended enrollments: EXCLUDED, by the status column, on one line

**Excluded.** The deciding line is `->where('student_curricula.status', StudentStatusEnum::ACTIVE)`
([:214](../../../app/Academics/BillableEnrollmentAdapter.php#L214), inside `billableEpisodes()`) — the same line `currentForStudent`
uses. Withdrawal sets `status` to `WITHDRAWN`, and `PROMOTED` / `REPEATED` are excluded by the same
line. Asserted for all three non-active statuses in *the cohort filter is exactly currentForStudent's
definition*, which also asserts the equivalence per student against `currentForStudent`.

**`ended_at` is NOT consulted, deliberately.** `StudentCurriculum::isActive()` is
`is_null($this->ended_at)` ([StudentCurriculum.php:192-195](../../../app/Models/StudentCurriculum.php#L192-L195))
— a *second*, independent notion of active. `currentForStudent` has never used it, so neither do
these. Nothing in the schema ties the two columns: no CHECK, no trigger, no test. The only CHECK on
the table is `student_curricula_promoted_requires_link`, on `promoted` / `promoted_to_id`.

> **CORRECTED after review (F7).** I called this "a residual worth naming" and hedged it as
> *"if the two columns can diverge in production"*. They already have. Measured on
> `portaa10_portal` on 2026-08-17: **366 rows with `status = promoted` and `ended_at IS NULL`** —
> every promoted episode in the copy. So `isActive()` returns true for all 366 while the billable
> definition correctly excludes them. The opposite direction (`status = active` with `ended_at`
> set — billable AND ended, which would invoice a withdrawn student) has 0 rows today, but
> `CurriculumEnrollmentService::softEnd()`'s own docblock records the previous `unenroll()`
> producing exactly that shape. Ticket with both directions, both readers and a mechanism:
> [docs/handoff/tickets/ended-at-and-status-drift.md](../tickets/ended-at-and-status-drift.md).

I did not add a third rule here: a read whose contract is "a mirror of `currentForStudent`" is the
wrong place to invent one, and patching one reader is how the drift got here.

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

> **CORRECTED after review (F6).** The next paragraph originally read: *"The `MAX(id)` subquery
> carries the School filter too, so it cannot pick a foreign row that the outer clauses then discard
> — which would drop that student out of the cohort silently."* **That failure mode cannot occur.**
> The composite FK confines every episode of a student to that student's one School, so a student's
> global `MAX(id)` and their in-School `MAX(id)` are the same row and the subquery's school filter
> cannot change a result. Clause 2 is unfalsifiable for the same reason — the FK makes it equivalent
> to clause 1, so no row satisfies one and not the other and no test can separate them. **Only
> clause 1 is falsifiable, and it is** (see the planted red below). Both redundant clauses are kept
> — removing a redundant guard buys nothing — but the comment defending them now says what they are
> honestly worth, because a comment describing an impossible failure teaches the next reader to
> preserve a clause for a reason that is not true.

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
([:288](../../../app/Academics/BillableEnrollmentAdapter.php#L288)): `schoolId()` derives the DTO's
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
construction, rather than four `orWhere` branches that are four chances to forget one.

> **CORRECTED after review (F5).** I claimed the two methods form a **partition** of the billable
> set — "cohorts ∪ unplaceable = the billable set, no gap, no overlap" — and the test was named
> `PARTITION`. It is not one. The negation is exact only against **one** `listForCohort` call, at
> the coordinates that call names. In a seven-enrollment School the two methods account for four:
> three are placeable at coordinates nobody asked about, and no method enumerates them. A second
> shape escapes too — an episode with a NULL `student_id`, which is schema-legal (the column is
> nullable and MySQL's default **MATCH SIMPLE** skips the composite FK check when a component is
> NULL), fails the EXISTS-through-`students` clause and appears in neither list. The docblock and
> the test are corrected; the test now also proves the qualifier is real by skipping one coordinate
> and showing a billable student fall through both methods unreported. The requirement that closes
> it is a ticket naming commit 3 as consumer:
> [docs/handoff/tickets/bulk-run-must-account-for-every-billable-student.md](../tickets/bulk-run-must-account-for-every-billable-student.md).
> No third method was built — commit 3 is its consumer and it is two commits away.

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
| `bin/quality` | **PASS 15/15**, twice: on `346f662` and again on the review-response commit |

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

---

## Cold review round 1

Ten findings against `346f662`. **All ten addressed in one follow-up commit.** F1 and F2 were
ship-blocking and both were correct: the eager load was unproven, and the "one shared base query" the
port promised did not exist. Nothing in the review asked the methods to behave differently and nothing
here changes what they do — except `currentForStudent`, which now reaches the same definition through
the same code, and returns the same rows (see F2).

### F1 · the snapshot relations are now proven

**The finding was right and the exposure was exactly as stated.** Replacing
`->with($this->unscopedSnapshotRelations())` with `->with(self::SNAPSHOT_RELATIONS)` left the suite
10/10 green. `cohortIsolation` structurally cannot catch it: it runs with **no** ambient context,
where `SchoolScope` is a no-op and the scoped and unscoped eager loads issue identical SQL.

The assertion now lives in `isolation holds when the ambient School context is the WRONG one`, the one
test in the file where re-scoping the relations changes an observable value. It asserts the DTO's
`schoolId`, its `studentId`, and that `studentName` has **not** degraded to the `'Student #<id>'`
fallback — three different consequences of the same substitution. The same pair is asserted for
`listUnplaceableForSchool`, which shares `currentEnrollments()` and had no coverage at all.

**Planted** — the exact substitution named in the finding:

```text
tests: 12, passed: 11, failed: 1
✗ isolation holds when the ambient School context is the WRONG one
  tests/Feature/Finance/CohortEnrollmentPortTest.php:260
  Failed asserting that 0 is identical to 5.
```

`0` is the DTO's `schoolId`; `5` is `school#5`. That is the finding's predicted failure verbatim — an
invoice attributed to no School. Restored: **12 passed, 56 assertions**.

### F2 · "one shared base query" was false, and is now true

**Correct, and worse than stated in one respect**: the port docblock asserted the two could not drift
*because* they shared a base query, and the sharing did not exist. `currentForStudent` spelled the
rule out inline; the cohort base spelled it out again.

The definition now lives in **one** private method, `billableEpisodes(?int $schoolId = null)` —
`status = ACTIVE` plus `id IN (SELECT MAX(id) … GROUP BY student_id)`. `currentForStudent()` calls it
directly; `currentEnrollments()` calls it and adds isolation and eager loading. The two clauses exist
exactly once in the class.

**`currentForStudent`'s behaviour is unchanged, and I checked rather than assumed** — the finding said
to stop and report if it changed. It does not, for one reason: the composite FK
`student_curricula_student_school_foreign` confines every episode of a student to that student's one
School, so the subquery's un-scoped `MAX(id)` and the old `latest('id')` under the ambient scope
resolve to the same row. `latest('id')` is dropped rather than kept, because `billableEpisodes()`
already admits at most one row per student — with `student_id` pinned the result set has at most one
member — and re-adding it would restore the second copy of the tie-break. `OpeningBalanceImportTest`,
which drives `currentForStudent` through the port, is green (49 passed, 277 assertions across both
files).

`currentForStudent` keeps the ambient `SchoolScope`; the cohort reads still strip it. Isolation was
deliberately **not** unified — see F3.

**Planted** — the tie-break deleted from `billableEpisodes()`, so both callers lose it at once:

```text
tests: 12, passed: 10, failed: 2

✗ a student holding TWO active episodes is billed once, for the one currentForStudent returns
  tests/Feature/Finance/CohortEnrollmentPortTest.php:328
  Failed asserting that two arrays are identical.
  --- Expected
  +++ Actual
  -Array &0 []
  +Array &0 [
  +    0 => 12,
  +]

✗ currentForStudent applies the SAME tie-break, and fails on its own when it is removed
  tests/Feature/Finance/CohortEnrollmentPortTest.php:362
  Failed asserting that 15 is identical to 16.
```

Two independent reds, one per code path. The first is the **double bill**: student#12 appears in the
first level's cohort as well as the second, so commit 2 raises two invoices. The second is the
**single-invoice path** returning enrollment 15 — the older episode — where 16 is current.

The second test is new and exists solely so the plant produces two reds. The pre-existing test dies on
its *first* assertion (the cohort one) and never reaches its `currentForStudent` check, so on its own
it could not show the coupling. Restored: **12 passed, 56 assertions**.

### F3 · the equivalence is conditional, and now says so

F2's fix does **not** resolve it, as the finding anticipated. `currentForStudent` keeps the ambient
scope and takes no School; the cohort reads take one and strip it. Unifying them would mean changing
isolation on the live single-invoice path, which is out of scope for this commit.

So the port states the condition instead of asserting a biconditional. The equivalence holds when the
ambient School is `$schoolId` **or absent** — which covers every caller that exists (a `SchoolAware`
job under `runFor`, or a request already in that School) — and the docblock names the foreign-context
case as the seam where it breaks. The interface docblock now splits every method into **AMBIENT** and
**ARGUMENT** isolation rather than making one blanket claim.

### F4 · both stale comments fixed

`BillableEnrollmentAdapter.php`'s `toBillableEnrollment()` said *"The enrollment row carries NO
school_id (see above)"* while "see above" said the opposite — the trap left half-closed. Rewritten:
deriving the School from the student is still correct and still deliberate (the FK makes the values
equal, so this reads the durable identity rather than the denormalised copy), and the comment says
that instead of a false schema claim.

`BillableEnrollmentProvider.php`'s header said the `SchoolScope` "already constrains visibility, so
cross-School lookups return null by construction" — false for the two methods that strip it by
design, and **this is the sentence that misled the brief**. Replaced with the per-method AMBIENT /
ARGUMENT split, including the point that a filter turns a wrong-School read into "not found" and never
into a refusal.

### F5 · the totality claim is withdrawn, ticket written

Both shapes named in the finding confirmed: `student_curricula.student_id` **is** nullable
(`information_schema`), so a NULL-`student_id` episode is schema-legal under MATCH SIMPLE and falls
out of both lists. Docblock and test corrected as described above; no third method built.

Ticket: [docs/handoff/tickets/bulk-run-must-account-for-every-billable-student.md](../tickets/bulk-run-must-account-for-every-billable-student.md)
— names commit 3 as consumer and states the requirement as given: the bulk-run screen must be able to
say how many billable students were neither billed nor flagged, or it reports success over a silent
omission.

### F6 · the redundant clauses are kept, the comment defending them is corrected

Both clauses kept. The comment now grades all three isolation clauses honestly: clause 1 is THE
constraint and is falsifiable; clause 2 is unfalsifiable (FK-equivalent to clause 1); clause 3 cannot
change a result at all. The failure mode the old comment invoked — the subquery "picking a foreign row
and leaving the student out of the cohort" — does not exist, and saying so is the point.

### F7 · ticket written, and the drift is measured, not hypothesised

Verified both halves of the finding. `CurriculumEnrollmentService::softEnd()`'s docblock records the
previous `unenroll()` setting only `ended_at`, and `portaa10_portal` holds **366 `promoted` rows with
`ended_at IS NULL`** — confirmed by query, not quoted from the review.

Ticket: [docs/handoff/tickets/ended-at-and-status-drift.md](../tickets/ended-at-and-status-drift.md)
— both directions with live counts, all three readers, and a mechanism (a named trigger signalling
SQLSTATE 45000, since production is MySQL 5.7 and ignores CHECK) rather than a convention.

### F8 · absolute counts asserted; the unplaceable number is NOT 8, and I was wrong about it

The cohort test now asserts **8** as well as flatness — `$large === $small` passes for any constant,
including a constant 40.

**I asserted 8 for `listUnplaceableForSchool` too and it failed at 4.** The count is *data*-shaped,
not only code-shaped: Laravel skips an eager-load query entirely when every parent key for it is null,
and an unplaceable row is by definition one whose coordinate keys are null. Measured:

| fixture | rows | queries |
| --- | ---: | ---: |
| all-null coordinates | 30 | 4 |
| plus one row with a real arm carrying a null `class_level_id` | 31 | 7 |

So a fixed number there would pin the fixture, not the code. The test asserts what belongs to the
code: flat in the number of students, the measured 4 for the all-null shape, strictly more for the
mixed shape, and never above the structural ceiling of `1 + count(SNAPSHOT_RELATIONS) = 8`.

### F9 · lint extended to `app/Academics/`, and it caught exactly this branch

`finance-escape-hatches` was gated on `str_starts_with($rel, 'app/Finance/')`, so six
`withoutGlobalScope` calls in `app/Academics` were invisible to it — the boundary could be walked
around by moving code one directory over. Predicate extended to cover `app/Academics/` too.

**What it caught — three findings, all in the file this branch wrote, and nothing else:**

```text
boundary-lint: 3 NEW boundary violation(s):
  ✗ finance-escape-hatches  app/Academics/BillableEnrollmentAdapter.php  $query->withoutGlobalScope(SchoolScope::class)
  ✗ finance-escape-hatches  app/Academics/BillableEnrollmentAdapter.php  $unscoped = fn ($query) => $query->withoutGlobalScope(SchoolScope::class);
  ✗ finance-escape-hatches  app/Academics/BillableEnrollmentAdapter.php  ->withoutGlobalScope(SchoolScope::class)
```

**No unrelated pre-existing file lit up**, so the extension stays — `app/Academics/` contains exactly
one PHP file, the adapter. The extension is therefore not yet load-bearing over anything but this
branch's own code; it becomes so as the Academics seam fills.

The three lines are baselined with their justification in the lint's own comment. Baseline entries
went 4 → 7; nothing was removed (`diff` shows additions only, plus header comment lines the previous
baseline predated). **A limitation worth recording rather than hiding:** the baseline keys on the
*trimmed line text*, so a seventh call whose line reads byte-identically to a baselined one would be
admitted silently. That is a property of this lint's design, not of this change — and copy-paste is
the most likely way a seventh one ever appears, which is precisely the case it lets through. Ticketed
in a follow-up commit:
[docs/handoff/tickets/boundary-lint-baseline-keys-on-line-text.md](../tickets/boundary-lint-baseline-keys-on-line-text.md).

### F10 · `bin/quality`

Run on this commit: **PASS 15/15** — recorded in the Gates table above rather than deferred.

### Trivia

`isActive()` is `StudentCurriculum.php:192-195`, not `:193` — corrected. The migration's FK statement
spans `:83-88`, not `:86` — corrected.

---

## Follow-up: the two by-student routes, and a hole in the lint baseline

Post-review, on top of `2b6c79a`. The re-check confirmed F1 and F2 closed and established that
`currentForStudent` is null under a foreign ambient context at both shas, by the ambient `SchoolScope`
that `billableEpisodes()` does not strip. Two gaps followed from that.

### The refusal on the two routes that reach `currentForStudent` was untested

`InvoiceController::billableEnrollment` and `::generateForStudent`
([routes/endpoints/finance.php:228-229](../../../routes/endpoints/finance.php#L228-L229)) are the two
routes that call the port's ambient-scoped read, and both bind `{student:uuid}`. Driven with School
A's token and a School B student uuid carrying an active enrollment, both answer **404**. Nothing in
the tree asserted that. `tests/Feature/Finance/ByStudentRouteIsolationTest.php` now does.

**Each refusal is paired with the same request succeeding for the owning School.** Without that
control the test measures the URL, not the isolation — a misspelled path, a missing permission or an
unregistered route all produce a 404 and the test would pass regardless. The POST arm asserts the
invoice tables are empty after the refusal (both of them, since invoice and lines are written in one
transaction), and asserts the control's write landed in the owning School.

**The status is asserted; the message is not.** The refusal is Laravel's route-model binding, so its
wording is framework copy the project does not own.

### Planted — permissive binding, controller untouched

The finding named the plant: resolve the Student without the School scope. Done with an explicit
`Route::bind('student', …)` in the test's `beforeEach`, which overrides implicit binding for
`{student:uuid}` and hands School B's model to the controller under School A's token. No controller
or route file was modified.

```text
tests: 2, passed: 0, failed: 2

✗ GET billable-enrollment refuses a foreign student, and serves its own
  Expected response status code [404] but received 422.
  Failed asserting that 422 is identical to 404.

✗ POST invoices refuses a foreign student and writes nothing, and bills its own
  Expected response status code [404] but received 422.
  Failed asserting that 422 is identical to 404.
```

Both arms red, and the status they moved to is the useful part: **422, not 201.** With the outer
refusal removed the request reaches the controller and the SECOND layer catches it —
`currentForStudent()`'s ambient `SchoolScope` finds no episode for a foreign student and the
controller returns "no active enrollment to bill". So the two routes are refused twice over, and this
file pins the outer refusal specifically.

One precision, since the plant run stopped at the status assertion: **no invoice was written under
the plant either**, but that is read off the code path — `InvoiceController::generateForStudent`
returns the 422 before `GenerateInvoice::handle` is reached — not measured by an assertion that ran.
The unplanted green run does measure it.

Restored: **2 passed, 10 assertions.**

### The lint baseline can be walked past

Ticketed rather than fixed here:
[docs/handoff/tickets/boundary-lint-baseline-keys-on-line-text.md](../tickets/boundary-lint-baseline-keys-on-line-text.md).
`bin/ci-boundary-lint.php` keys findings on `rule \t path \t trim($line)`, so a fourth
`withoutGlobalScope` whose line reads byte-identically to a baselined one produces a key that is
already present and is admitted with no new entry. Copy-pasting the neighbouring closure is the most
likely way that fourth call ever gets written — so the rule's whole value, that the next escape hatch
must be argued for, is exactly what leaks. Every keyed rule in the file shares the hole; the two
zero-baseline rules are unaffected in practice. The proposed fix is an occurrence count in the key,
which keeps the "may only shrink" semantics, and explicitly not a line number, which would fail on
every unrelated edit and train people to regenerate the baseline reflexively.
