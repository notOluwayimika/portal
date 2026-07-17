# 0044 — Result & enrollment authorization moves from roles to permissions

**Status:** Accepted as a **design** (permission model + migration plan). No
permissions are added and no `hasRole()` is changed by this ADR — implementation
is a separate, approved slice. This ADR exists so the model is agreed before the
code changes, and so Phase 2 does not inherit the role-based pattern.

## Context

The result and enrollment maker–checker workflow authorizes by **role**, in
violation of Constitution §7.2 ("authorize by permission, never role"). The debt
is partly live and partly dormant (roadmap "Role-gate inventory"):

- **Live** `hasRole('admin') || hasRole('head_of_school')` in four FormRequests —
  `RejectSubjectResultRequest`, `PromoteStudentRequest`,
  `UpdateStudentCurriculumStatusRequest`, `RegisterStudentCurriculumRequest`.
- **Dormant** (commented) controller role checks — `CurriculumSubjectController`
  `submit` (`isTeacher`), `approve`/`reject` (`isReviewer` = admin|head_of_school),
  and `StudentCurriculumController@getScoresWithMarkingComponents`.
- Route groups additionally apply coarse `role:` middleware.

One sibling already does it correctly: `student_curriculum.unenroll` authorizes by
permission (`UnenrollStudentRequest`). The new set **must match that convention**,
not introduce a parallel one.

## Decision — the permission model

Seven permissions, in the existing dotted convention. Two workflows:

### Result lifecycle (maker–checker)

| Permission | Meaning | Default role(s) | Role in SoD |
|---|---|---|---|
| `result.submit` | Teacher submits a subject's results for review | `teacher` | **Maker** |
| `result.approve` | Reviewer approves submitted results | `head_of_school` (admin as oversight) | **Checker** |
| `result.reject` | Reviewer rejects submitted results back to the teacher | `head_of_school` (admin as oversight) | **Checker** |
| `result.view_scores` | View scores + marking components for a subject | `teacher`, `head_of_school`, `admin` | — (read) |

### Enrollment lifecycle

| Permission | Meaning | Default role(s) |
|---|---|---|
| `student_curriculum.register` | Register a student into a new curriculum | `admin`, `head_of_school` |
| `student_curriculum.promote` | Promote a student between curricula | `admin`, `head_of_school` |
| `student_curriculum.update_status` | Change enrollment status (active/promoted/…) | `admin`, `head_of_school` |
| `student_curriculum.unenroll` *(exists)* | End an enrollment | `admin`, `head_of_school` |

### Maker–checker separation (the load-bearing constraint)

`result.submit` (maker) and `result.approve` / `result.reject` (checker) **must
not be held by the same role for the same subject**: a teacher submits, a
reviewer approves. This mirrors the Finance segregation-of-duties model and binds
with ADR 0040 (`super_admin` never overrides maker–checker).

- `teacher` gets `result.submit` and **not** `result.approve`/`reject`.
- `head_of_school` gets `result.approve`/`reject` and **not** `result.submit`.
- **`admin` is the open question.** Admin today holds everything; granting it both
  `result.submit` and `result.approve` would let one actor make and check the same
  result, defeating SoD. The implementation slice must decide: (a) admin gets
  oversight/read + `approve`/`reject` only (not `submit`), or (b) admin is excluded
  from the result gate entirely and acts through head_of_school. **Recommendation:
  (a)** — admin approves/rejects and views, but does not submit. This decision is
  explicitly deferred to the implementation slice, not assumed here.

**This is not a fresh SoD decision — it inherits the Finance model.** The
maker≠checker constraint is already established for the platform by **ADR 0009**
(maker–checker approval) and **ADR 0040** (`super_admin` never overrides
maker–checker): structural separation (`decided_by ≠ initiator_id`), Policy
enforcement, and the super-admin bypass explicitly excluded from approval. The
result workflow **adopts that same model** rather than inventing a second SoD
interpretation. Concretely, the implementation slice:

- enforces the maker/checker split with the same structure Finance uses
  (`updated_by` / approver identity recorded, checker ≠ maker), not a bespoke rule;
- excludes `super_admin` from the approval bypass for `result.approve`/`reject`,
  per ADR 0040 (a super admin may act, but does not get maker–checker-free
  approval);
- so the admin-holds-both question above is resolved **the Finance way** — a
  single identity may not both submit and approve the same result — not
  re-litigated here.

`result.view_scores` is a read permission and carries no SoD constraint; guardians
continue to see their child's results through the guardian-facing endpoints, not
this one (the commented `getScores` role check intended to exclude guardians —
under the permission model, guardians simply are not granted `result.view_scores`).

## Migration plan (executed by the later implementation slice)

1. Add the seven cases to `App\Enums\Permission`, seed them, and assign to roles
   per the tables above (single seeder wired into `DatabaseSeeder`, asserted by
   the `SeededPermissionSet` test — same mechanism as 1.2a).
2. Replace the **live** `hasRole()` in the four FormRequests with `->can()`:
   Reject → `result.reject`, Promote → `student_curriculum.promote`,
   UpdateStatus → `student_curriculum.update_status`, Register →
   `student_curriculum.register`.
3. Wire the **dormant** controller checks to the new permissions **in observe
   mode** first (via `App\Support\Authz`, consistent with S5): submit →
   `result.submit`, approve → `result.approve`, reject → `result.reject`,
   getScores → `result.view_scores`. Enforce only after observation review
   (roadmap §24 four-part checkpoint).
4. Reconcile route `role:` middleware: keep it as the coarse authentication-tier
   gate or migrate to `permission:` middleware; do not rely on it as the sole
   authorization once permissions exist.
5. Add a `RestoredCheckEnforces403` test per restored gate and a maker≠checker
   test proving a `teacher` cannot approve and a `head_of_school` cannot submit.

Each step is independently mergeable; steps 2–3 are behaviour-observing (observe
mode) until the enforcement flip.

## Consequences

- The workflow authorizes by permission, consistent with
  `student_curriculum.unenroll`; the §7.2 violation is retired.
- Segregation of duties for results becomes explicit and testable before any
  Finance approval flow (Phase 3) is built on the same idea.
- No behaviour changes until the implementation slice; this ADR is design only.

## Related

- ADR 0035 (Constitution §7.2 — authorize by permission, never role).
- ADR 0040 (`super_admin` never overrides maker–checker).
- ADR 0043 (`Authz` observe-mode rollout — the vehicle for step 3).
- roadmap.md — the role-gate inventory this ADR discharges.
