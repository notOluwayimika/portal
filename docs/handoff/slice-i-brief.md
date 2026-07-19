# Slice (i) — `student_curricula.school_id` + composite FKs — brief (design closed 2026-07-19)

Open **fresh**. One-way, four-table, creation-path-touching migration — never a
session tail. All design decisions are closed (roadmap: "Enrollment `school_id` —
two-slice verdict + design decisions CLOSED"); this opens as a **pure build
session with nothing left to decide**.

## Why this exists

An enrollment episode has no School of its own. `student_curricula` carries no
`school_id` and `StudentCurriculum` is globally unscoped (no `BelongsToSchool`, no
`addGlobalScope`). Every consumer re-derives School through scoped relations —
which is what produced Finance slice 2's three-branch resolution
(`students.school_id` → `curricula.school_id` → `0`) and forced cross-School
isolation into application code rather than the schema. The same invariant is
hand-rolled in three places and missing in a fourth.

## Scope

**Column + composite FKs across four tables.** No `BelongsToSchool`. No feature code.

| Table | Change |
|---|---|
| `students` | add `UNIQUE(id, school_id)` (additive; parent for the composite FK) |
| `curricula` | add `UNIQUE(id, school_id)` (additive; same) |
| `student_curricula` | add `school_id`; backfill; composite FK → `students(id, school_id)`; composite FK → `curricula(id, school_id)`; add its own `UNIQUE(id, school_id)` to parent the Finance FK |
| `finance_invoices` | composite FK `(student_curriculum_id, school_id) → student_curricula(id, school_id)` — **separate migration file** (D3) |

Together the two child FKs make "student, curriculum and episode in different
Schools" **unrepresentable**, replacing three hand-rolled checks
(`CurriculumEnrollmentService:34`, `StudentCurriculumController:154`, `:206`) with
one mechanism, and closing the fourth path (`StudentService::update`) that has no
check at all.

## The decisions, already made

- **D1 — win boundary.** The completion report MUST lead with: *"(i) makes
  cross-school episodes unrepresentable at creation and closes none of the
  read-side binding/lookup holes; those are (ii)."* All **9**
  `{studentCurriculum:uuid}` bindings stay unscoped after (i). Do not bank a
  read-side win this slice does not earn.
- **D2 — `students.school_id` is immutable-after-create.** No code path updates it
  (verified). **No `ON UPDATE CASCADE`** on the composite FKs. Also guard the
  immutability — `'school_id'` is in `Student::$fillable` (`Student.php:24`) with
  nothing enforcing it; remove it or add an `updating` guard. A student moving
  School is a new admission (v10 §2.1), and CASCADE would silently rewrite the
  School attribution of every historical billed/graded episode.
- **D3 — the Finance FK rides this slice, in its own migration file.** So the
  two-table denormalization never exists undisciplined, while the Finance coupling
  stays independently reversible.
- **D4 — four tables, not three.** Neither parent has `UNIQUE(id, school_id)`
  today; both need one, and so does `student_curricula` for the Finance child.

## Folded in (not optional)

- `StudentRequest.php:65` and `ImportStudentRequest.php:17` use **unscoped**
  `exists:curricula,id`. Make them School-scoped (`Rule::exists(...)->where('school_id', …)`)
  — otherwise the new FK surfaces as a raw `QueryException` instead of a validation error.
- `StudentService::update` dead guard (see Defects) — the branch it protects is the
  unguarded creation path this slice makes structural.

## Explicitly OUT of scope

- **`BelongsToSchool` on `StudentCurriculum`** — that is slice (ii): a fail-closed
  behaviour change requiring its own per-model rollout (§5.5). It is what closes
  the 9 unscoped bindings and changes the `PrincipalApprovalController:50` mass
  write. Not here.
- **Option B** (`active_key`, terminal statuses, results/scores re-key) — separate
  slice, gates the repeat workflow, not gated on this one.
- The `promotedTo()` fix — belongs with Option-B's promotion-chain slice.

## Pre-flight — the integrity test, NOT a checkbox (deploy-time; nothing claimed from dev)

**Full procedure: [`docs/runbooks/slice-i-preflight-and-remediation.md`](../runbooks/slice-i-preflight-and-remediation.md);
queries: `prod-divergence-and-cascade-queries.sql` §C.**

The backfill copies `school_id` **from the student**, so the student composite FK is
tautologically satisfied and only the **curriculum** composite FK can reject real
data — it fails for every episode where `students.school_id <> curricula.school_id`,
aborting the migration mid-deploy. Those rows are **expected, not hypothetical**:
this slice exists because `StudentService::update`'s dead guard and the unscoped
`exists:curricula,id` were live, and both produce exactly "local student + foreign
curriculum".

1. Run **§C1b** (partition proof), then **§C1** — *list* offenders, don't count them.
2. Remediate to zero per the runbook's decision tree, then re-run §C1.
3. ⚠️ **Dev cannot test this.** It holds **one** School in both `students` and
   `curricula`, so a mismatch is structurally impossible and its zero carries no
   information. Only the mechanics were proven there (agree 977 + disagree 0 =
   total 977, 0 orphans).
4. `finance_invoices` is created **empty** in the same deploy, so its composite FK
   cannot fail at the first Phase-1 deploy — the invoiced-offender case (the
   Finance↔Academic knot) applies only to re-runs / Finance-bearing environments.
5. ⚠️ **`students` prod row count** — the one place the "trivial in dev" rebuild
   estimate may not hold. Check before scheduling.

## Acceptance

- Cross-School episode creation is rejected **by the DB**, bite-proven: attempt a
  raw insert with a mismatched `school_id` and prove the FK rejects it; remove the
  FK and prove the test goes red.
- The three hand-rolled checks are demonstrably redundant (removing one does not
  open a hole, because the FK holds).
- `StudentService::update`'s path is guarded — the previously unguarded
  `updateOrCreate` cannot create a cross-School episode.
- `students.school_id` immutability is enforced, not asserted.
- F1–F4 and slice-2's guards survive: re-run `tests/Feature/Finance` and confirm
  `finance_invoices_active_enrollment_unique` and
  `finance_invoices_total_immutable` still exist by name.

---

## Defects (confirmed 2026-07-19; neither gated on this migration)

**1. `promotedTo()` loads the wrong entity — live correctness bug, currently latent.**
The FK is self-referencing: `promoted_to_id → student_curricula.id`, and the
migration says so outright — *"Self-referencing FK — points to the next
student_curricula row after promotion."* Both internal writers agree
(`StudentCurriculumController:178` → `$new->id`; `BackfillPastTermJob:254` →
`$sourceEnrollment->id`). Wrong on the other side:

| Site | Says | Verdict |
|---|---|---|
| `StudentCurriculum::promotedTo()` `:63-66` | `belongsTo(Curriculum::class, …)` | ❌ wrong entity |
| `StudentRequest.php:66` | `exists:curricula,id` | ❌ wrong table |
| `resources/js/types/models.ts:444` | `promoted_to?: Curriculum` | ❌ wrong type |

**The FK is right; the model, request rule and TS type are wrong.** Latent today —
**0 rows** have `promoted_to_id` set — and it activates on the first promotion
(loading an arbitrary `Curriculum` that shares the integer id), or FK-fails if a
curriculum id is submitted through `StudentRequest`. Fix with the Option-B
promotion-chain slice, which is built on this column.

**2. `StudentService::update` dead guard.** `:118` reads
`$student->studentCurriculum?->curriculum_id`. `Student` has **no**
`studentCurriculum` (singular) relation — only `studentCurricula()` (`:83`) and
`currentCurriculum()` (`:88`). Laravel returns null for an undefined relation, so
`?->` short-circuits and the branch **always fires**. The `updateOrCreate` beneath
it is the one enrollment-creation path with no School check. (`store()` and
`import()` are double-guarded: `Curriculum::findOrFail` under SchoolScope, then
`enroll()`'s explicit check.) Folded into this slice.
