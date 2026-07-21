# 0044 — Result & enrollment authorization moves from roles to permissions

**Status:** Accepted as a **design** (permission model + migration plan). No
permissions are added and no `hasRole()` is changed by this ADR — implementation
is a separate, approved slice. This ADR exists so the model is agreed before the
code changes, and so Phase 2 does not inherit the role-based pattern.

**Implementation:** the seven permissions were added and seeded by slice **C1**
(`feat/rbac-foundation`); the authorization changes — migration-plan steps 2, 3
and 5, plus the ADR 0040 binding in "Maker–checker separation" — landed in slice
**C3** (`feat/rbac-policies`, 2026-07-21). Step 4 (route `role:` reconciliation)
was discharged by **C2**. See "Implementation record" at the end of this file for
what each step actually produced, including the one open question this ADR left
to the implementation slice.

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

## Implementation record (C1 2026-07-21 · C2 2026-07-21 · C3 2026-07-21)

| Step | Outcome |
|---|---|
| 1 — add + seed the seven | **C1.** Added to `App\Enums\Permission`, seeded by the single `RbacSeeder`, asserted by `SeededPermissionCoverageTest` (every permission reaches ≥1 role) rather than the ADR's projected `SeededPermissionSet`. |
| 2 — four FormRequests `hasRole` → `can()` | **C3.** Reject → `result.reject`, Promote → `student_curriculum.promote`, UpdateStatus → `student_curriculum.update_status`, Register → `student_curriculum.register`. |
| 3 — dormant controller checks → permissions, observe mode | **C3.** `submit` now tests `result.submit` (recorded ability renamed to match, observation stream still empty so no evidence was lost); `approve` tests `result.approve`. `reject` deliberately has **no** observe-mode check: its FormRequest already enforces the identical condition first, so the check could never fire and would record no evidence while reading like a live gate. `getScores` → `result.view_scores` is **not done** — it belongs to the 45 `Authz` call sites A6 owns, and C3's brief excludes them. |
| 4 — reconcile route `role:` middleware | **C2** (`feat/rbac-permission-middleware`): all 27 non-super-admin groups swapped to `permission:`, parity-proven against a pre-swap oracle. |
| 5 — restored-gate 403 test + maker≠checker test | **C3.** `MakerCheckerSeparationTest` covers the ADR's exact wording (a teacher cannot approve, a head_of_school cannot submit) **and** the structural rules that survive a runtime regrant of those permissions. |

**The open question, resolved.** The ADR left "admin holds both sides?" to the
implementation slice with recommendation (a). **C1 took (a)**: admin holds
`result.approve`/`reject`/`view_scores` and *not* `result.submit`, enforced by an
SoD test that fails if any role ever holds maker and checker together.

**What C3 added beyond the plan.** The ADR's binding to ADR 0040 required two
mechanisms, and following the letter of ADR 0040 would have implemented only a
`finance.*.approve` exclusion — which `result.approve`/`result.reject` do not
match. C3 therefore implemented the exclusion as a terminal-segment **convention**
and added the structural `submitted_by <> decided_by` rule at Policy **and** DB.
That second half needed a schema change the ADR did not anticipate: the result
status table recorded one `updated_by`, overwritten on each transition, so the
approver's write destroyed the submitter's identity — maker ≠ checker was not
merely unenforced, it was unrepresentable. Details in ADR 0040's implementation
status section.

**Role-gate re-audit (the roadmap's deferred item).** After C3 the remaining
`hasRole()` calls in `app/` are, deliberately, *not* authorization gates:
`PrincipalController@destroy` asks whether the **target** is a principal (role
identity is the question; the caller is authorized by the route's permission);
`DashboardController`, `StudentController`, `CurriculumController` and
`GuardianService` branch on `guardian` to pick a **view or data shape**, not to
grant access. Converting those would change their meaning rather than modernise
it. `User::hasRole` inside `User.php` is the mechanism itself.

## Related

- ADR 0035 (Constitution §7.2 — authorize by permission, never role).
- ADR 0040 (`super_admin` never overrides maker–checker).
- ADR 0043 (`Authz` observe-mode rollout — the vehicle for step 3).
- roadmap.md — the role-gate inventory this ADR discharges.
