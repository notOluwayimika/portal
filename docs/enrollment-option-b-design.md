# Enrollment Option B — investigation & design (no migration, no code)

Registrar selected **Option B**: episodic enrollments, full history, active-only
uniqueness; the **student identity (student code) is fixed** across the admission
period; the surrogate key goes on the enrollment **episode**, never the student.
A **repeat** (does not complete, does not return in time) rolls the same curriculum
forward for the same student with **no withdrawal/gap**, distinct from
withdraw-and-return (which has a gap). Both are episodic rows; they end differently.

**Implementation is held** on one registrar answer: does a repeat start billing
**fresh** or **continue unbroken**. Everything below is designed against both
branches, with divergence flagged. No migration, no schema change, no code here.

---

## 1. The ≤1-assumption audit — every place that assumes one row per (student, curriculum)

The schema change is mechanical; the risk lives here. **The load-bearing finding:
the dangerous ≤1 assumption is not in enrollment — it is in RESULTS**, and it is
triggered specifically by the *repeat* rule (same curriculum).

### 1a. RESULTS / SCORES — NOT episode-safe (the silent reporting bug)

| Site | Assumption | Breaks with N episodes | Fix shape |
|---|---|---|---|
| `student_results` `UNIQUE(student_id, curriculum_subject_id)`; written `StudentResult::updateOrCreate(['student_id','curriculum_subject_id'])` (CurriculumSubjectController:95, :358) | one result per (student, curriculum_subject) | A **repeat** = same curriculum = same `curriculum_subject`s. The second attempt's `updateOrCreate` **silently OVERWRITES** the first attempt's result — no error, no history. Enrollment history is preserved (episode row kept) but **results history is destroyed**. | **Re-key to the episode**: `UNIQUE(student_curriculum_id, curriculum_subject_id)`; add `student_curriculum_id` FK; migrate every existing result to its episode; update every read/write. **Large — the real cost of Option B.** |
| `scores` `UNIQUE(student_id, curriculum_subject_id, marking_component_id)`; `Score::updateOrCreate` | one score per (student, curriculum_subject, marking_component) | Same — a repeat overwrites the prior attempt's per-component scores. | Same: re-key to `student_curriculum_id`. |

**Why it hides:** withdraw-and-return to a *different* curriculum has different
`curriculum_subject`s → no collision; only the **repeat (same curriculum)** trips
it, and it fails as a silent overwrite, never an error. This is the reporting bug
the audit exists to catch.

### 1b. ENROLLMENT-level sites (student_curricula) — the mechanical part

| Site | Assumption | Breaks with N | Fix shape |
|---|---|---|---|
| `StudentCurriculumController@register:203-204` `where(student_id)->where(curriculum_id)->exists()` ("already enrolled in this curriculum") | any row = enrolled | blocks a legitimate re-enrol/repeat (an ended episode still exists) | scope to **active** (`whereNull('ended_at')` / active status) |
| `@promote:150-151` same `exists()` on the target curriculum | any row = enrolled | same — blocks promotion back into a previously-ended curriculum | scope to active |
| `@updateStatus:104`, `@register:210` `where(student_id)->where(status,'active')->exists()` ("already enrolled in **a** curriculum") | one active enrolment **total** | already active-scoped → Option-B-compatible; but confirm the intent is "one active episode total" (across curricula) vs per-curriculum | keep; document the "one active total" invariant (it is what keeps `currentCurriculum` ≤1 — see 1c) |
| `CurriculumController:350` `StudentCurriculum::where(curriculum_id)->where(student_id)->first()` | one row | returns an **arbitrary** episode (default order) | `->whereNull('ended_at')->first()` (active episode) or `->latest('id')` per intent |
| `unique(student_id, curriculum_id)` (the constraint itself) | one enrolment per pair ever | forbids every second episode — the constraint Option B replaces | → `active_key` (§4) |

### 1c. Accessors / relations — safe **only** while "one active enrolment total" holds

| Site | Why it stays ≤1 | Watch |
|---|---|---|
| `Student::currentCurriculum()` `hasOne(StudentCurriculum)->where(status ACTIVE)` | active-only uniqueness + the "one active total" rule (1b) → at most one active episode | if the rule ever relaxes to "one active per curriculum", `currentCurriculum` becomes ambiguous → must add ordering |
| `StudentResource:18` `currentCurriculum ?? studentCurricula()->latest('id')->first()` | falls back to the most-recent episode | acceptable ("current or last"); confirm reports want *current*, not *sum of episodes* |

