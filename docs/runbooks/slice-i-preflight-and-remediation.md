# Slice (i) deploy pre-flight & remediation — cross-School enrollment episodes

**Status:** plan only. Nothing here has been run against production; the detection
queries were validated for *mechanics* on the dev DB, which cannot exercise the
condition they look for (see "Why dev proves nothing" below).

Companion queries: `docs/runbooks/prod-divergence-and-cascade-queries.sql` §C.

---

## 1. What can actually fail

Slice (i) backfills `student_curricula.school_id` **from `students.school_id`**, then
adds composite FKs. Only one of them can reject real data:

| Constraint | Can fail? | Why |
|---|---|---|
| `(student_id, school_id) → students(id, school_id)` | **No** | Tautological — `school_id` was copied from that student. A NULL `students.school_id` is caught first by the migration's own explicit guard. |
| `(curriculum_id, school_id) → curricula(id, school_id)` | **YES** | Fails for every episode where `students.school_id <> curricula.school_id`. |
| `finance_invoices (student_curriculum_id, school_id)` | **No, at first deploy** | `finance_invoices` is *created empty* by the slice-2 migration in the same deploy. Live only on a re-run or where Finance data already exists. |
| `UNIQUE(id, school_id)` on the three parents | **No** | `id` is the PK, so the pair is unique by construction. |

**The entire slice-(i) deploy risk is one query (§C1).**

These rows are **not hypothetical**. Slice (i) exists *because* cross-School
enrollment paths were live and unguarded: `StudentService::update`'s dead guard
(`$student->studentCurriculum` is not a relation, so it is always null) and the
unscoped `exists:curricula,id` in `StudentRequest` / `ImportStudentRequest`. Those
paths produce exactly "local student + FOREIGN curriculum" — the C1 shape. **Finding
offenders is the expected outcome; finding zero is the surprise.**

## 2. Why dev proves nothing here

The dev DB holds **one** school in both `students` and `curricula` (611 students, 46
curricula, 977 episodes), so a mismatch is *structurally impossible*. C1 returns 0
there, and that zero carries no information about prod.

What dev *did* prove is the mechanics: `agree(977) + disagree(0) = joined(977) =
episodes(977)`, and 0 episodes fail to join either parent. The joins and the
comparison are correct and nothing is being silently dropped. Run **C1b** in the same
session on prod to re-establish that before trusting C1's number.

## 3. Determining the correct School — the student's is authoritative

When C1 flags a row, the student's School and the curriculum's School disagree, and
the data alone does not say which is "right." **The rule is: the student's School is
authoritative; the curriculum reference is what gets fixed. Never rewrite
`students.school_id`.** Four reasons:

1. `students.school_id` is **immutable-after-create** (D2) and is the value the
   backfill uses; the model now refuses to change it.
2. Student identity is School-scoped: `UNIQUE(school_id, admission_number)`. Moving a
   student between Schools would collide or force renumbering.
3. The **observed defect shape** is "foreign curriculum attached to a local student" —
   both known unguarded paths pick a *curriculum* from an unscoped list. Nothing in
   the codebase mis-files a student's School.
4. Financial history follows the student: invoices reference `student_id` and derive
   School from `students.school_id`. Rewriting it would relocate a student's ledger.

## 4. Remediation decision tree (per offending episode)

⚠️ **Ending the episode does NOT remediate it.** The FK constrains `curriculum_id`
regardless of `status` or `ended_at`. Only re-pointing or removing the row satisfies it.

Use C1's `subject_rows` / `behavioural_rows` / `psychomotor_rows` columns and C2 to
classify:

**(a) Childless, uninvoiced** — no subjects, assessments, psychomotor rows, no invoice.
→ Simplest case. Either delete the episode, or re-point `curriculum_id` to the
equivalent curriculum in the student's School. Equivalence is the natural tuple from
`curricula_unique_key`: `(school_id, class_level_arm_id, term_id, exam_type_id, is_ccm)`.
If the student's School has no equivalent curriculum, the enrollment was simply
invalid — delete it.

**(b) Has subjects, no grades, uninvoiced** — re-pointing is **not a one-column UPDATE**.
`student_subjects.curriculum_subject_id` points at the *old* curriculum's
`curriculum_subjects`, so after re-pointing the episode would sit in curriculum A′
while studying B's subjects. Each `student_subject` must be re-mapped to the
equivalent `curriculum_subject` in A′ (match on `subject_id`); any subject A′ does not
offer must be dropped. Note `student_subjects.student_curriculum_id` is
RESTRICT/NO ACTION, so the episode cannot be deleted while they exist.

**(c) Has grades (`student_results` / `scores`)** — **escalate; do not fix in SQL.**
These are keyed on `(student_id, curriculum_subject_id)`, *not* on the episode, so
re-mapping subjects mis-keys or orphans the grades. This is entangled with Option B's
pending results/scores re-key to `student_curriculum_id` — remediating a graded
cross-School episode before that re-key lands means hand-migrating grade rows whose
key is about to change. **If C1 returns graded offenders, that is its own slice, and
it should be sequenced against Option B rather than rushed ahead of this deploy.**

**(d) Invoiced (C2 returns rows)** — **its own slice, crossing the Finance↔Academic
seam.** Not fixable by data edit:
- `finance_invoices` is DELETE-denied by trigger and its money columns are
  UPDATE-denied (F4/F6). The row cannot be removed or silently corrected.
- The invoice's `academic_context` is a deliberate **snapshot** — per
  `docs/finance-data-ownership.md` it is *correct* for it to describe the curriculum
  as billed, so re-pointing the episode does not invalidate it.
- If the billed amount was wrong *because* the curriculum was wrong, the only
  sanctioned correction is **VOID + re-issue** through the normal Finance workflow
  (reversing ledger entry, approval, audit) — an accounting operation, not a fix-up.
- The invoice's own `school_id` derives from `students.school_id`, the same value the
  episode is backfilled with, so an invoiced offender does **not** additionally break
  the Finance composite FK.

**At the first Phase-1 deploy, case (d) cannot occur** — `finance_invoices` is created
empty in that same deploy. The Finance knot is real but **not present at the deploy
being planned**; it applies to re-runs and to environments that already hold Finance
data.

## 5. Escalation

If C1 returns more than a handful of offenders, or any in class (c)/(d), **do not
remediate inline during the deploy window.** Treat it as a data-cleanup slice of its
own, run before the migration, with its own review. The migration is not urgent; a
rushed grade or ledger repair is irreversible in ways the migration is not.

## 6. Procedure

1. Run **§C1b** (partition proof), then **§C1**. Where Finance data already exists,
   also run **§C2**.
2. If C1 returns zero *and* C1b's partition holds → the migration can run.
3. If C1 returns rows → **STOP.** Classify each with §4, remediate to zero, re-run C1,
   then migrate.
4. Only then run the slice-2 migrations followed by the slice-(i) pair (see the
   migration-order dependency in the roadmap).
