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

**Read-side audit (the other half — after the re-key to `student_curriculum_id`,
every read still keyed on `(student, curriculum_subject)` returns the WRONG or a
MIXED episode's results).** Same `->first()` pattern already caught at
`CurriculumController:350`, one level down in the data:

| Reader | Pattern | Breaks with N episodes |
|---|---|---|
| `StudentController:317` (result card / availability) | `StudentResult::where(student_id)->where(curriculum_subject_id)->first()` | returns an **arbitrary** episode's result → the current episode shows a prior attempt's result / wrong availability |
| `CurriculumController:353` (transcript/result read) | same `->first()` | wrong episode's result |
| `BroadsheetService:251, :309` (broadsheet) | `studentResults->firstWhere('student_id', $sid)` | broadsheet cell = arbitrary episode. **High impact — the whole-class results report** |
| `StudentSubjectResource:35` | `studentResults->firstWhere('student_id', $sid)` | subject card shows arbitrary episode |
| `CurriculumSubjectController:534` (year average) | `studentResults->avg('total_score')` over all rows for a subject | a repeating student is **counted twice** → the class/year average is wrong |
| `CurriculumController:406-408, :441` (completeness map) | join / key on `(student_id, curriculum_subject_id)`, episode-blind | a result from ANY prior episode marks the current one "complete" → **"outstanding results" understated** |
| `StudentCurriculumController:227` (getScoresWithMarkingComponents) | `Score::where(student_id)->where(curriculum_subject_id)->get()` — **has the episode (`$studentCurriculum`) but queries by student_id** | returns scores from **all** episodes mixed together |
| `CurriculumSubjectController:84, :61, :68, :111` (score entry / submit / approve) | `scores()->where(student_id)->get()` / result reads keyed on `(cs, student)` | totals/grades computed across episodes; approval acts on the wrong episode |
| `BroadsheetService:259` (scores) | `scores->where('student_id', $sid)` | sums scores across episodes |
| Dashboard aggregates (`DashboardAnalysisService:148`, `ModuleClassificationService:137`) | counts/joins on `student_results`/`scores` by `curriculum_subject` | repeat episodes double-count in analytics |

**Read-side fix shape:** every one of these must add the episode dimension —
scope on `student_curriculum_id` (which the caller already has, or must resolve to
the active episode). Getting the write-side re-key right without fixing these is
worse than doing nothing: the data would be correctly episode-keyed but every
report would still collapse it to one arbitrary episode. **The read-side is the
true blast radius** — broadsheet, transcript, result card, score entry, approval,
year averages, completeness maps, the getScores endpoint, and dashboards.

Data migrations touching these tables (not reads, noted separately):
`MoveFromCcmJob:238/255` (moves scores by `curriculum_subject_id`),
`BackfillStudentResultGrades`, `MigrateMarkingSchemes:118` — must be episode-aware
when the re-key lands.

### 1b. ENROLLMENT-level sites (student_curricula) — the mechanical part

| Site | Assumption | Breaks with N | Fix shape |
|---|---|---|---|
| `StudentCurriculumController@register:203-204` `where(student_id)->where(curriculum_id)->exists()` ("already enrolled in this curriculum") | any row = enrolled | blocks a legitimate re-enrol/repeat (an ended episode still exists) | scope to **active** (`whereNull('ended_at')` / active status) |
| `@promote:150-151` same `exists()` on the target curriculum | any row = enrolled | same — blocks promotion back into a previously-ended curriculum | scope to active |
| `@updateStatus:104`, `@register:210` `where(student_id)->where(status,'active')->exists()` ("already enrolled in **a** curriculum") | one active enrolment **total** | already active-scoped → Option-B-compatible; but confirm the intent is "one active episode total" (across curricula) vs per-curriculum | keep; document the "one active total" invariant (it is what keeps `currentCurriculum` ≤1 — see 1c) |
| `CurriculumController:350` `StudentCurriculum::where(curriculum_id)->where(student_id)->first()` | one row | returns an **arbitrary** episode (default order) | `->whereNull('ended_at')->first()` (active episode) or `->latest('id')` per intent |
| `unique(student_id, curriculum_id)` (the constraint itself) | one enrolment per pair ever | forbids every second episode — the constraint Option B replaces | → `active_key` (§4) |

### 1b-bis. ⚠️ BLOCKING DEFECT this slice must fix — `promotedTo()` loads the wrong entity

Confirmed 2026-07-19. **This slice is the one that fires it**, because §3's promotion
path writes `promoted_to_id`.

The FK is self-referencing and the migration says so outright — *"Self-referencing
FK — points to the next student_curricula row after promotion"*
(`2026_05_01_100910`, `->constrained('student_curricula')`). Live schema agrees:
`promoted_to_id → student_curricula.id`. Both internal writers agree too
(`StudentCurriculumController:178` → `$new->id`; `BackfillPastTermJob:254` →
`$sourceEnrollment->id`).

Three sites disagree with the FK:

| Site | Says | |
|---|---|---|
| `StudentCurriculum::promotedTo()` `:63-66` | `belongsTo(Curriculum::class, 'promoted_to_id')` | ❌ wrong entity |
| `StudentRequest.php:66` | `exists:curricula,id` | ❌ wrong table |
| `resources/js/types/models.ts:444` | `promoted_to?: Curriculum` | ❌ wrong type |

**The FK is right; the model, the request rule and the TS type are wrong.** Both are
auto-increment bigints in overlapping ranges, so this does not error — it silently
loads an arbitrary `Curriculum` that happens to share the integer id.

**Latent only because `promoted_to_id` is set on 0 rows today.** It activates on the
first real promotion — i.e. the moment §3's `@promote → endEpisode(source, promoted)
+ enroll(new, ['promoted_to_id' => …])` path ships. Note §3 above passes
`promoted_to_id` as if it works; that line inherits the bug.

**Trigger: fix it in this slice, before the promotion path is wired.** Deliberately
NOT fixed by slice (i) (`student_curricula.school_id`) — it belongs with the
promotion chain, not with School integrity. Slice (i) also left
`StudentRequest:66`'s rule unscoped on purpose: scoping it to `curricula` would only
entrench the wrong target table.

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

### Billing — RESOLVED (registrar): every episode bills fresh, uniformly

**Every enrollment episode bills fresh** — new curriculum, promotion, and repeat
all generate a fresh bill, identically. The continuation branch is dropped; the
four divergence points collapse to one answer ("fresh") and no longer exist as
choices.

Consequences for this design:
- **Terminal statuses are pure ACADEMIC facts with no financial meaning.**
  `completed` / `withdrawn` / `repeated` / `promoted` / `transferred` describe *why
  an episode ended*, for academic history and reporting. `repeated` carries **no**
  billing semantics — Finance does not read the terminal status to decide billing.
- **One uniform fact per episode.** Every episode (however it was born — new,
  promotion, repeat) emits a single "enrollment happened" fact; Finance bills all
  episodes identically off the **episode id** (`student_curriculum_id`) as the
  billable referent. **Repeat billing needs no special Finance logic** — it is
  ordinary per-episode billing.
- **Waive / discount are post-bill adjustments, not a billing mode.** They apply
  *after* a bill exists (§10 credit note / write-off; §3 discount) through the
  normal Finance approval + ledger + audit workflow — not repeat-specific, not an
  enrollment concern. Nothing here.

**Open §7 question, flagged for Ph2 (do NOT act now):** does a waived fee appear on
the parent statement as "charged then waived," or not appear at all? A statement-
presentation decision, independent of this enrollment slice.

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

**Do not run any of this** *in this slice* (design only). Billing is resolved
(fresh, uniform — §2), so nothing here is registrar-blocked any longer; the schema
+ re-key + read-side fixes are one coupled implementation slice, ready to sequence.

---

## Summary — READY TO SEQUENCE (billing resolved)

Registrar: **every episode bills fresh, uniformly**; terminal statuses are pure
academic facts; repeat billing needs no special Finance logic (ordinary billing +
the general §10/§3 adjustment workflow). The design is now common to a single
billing model — no branches remain.

1. **≤1 audit complete — both sides.** WRITE side: re-key `student_results`/`scores`
   from `(student_id, curriculum_subject_id)` to `student_curriculum_id` (a repeat
   silently overwrites otherwise). READ side (**the true blast radius**): every
   reader keyed on `(student, curriculum_subject)` — broadsheet, transcript, result
   card, score entry, submit/approve, year averages, completeness maps, the
   getScores endpoint, dashboards — must add the episode dimension, or reports
   silently show an arbitrary/mixed episode. Assessments/subjects already
   episode-safe. `CurriculumResource:32` null-guard is an adjacent small fix.
2. **End-path:** one `endEpisode(terminalStatus)` + one `enroll()`; stops the
   §15C-violating delete; also fixes the create fan-out.
3. **Terminal status:** revive `repeated` as a **pure academic** status (no
   financial meaning); `active_key` = which episode is active, terminal status = why
   prior episodes ended.
4. **Schema:** `active_key` generated column + `UNIQUE(student_id, active_key)`;
   the coupled results/scores re-key + all read-side fixes; backfill clean today
   but rollback lossy after the first repeat.

**The coupled slice = student_curricula `active_key` + end/create convergence +
results/scores re-key + every read-side fix.** Its true blast radius (the read
inventory in §1a) is now known. **Open for Ph2 only (do not act):** §7 statement
presentation of a waived fee. No migration, no code in this design slice.