### 1d. Episode-safe children — NO change needed (already keyed on the episode)

`student_subjects` `UNIQUE(student_curriculum_id, curriculum_subject_id)`,
`behavioral_assessments` and `psychomotor_skills`
`UNIQUE(student_curriculum_id, assessment_term_id)` all FK to `student_curricula`
→ a repeat = new episode = new `student_curriculum_id` → new rows, no collision.
**These are the model the results/scores tables should have followed** (1a).

### 1e. Rendering fragility in the same neighbourhood

`CurriculumResource:32` (`full_name`) dereferences `academicSession`,
`classLevelArm->classLevel`, `->arm`, `->stream`, `examType`, `term` **unguarded**
(the 500 flagged last slice). Episodic enrolments across varied states make "a
curriculum missing a relation" *more* likely, not less. Fix shape: null-guard each
(its own small slice; independent of the schema change).

---

## 2. Episode terminal-state model

`active_key` answers "which episode is active"; a **terminal status** answers "why
each prior episode ended" — and the terminal status is what Finance reads (§9).

**Proposed terminal states** (the dormant `'repeated'` in `StudentStatusEnum` /
`UpdateStudentCurriculumStatusRequest`, which no workflow uses, was almost
certainly intended for exactly this):

| Terminal status | Meaning | Gap? | §9 Finance behaviour |
|---|---|---|---|
| `completed` | finished the curriculum | — | graduation / progression; no cancellation |
| `withdrawn` | left before completing | yes (may return later) | **invoice cancellation** |
| `repeated` | did not complete, did not return in time; same curriculum rolls forward | **no** | **billing continues OR restarts — registrar TBD (see below)** |
| `promoted` | moved to the next curriculum (existing `promoted_to_id` chain) | — | progression; no cancellation |
| `transferred` *(from `StudentMembershipStatus`)* | left to another school | yes | cancellation (student-level, not this table) |

`active` is the non-terminal state (one per student; `ended_at` null). An episode
ends by setting a terminal status **and** `ended_at`; the row is **never deleted**
(§15C append-only).

### The billing divergence (the only part held on the registrar)

- **Repeat = fresh billing:** the repeat episode is a NEW billable unit — its own
  invoice/fee schedule; the prior episode's charges close with it. Finance keys on
  the **episode id** (`student_curriculum_id`) as the billable referent.
- **Repeat = continuation:** the repeat inherits the prior episode's billing — no
  new invoice; charges roll forward. Finance keys on the **(student, curriculum)**
  pair or a linked billing thread across episodes.

**Divergence points (design both, decide later):**
1. Does creating a repeat episode emit a "new billable enrolment" fact, or a
   "billing continues" fact? (The eventual 1.4e published fact differs.)
2. Does the repeat episode get its own fee schedule, or reference the prior's?
3. On repeat, is the prior episode's outstanding balance cancelled, carried, or
   merged? (fresh = closed with the episode; continuation = carried.)
4. Reporting "amount billed for curriculum X": sum episodes (fresh) vs single
   thread (continuation).

Everything **except** these four is common to both branches and can be built now.

---

## 3. End-path reconciliation — one end operation, one create operation

Today three paths, inconsistent:

| Path | Today | Problem |
|---|---|---|
| `CurriculumEnrollmentService::unenroll()` | soft-ends (`ended_at`), keeps the row | **cannot** re-enrol into the same curriculum — the unique constraint then blocks a new episode (`enroll()` checks only `whereNull('ended_at')`, then the insert hits `unique(student_id, curriculum_id)` → unhandled `QueryException`) |
| `StudentCurriculumController@updateStatus('withdrawn')` | sets status **then DELETEs the row** | violates §15C append-only; destroys §9's durable referent; the delete is a *workaround* for the same unique constraint |
| **repeat** | does not exist | — |

**Design (under active-only uniqueness, all three converge):**

