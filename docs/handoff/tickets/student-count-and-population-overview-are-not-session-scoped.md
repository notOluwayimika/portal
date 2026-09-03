# The student total and population overview are school-wide, not session-scoped

**Found:** review, 2026-09-03. **Pre-existing**, unrelated to the CCM/rollover work. Owner decision
recorded up front, because it is the whole shape of the fix: **the displayed student total and the
population overview are to be session-scoped; the onboarding/threshold logic stays school-wide.** Only
the display changes.

## What the two surfaces show today

Neither is session-scoped — both count the school's entire student population for all time:

- **Setup overview** — `SetupController::index`, line 53:
  `'students' => $school->students()->count()`. `School::students()` is a plain
  `hasMany(Student::class)` on `school_id`. Every student the school has ever registered (bar
  soft-deleted).
- **Admin dashboard** — `DashboardAnalysisService::collectEntityVolumes`, the `students` entity:
  `DB::table('students')->where('school_id', $schoolId)`, where `active` means `whereNull('deleted_at')`
  — *not soft-deleted*, not *enrolled this session*. This same value feeds BOTH the displayed figure
  and the onboarding gate (see below).
- **Population overview** — `collectDistributions`' `students_by_class_level` joins through
  `student_curricula` with `status = 'ACTIVE'`, so it is enrollment-shaped, but it carries **no session
  filter** either.

The consequence: the number only ever grows, counts pupils who graduated or left (as long as their row
isn't soft-deleted), and does not answer the question an overview is for — "how many students are here
*this year*."

## What it should show

The **displayed** total (setup overview + dashboard) and the **population overview** (by class level)
should count **distinct students with an ACTIVE `student_curricula` enrollment in a curriculum whose
`term` belongs to the school's active session** — `$school->currentSession` (already resolved in
`SetupController::index`, alongside `CurrentTerm::forSchoolModel`). A `Student` is a persistent
person with no session column, so this is only expressible through enrollment:
`students -> student_curricula (status ACTIVE) -> curricula -> terms -> academic_session = currentSession`.

## The split, and why it is the whole point

`entities['students']['active']` is **one number doing two jobs**. Besides the displayed figure, it is
the onboarding gate: the "Add your students" step's `is_complete` is
`($entities['students']['active'] ?? 0) >= dormant_threshold`. Session-scoping that value wholesale
would flip a school that is **between sessions** — new session rolled, enrollments not yet created —
back to "incomplete," which is wrong: they added their students last year, the step is done.

So do **not** overwrite `active`. Produce a **separate** session-scoped figure for the display, and
leave the school-wide `active` feeding the gate untouched. This is the same "one field, two consumers"
separation the batch-status and citation-lint work turned on — keep the two readings distinct rather
than making one serve a question it doesn't answer.

**THERE ARE THREE GATE CONSUMERS, NOT TWO** — re-derived from the tree rather than carried from the
report, which named the first two:

- `DashboardController` line 164 — the web onboarding checklist;
- `Api\DashboardController` line 246 — the API onboarding checklist;
- `DashboardAnalysisService` line 69 (`$hasStudents`) — which decides `is_onboarding_state` for the
  whole analysis, and is the one a reader of the ticket would have missed because it sits in the same
  method being edited.

All three must keep reading the school-wide `active`.

## What closes it

**Backend**
- Add a session-scoped distinct-student count via the join above, keyed on `$school->currentSession`.
  Expose it as a NEW field on the students entity (`enrolled_current_session`) — do **not** touch
  `total` / `active`.
- `SetupController::index`: change `'students'` to the session-scoped count. `currentSession` is
  already in scope there, and nothing consumes that value as a threshold, so it is changed directly.
- `students_by_class_level`: add the session filter (join `terms`, `where academic_session_id =
  currentSession->id`).

**Frontend**
- The setup overview "Students" card renders `data?.students` verbatim and recomputes nothing —
  confirmed, so it needs no change.
- The dashboard KPI strip reads `entity.active` for **every** entity, so the students KPI needs a
  per-KPI value selector rather than a global change; the population overview reads the
  now-session-filtered distribution.
- The onboarding checklist is unchanged — it is server-computed from the school-wide `active`.

**Null active session** (a school with no current session): display **0** — no active session means
nobody is enrolled this session — and do not error. The onboarding gate is unaffected (still
school-wide).

## Edge cases

- Between sessions (rolled, not yet enrolled): displayed count low/0, onboarding "students" step
  **still complete**. This is the split working, not a bug — pin it with a test.
- A student with two active enrollments in the session counts **once** (`COUNT(DISTINCT
  students.id)`).
- Soft-deleted students stay excluded.
- No current session -> 0, no error.

## Arms it needs

- **Positive:** a student enrolled ONLY in a prior session is **not** in the displayed total; a student
  enrolled in the current session **is**. Assert the setup-overview number and the dashboard displayed
  number both equal the current-session distinct count — not merely "> 0".
- **The split guard (the important one):** a school whose students are all from a prior session —
  displayed total is 0/low, **but** the onboarding "students" step is still `is_complete` (school-wide
  volume >= threshold). This pins that the two consumers diverge on purpose.
- **Population overview:** `students_by_class_level` counts only current-session enrollments.
- **Null session:** displayed 0, no error, gate unaffected.
- **Distinct:** two active enrollments for one student -> counted once.

## Related

- `SetupController::index` (`$school->currentSession`, `CurrentTerm::forSchoolModel`).
- `DashboardAnalysisService::collectEntityVolumes` / `collectDistributions`.
- Borders the general dashboard/setup area (partly the co-dev's); unrelated to CCM/rollover.