- **One authoritative END operation** — `CurriculumEnrollmentService::endEpisode(episode, terminalStatus, actor, reason?)`: sets the terminal status + `ended_at` + `ended_by`/reason, **never deletes**, clears `active_key` (→ NULL) so a new active episode becomes insertable. `unenroll` = `endEpisode(…, withdrawn)`; the withdraw-delete path is replaced by `endEpisode(…, withdrawn)` (stops the deletion); repeat = `endEpisode(…, repeated)` immediately followed by a new episode.
- **One authoritative CREATE operation** — `enroll()` becomes the *only* way an episode is born (fixes the §1 enrollment-creation fan-out: `@promote` and `@register` currently `StudentCurriculum::create` directly, bypassing `enroll()` **and** its `autoAttachCompulsorySubjects`). `@register` → `enroll()`; `@promote` → `endEpisode(source, promoted)` + `enroll(new, ['promoted_to_id' => …])`; repeat → `endEpisode(source, repeated)` + `enroll(same curriculum)`.
- **Repeat vs withdraw-and-return** are the same two operations in a different order/terminal-status: repeat = end(`repeated`) + immediate re-create (no gap); withdraw-return = end(`withdrawn`) now, re-create later (gap). The data shape is identical; only the terminal status and timing differ.

This convergence is a prerequisite the 1.4e event bus needs (one publication point
per fact); it is **not** the bus.

---

## 4. Schema proposal (proposal only — not executed)

### 4a. Active-only uniqueness (student_curricula)

MySQL has no partial unique index, so:

- add a **generated** column
  `active_key` = `CASE WHEN ended_at IS NULL THEN curriculum_id ELSE NULL END`
  (STORED), and
- replace `UNIQUE(student_id, curriculum_id)` with `UNIQUE(student_id, active_key)`.

NULL `active_key` for every ended episode → many ended + at most one active per
`(student, curriculum)` is expressible; `student_id`-scoped, so the "one active
total" invariant (1b/1c) is enforced separately in code, not by this index.
(Alternative considered: `UNIQUE(student_id, curriculum_id, ended_at)` — rejected,
MySQL treats multiple NULL `ended_at` as distinct, so two *active* rows would pass.)

### 4b. Results/scores re-key (the large, coupled part — 1a)

`student_results` and `scores` must move from `student_id`-keyed to
`student_curriculum_id`-keyed so a repeat's results are distinct:
add `student_curriculum_id` (FK), backfill from the (student, curriculum_subject)→
episode mapping, swap the unique indexes, update every read/write. **This is the
bulk of Option B's cost and must land with (or before) the repeat workflow — a
repeat without it silently overwrites results.**

### 4c. Backfill, down(), risk matrix

| Item | Plan |
|---|---|
| Backfill (student_curricula) | every existing row is an **active** episode: `active_key` derives from `ended_at` (existing unenrolled rows already have `ended_at`); ended rows → NULL, others → curriculum_id. No row moves. |
| Backfill (results/scores re-key) | map each `(student_id, curriculum_subject_id)` to the student's episode for that curriculum; **ambiguous if a student already has >1 episode in a curriculum today** — verify none exist first (the current delete-on-withdraw means historical repeats have NO surviving prior episode, so today's data is 1:1; confirm before migrating). |
| `down()` | drop `active_key` + its unique, restore `UNIQUE(student_id, curriculum_id)` — **only reversible while no student has 2 active/duplicate episodes**; once a repeat exists, the old unique cannot be restored (that data is the point of Option B). State this: rollback is safe pre-first-repeat, lossy after. |
| Data-migration risks | (1) duplicate historical rows — none today (delete workaround erased them), so the forward migration is clean but **irreversible after first repeat**; (2) reporting impact — every `(student, curriculum_subject)` result read must choose an episode; (3) the results re-key is the high-blast-radius change, not the enrollment index. |

**Do not run any of this.** It waits on the registrar's fresh-vs-continuation
answer (§2), which changes 4b's Finance-facing shape and the repeat workflow.

---

## Summary — what unblocks implementation

1. **≤1 audit done:** enrollment-level sites are mechanical (scope checks to
   active); the **real cost is re-keying `student_results`/`scores` to the episode**
   — without it, a repeat silently overwrites results. Assessments/subjects are
   already episode-safe. `CurriculumResource:32` null-guard is an adjacent small fix.
2. **End-path:** one `endEpisode(terminalStatus)` + one `enroll()`; stops the
   §15C-violating delete; also fixes the create fan-out.
3. **Terminal status:** revive `repeated`; `active_key` = which is active, terminal
   status = why prior ended (what Finance reads). Designed for both billing branches.
4. **Schema:** `active_key` generated column + `UNIQUE(student_id, active_key)`;
   plus the coupled results/scores re-key; backfill clean today but rollback lossy
   after the first repeat.

**Held on:** registrar — repeat billing **fresh vs continuation** (§2 divergence
points 1–4). Then implement.
