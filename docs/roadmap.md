# Roadmap & source of truth

Two approved documents govern this work. They do not compete — they answer
different questions, and this page records the reconciliation so there is a
single authoritative roadmap.

| Question | Authority |
|---|---|
| **What the architecture is** (Constitution, isolation/identity/RBAC models, financial architecture, Module Blueprint, ADR register 0001–0035) | **Finance Implementation Specification v10** (`plan_docs/`, untracked) |
| **When and in what order it is delivered** (milestones 1.0–1.5, slice contents, Core vs Continuous, rollout flags, deferrals) | **Phase 1 Execution Plan** (approved after v10; explicitly preserves v10's architecture and re-sequences only delivery) |

Where the two describe delivery differently, **the Execution Plan governs** —
it was approved later, for exactly that purpose. No technical decision from v10
was changed by it.

## Reconciled deviations (Execution Plan — Validation Review §A)

v10 §20 packs all foundation work into a 6-week Phase 1. The approved
Execution Plan split that into **Phase-1 Core** (gates Finance Ph2) and a
**Continuous track** (each item lands before the phase it actually blocks):

| v10 Phase-1 item | Reconciled delivery | Actually gates |
|---|---|---|
| Idempotency table + middleware (§12.4, ADR 0008) | **Ph5** | record-payment / webhooks |
| FeatureFlags service | **Ph2** | per-School Finance flag |
| Approvals engine (ADR 0009) | **Ph3** | the approval-engine phase |
| Pdf engine (ADR 0014) | **Ph5** | first invoice/statement template |
| Sequences (ADR 0007) | Continuous (early) | Ph5 (also fixes the live admission-number race) |
| Observability (ADR 0031) | Continuous | before Ph6 ("before money moves") |
| Event bus + 4 Academics facts (ADR 0011) | Continuous | Ph5 (first Finance listener) |
| Audit immutability (ADR 0032) | Continuous (early) | protects the existing log |
| 53 commented-authz restores | Continuous (baseline burn-down) | security debt, not Ph2 |
| Legacy jobs → `SchoolAware` (1.3b) | Continuous | precondition for enabling fail-closed on job-touched models |

**Verified audit counts supersede the spec's.** The Execution Plan's code
audit re-measured v10 §4.2/§4.4 and every figure was worse than claimed; the
verified numbers below are authoritative, and v10's risk register, acceptance
criteria and Phase-1 estimate — built on the lower figures — must be read
against them:

| Debt item | v10 claims | Verified (authoritative) |
|---|---|---|
| Commented-out authorization checks | 52 | **53** |
| Controllers containing them | 5 | **7** |
| Publicly leaked (unauthenticated) routes | 6 | **7** |
| Permissions actually defined | 32 | **28** (19 of them never seeded at audit time) |

(Jobs impersonating a causer without team context: **6 of 7 job classes**
verified — v10's "5 jobs" undercounted by one, reconciled in slice 1.3b, which
retrofitted **all 7** to `SchoolAware` and removed every impersonation.)

### Debt corrections & residuals (post-1.3b investigation)

- **Halting-event defect (slice 1.3b.1).** `BelongsToSchool::creating`'s
  `school_id` auto-fill (§5.2 enforcement point 2) was silently inert on 9
  models. Root cause: Laravel's `creating` event is halting (`until()`), and
  `AddUuid`'s arrow-fn returned the uuid (non-null), halting the chain before
  the fill. Fixed by converting `AddUuid` to a block closure; a reflection-wide
  conformance test now guards every `BelongsToSchool` model, and a boundary-lint
  rule (`halting-event-arrow-fn`) prevents recurrence. No live isolation breach
  occurred (8 of 9 tables are `school_id NOT NULL` → fail-loud; the 9th,
  `students`, is nullable but its sole create path passes `school_id`
  explicitly).
- **Debt item 17 (admission/staff numbering) — correction.** Previously
  recorded only as a *concurrency race* (racy read-then-write in
  `HasAdmissionNumber`/`HasStaffNumber`). The same halting defect means the
  duplicate-number **validation** `creating` hooks **never executed** on
  `AddUuid`-first models (Student, Teacher) — a **functional-correctness gap in
  addition to the race**. The validation hooks now run (1.3b.1); the concurrency
  race is still owned by the Sequences slice (1.4b).
- **Debt item 7 (SchoolScope fail-open) — residual, NOT fully complete.** 1.3b
  fixed queued *scope application* (scoping now applies under `runFor`), but the
  fail-closed **throw remains auth-gated**: a principal-less off-request
  execution (an unauth scheduled command, or a future non-`SchoolAware` job with
  no auth) still reads **unscoped** rather than throwing. Owning future slice:
  the `users.school_id`-drop / ADR 0042 slice (make `ActiveSchool::id()`
  transport-agnostic), or a dedicated scope-level backstop. Debt item 7 stays
  **open**.
- **1.3b verification note — correction.** An earlier 1.3b verification
  attributed the non-firing auto-fill to a "test-harness dispatcher blind spot."
  That was wrong. The cause is **halting-event ordering** (above), reproducible
  identically in **both PHPUnit and Tinker** — the harness faithfully reflected
  production.

## Phase-1 status (as of M1.5b)

**Core — complete:** 1.0a/b/c (CI + MySQL suite + factories) · 1.1a/b (IDOR
patch, route/seeder fixes) · 1.2a (Permission enum) · 1.2b (`Gate::before`,
flag) · 1.2d (authz lint + policy pattern) · 1.2e (single access source, flag)
· 1.3a (`runFor` + `SchoolAware` + rename) · 1.3c (fail-closed scope,
per-model flag; super-admin isolation refactor) · 1.3d (Term/ClassLevelArm/
MarkingComponent scoping) · 1.3e (`students.status`) · 1.3f (guardian
same-School constraint + timezone + queue) · 1.4a (Money VO + cast + wire
contract) · 1.5a (arch tests + boundary lint + Larastan L5) · 1.5b (this docs
slice).

**Continuous — done:** 1.3b (all 7 queueable jobs retrofitted to `SchoolAware`;
`auth()->setUser($causer)` eliminated; `SchoolScope`/`BelongsToSchool` now
resolve off-request context from `ActiveSchool::runFor()`).

**Continuous — open:** 1.2c1–c3 (34 commented-authz entries remain of 53),
now rolling out **observe-first** via `App\Support\Authz` (S5 — see below) ·
1.2f remainder (drop `users.school_id` + `school_user` after parity; expires
ADR 0042's debt) · fail-closed per-model enablement (jobs no longer block it;
per-model request-path audit remains the gate) · 1.4b–e (Sequences, audit immutability,
observability, event bus) · frontend `formatNaira` (§12.3 names it; only ad-hoc
`toLocaleString` rendering exists today).

**Not authorization debt — test role-seeding debt (reclassified):** the ~25
Guardian / ActivityLog feature-test failures are produced by the `role:`
route-middleware that is *already enforcing* on those routes, not by the dormant
`->can()` checks (which are still commented and inert). They are test-seeding
gaps — the tests do not seed the role the middleware requires — and must be
fixed by correcting test setup, **not** by touching authorization. They are
**not** S5 observe-mode evidence and prove nothing about the commented checks.

**Rollout flags currently dark:** `auth.gate_before_superadmin` (on by
default, verified) · `rbac.single_source_access` (off; parity-gated) ·
`rbac.fail_closed_models` (empty; per-model — 1.3b landed, so job context no
longer blocks any model; each enablement still needs its request-path audit).

## Decision: Larastan is baseline-relative (ratchet)

The §24 exit criterion "Larastan Level 5 + arch tests green" and the Execution
Plan's ratchet philosophy were previously ambiguous about whether "green" means
**absolute zero findings** or **zero findings above the committed baseline**.

**Decision (architectural, not an assumption):** Larastan Level 5 is enforced
**baseline-relative** — CI fails only on findings NOT in `phpstan-baseline.neon`,
identically to the test / tsc / authz / boundary ratchets. Level 5 is fixed for
Phase 1; the baseline may only shrink. "Green" at Phase-1 exit therefore means
**green against the baseline**, and burning the baseline toward zero is
opportunistic Continuous work, not a Phase-1 exit blocker.

Rationale: every other CI gate in this repo is a ratchet (freeze pre-existing
debt, fail on regressions). An absolute-zero Larastan bar would be the sole
exception, would gate Phase-1 exit on a multi-week static-analysis cleanup with
no Finance-blocking payoff, and would contradict the approved delivery model.
This decision makes the ratchet interpretation explicit and binding.

## Decision: the §24 authorization checkpoint is not closed by `authz-lint = 0`

**A green authz-lint is a false signal for §24.** The lint counts *commented-out*
authorization; the S5 rollout clears each commented check by restoring it as live
code that runs in **observe mode** (records a would-be denial, then continues —
never blocks). So authz-lint reaches 0 the moment the checks are restored, while
**no request is actually authorized** — enforcement is still off. Treating
authz-lint = 0 as the exit condition would mark §24 "done" over an app that
authorizes nothing.

**Binding: the §24 authorization checkpoint closes only when all four hold —**

1. `authz-lint = 0` (no commented authorization remains), **and**
2. `AUTHZ_ENFORCE=true` is set in the production environment, **and**
3. the observe-mode evidence (`authz_observations`) has been reviewed and every
   would-be denial classified as expected (not a legitimate-access regression), **and**
4. enforcement is verified *active* in production — a request lacking a required
   permission actually receives 403, confirmed by a live check, not by config alone.

Until all four hold, §24 authorization is **open**, regardless of the lint number.

### `AUTHZ_ENFORCE` is temporary rollout infrastructure (not permanent config)

`config/authz.php` (`AUTHZ_ENFORCE`, default `false`) and `App\Support\Authz`'s
two-mode gate exist **only** to roll authorization out safely (observe → analyse
→ enforce). This is scaffolding with a defined end, not a permanent feature flag:

- **Owning slice for removal:** the S5 enforcement slice (the one that sets
  `AUTHZ_ENFORCE=true` in production and closes §24 above).
- **Removal condition:** enforcement has been active and stable in production for
  one full release cycle with the observation table reviewed and quiet (no new
  unexpected denials), i.e. §24 closed per the four criteria above.
- **Planned deletion:** once that holds, delete the `enforce` branch from
  `App\Support\Authz` (checks become unconditional — fail = `abort(403)`), drop
  `config/authz.php`'s `enforce` key and the `AUTHZ_ENFORCE` env var, and prune +
  drop the `authz_observations` table (see ADR 0043). If enforcement instead
  becomes permanently config-toggleable, that reversal requires its own ADR — it
  must not become permanent-by-default drift.

`Authz` itself (the call-site shape) is likewise transitional; its long-term
disposition — fold into Laravel Policies/Gates, or keep as a thin wrapper — is
decided in **ADR 0043**, which also records that no new (Finance) business logic
may depend on `Authz`. The ordered **teardown sequence** (migrate every call to
its permanent home → verify no `Authz::` remains → delete wrapper → remove flag →
drop table + commands + schedule → delete rollout-only tests, keep the invariant
tests) is fixed in **ADR 0043 §5**. Deletion must never remove authorization:
each check is migrated before the wrapper is removed.

### `authz_observations` retention & pruning

- **Retention window: 30 days.** Rows older than 30 days are deleted daily by the
  scheduled `authz:prune --older-than=30` ([routes/console.php](../routes/console.php)) —
  the retention period lives here (roadmap + schedule), not only as a command
  default. At rollout teardown the table is dropped entirely (ADR 0043 §5).
- **Data minimization:** observations store the request **path only**
  (`getPathInfo()`), never `fullUrl()` — no query string, so no PII from query
  parameters enters the table (ADR 0043 §4). It is rollout evidence, not an audit
  log.
- **Pruning is scheduled, not merely available:** without a schedule an evidence
  table becomes permanent telemetry by default, which ADR 0043 §4 forbids.

### Scheduler is the first-and-only scheduled task — a Phase-2 gap

`authz:prune` (2026-07, this slice) is the **first scheduled task in the
codebase**; before it, no `withSchedule()` / `Schedule::` existed. The
registration point is now `routes/console.php`. **Gap (owning slice: a Phase-2
"scheduling infrastructure" slice, not built now):** Phase 2+ assumes ambient
scheduled work that does not yet exist — revenue recognition on term start (§9),
dunning reminders (§11), the ledger drift-verification job (§12.2), reconciliation.
Those are **School-scoped** and per §5.4 must iterate Schools via
`ActiveSchool::runFor()`; `authz:prune` is exempt only because it deletes by age
and reads no School-owned data. The scheduling *mechanism* (a running
`schedule:run` cron / `schedule:work`) must be provisioned in the deploy
environment before any Phase-2 scheduled job is relied upon.

**Deployment prerequisite — the scheduler must actually run (registration ≠
execution).** `schedule:list` proves the task is *registered*; it does not prove
the OS invokes it. Exactly one of the following must be configured in the
deployment environment, and its execution **verified** (e.g. observe an
`authz:prune` run in the logs, or a scheduled `->onSuccess()`/health ping):

- **cron** (one entry, calls the runner every minute):
  `* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1`
- **Supervisor / systemd** long-running worker (alternative to cron):
  `php artisan schedule:work` under a process supervisor that restarts it.
- **Platform scheduler** (Forge "Scheduler", a Kubernetes CronJob, Envoyer/
  Vapor scheduler, etc.) configured to run `schedule:run` every minute.

**This is an environment concern the repository cannot prove.** There is no cron
manifest, Procfile, systemd unit, or platform-scheduler config committed; the repo
only proves *registration* (`routes/console.php` + `ScheduleTest`). Whether
`schedule:run` is actually invoked in any environment is **not determinable from
this repository — do not infer it is.** **Binding: observe mode must not receive
production traffic until scheduler execution has been verified in that
environment** — otherwise `authz_observations` grows unbounded (§5b(b) is solved
only on paper) and the data-minimization/retention guarantees are void.

### S7 — remove `users.school_id` + `school_user` (execution plan + runtime-zero gate)

The last mechanical §24 item (1.2f remainder). **The runtime dependency graph
below shows runtime-zero is NOT yet satisfied — the schema migration is therefore
prohibited** (hard gate). Every executable reference must first be removed,
repointed, or justified.

**Runtime dependency graph (executable references, verified 2026-07):**

*`users.school_id` (read/write):*

- `ActiveSchool::id()` [:54-55](../app/Support/ActiveSchool.php#L54) — the fallback source (source 3). **Remove (S7 Step 3).**
- `ActivitySchoolResolver` — **DONE (S7 Step 2):** now reads through `ActiveSchool::id()`; the direct `auth()->user()->school_id` read is gone.
- `SuperAdmin\AdminController` [:104-105](../app/Http/Controllers/SuperAdmin/AdminController.php#L104) — reads + **writes** the column (maintenance). **Delete (S7 Step 4).**
- `TeacherService` [:69](../app/Services/TeacherService.php#L69) — **writes** `users.school_id` on teacher creation (from `TeacherRequest`'s validated `school_id`). **Repoint** to a role/pivot grant before the column drop.
- `User` `$fillable` [:30](../app/Models/User.php#L30) + `computeAccessibleSchoolIds()` legacy branch [:142](../app/Models/User.php#L142) + `school()` relation [:246](../app/Models/User.php#L246). Legacy branch is bypassed when `rbac.single_source_access=on`; `$fillable`/relation removed at column drop.
- Commented ownership checks: [StudentSubjectController:227](../app/Http/Controllers/StudentSubjectController.php#L227), [StudentCurriculumController:67](../app/Http/Controllers/StudentCurriculumController.php#L67) — **delete** at drop (do not migrate).
- Frontend type `auth.ts` `school_id: string` [resources/js/types/auth.ts](../resources/js/types/auth.ts) — remove at drop; audit consumers.

*`school_user` pivot (read/write):*

- `User::schools()` belongsToMany + `computeAccessibleSchoolIds()` legacy branch — bypassed by the single-source flag; removed at drop.
- **Direct data-visibility readers (independent of the flag — the load-bearing risk):** `GuardianService` [:38](../app/Services/GuardianService.php#L38) join, `Teacher` [:54](../app/Models/Teacher.php#L54) subquery, `Guardian` [:93](../app/Models/Guardian.php#L93) subquery. These scope guardian/teacher multi-school visibility off `school_user` and are **not** covered by flipping `single_source_access`. They must be repointed to `model_has_roles` (the backfilled single source) **before** the pivot is dropped.
- `TeacherSchoolAccessController` (writes grants) + frontend `school-checklist.tsx` / `manage-teacher-schools-dialog.tsx` (manage grants) — repoint grant-writes to the single source.
- Backfill migration `2026_07_14_000002_backfill_guardian_school_access.php` — historical; leave.

**Runtime-zero verification method:** `grep -rn --include='*.php' 'users?\.school_id\|->school_id\b\|school_user' app/ database/ routes/` restricted to executable lines (exclude comments/tests/backfill), cross-checked against a grep for `->schools()` and `auth()->user()->school_id`. **Current result: NOT zero** — the readers above remain. An arch test asserting zero `school_user` / `user->school_id` executable references should be the machine-verifiable gate before the migration.

**Sequence (each step independently mergeable; STOP for review before any
destructive change):** Step 2 (ActivitySchoolResolver) ✅ done · Step 3 remove
`ActiveSchool` fallback · Step 4 delete `AdminController:104` write · repoint
`TeacherService`/`GuardianService`/`Teacher`/`Guardian`/`TeacherSchoolAccess` off
the legacy sources · Step 5 enable `rbac.single_source_access` · Step 6 **parity
soak** (dual-compute, see below) · Step 7 rollback rehearsal · **Step 8 STOP** ·
then column drop (working `down()`, ADR 0042 expired, boundary-lint baseline 5→1).

**Parity soak must be dual-compute, not flag-flip.** `accessibleSchoolIds()` memoizes
keyed on `single_source_access` (S3), so a single request derives one path. Real
per-user divergence is only caught by computing **both** paths for the **same**
request and logging mismatches (user · School · old · new · source · reason) —
a flag-flip between runs compares different traffic and misses it. **Coverage
clause:** zero mismatches over zero (or unrepresentative) decisions is not
evidence; the soak log must show every user category (single/multi-School, super
admin, zero-access), ≥2 Schools, and both HTTP and queue transports, and must
report the decision count + distribution. **Zero mismatches *with* coverage is the
only condition permitting the migration.**

**Near-miss — a flag proves parity only for the paths it controls.**
`rbac.single_source_access` gates `accessibleSchoolIds()`, but three `school_user`
readers (`GuardianService`, `Teacher`/`Guardian` scopes) sat **outside** the
flag. A parity soak would have measured the controlled path, reported green, and
the column drop would then have broken guardian/teacher visibility in production.
**Lesson (Phase 2 will use flags for exactly this pattern): enumerate every
reader of a source before trusting a flagged rollout — control ≠ coverage.** Fixed
in this slice by funnelling the three readers through `App\Support\SchoolAccess`,
which is gated by the same flag, so the soak now covers them.

**S7 progress (this slice — no schema change; runtime-zero still closed):**

- **Runtime-zero gate landed + CI-wired** (`bin/ci-runtime-zero-lint.php`,
  `runtime-zero-baseline.txt`, composer `lint:boundaries` + `lint.yml`). Fails on
  any NEW executable `users.school_id` / `school_user` reference; baseline
  **6** (was 8 before the reader consolidation), ratcheting to **0** at the column
  drop (its expiry). Coverage caveat: it catches literal reads + the pivot table
  name + raw `users.school_id`; the two column **writes** (`AdminController`
  forceFill, `TeacherService` `User::create`) and Laravel's implicit
  `belongsToMany` pivot (`User::schools()`) are tracked here in the graph, not by
  the regex.
- **Divergence count (§1) = 0 in dev** (`school_user` 776 rows / `users.school_id`
  846 set → 0 orphans without a matching `model_has_roles` row). **Production must
  reconfirm before the flag flip** — dev is not authoritative.
- **Parity instrument built + bite-proven** (`App\Support\SchoolAccessParity`,
  `rbac.parity_soak`): dual-computes both paths per decision, logs `lost`/`gained`
  per `(user, School)`; a test injects a known divergence and asserts detection
  with all fields.
- **Readers repointed** (flag-gated via `SchoolAccess`); **Step 2 (audit
  resolver)** already landed.
- **§5 — `ActiveSchool::id()` null-dependants (before source-3 removal):** callers
  that correctly want `null` with no principal and do **not** rely on the
  `users.school_id` fallback — `SchoolScope` (console/worker), `SetSchoolContext`
  (drives select-school), `HandleInertiaRequests` (guarded by `$user`),
  `ActivitySchoolResolver` (falls through). Single-school request readers
  (`(int) ActiveSchool::id()` in controllers) are covered by the **session**
  school_id set at login ([SchoolAwareLoginResponse:45](../app/Http/Responses/SchoolAwareLoginResponse.php#L45)),
  so source-3 is a redundant safety net — but any authenticated path with neither
  session nor token school_id must be audited before Step 3. **Source-3 NOT
  removed in this slice.**
- **§2 — writer semantics (proposed; NOT implemented — stop for review):** the two
  `users.school_id` writers need an intent decision, not a translation.
  `TeacherService` writes the column on teacher creation from `TeacherRequest`'s
  `school_id`; the proposed replacement is **teacher creation grants a role in
  that School** (`model_has_roles` row) — a behaviour change (creation now confers
  a role). Which role? Presumably `teacher` in the selected School. `AdminController`
  [:104-105](../app/Http/Controllers/SuperAdmin/AdminController.php#L104) *maintains*
  the column (resets a user's home School when it leaves their accessible set);
  once the column is gone this maintenance has **nothing to maintain** and should
  **simply delete**, not translate. Both need sign-off before implementation.

### S7 writer semantics — teacher creation already IS School assignment (§1 evidence)

Investigated before proposing any writer change (do not infer from the column write):

- **What creates a teacher:** `TeacherService::processTeacherAccount` (single) and
  the bulk-import path (`~line 155`). Both, inside one DB transaction:
  `User::create([... 'school_id' => ActiveSchool::id() ...])` → **`$user->assignRole('teacher')`** →
  `store()` (the `teachers` row).
- **When a teacher becomes authorized to a School:** at `assignRole('teacher')` —
  in spatie teams mode this writes a `model_has_roles` row in the **active team**
  (the admin's School, set by `SetSchoolContext`). **That row is the access
  grant.** Additional Schools are granted via
  `TeacherSchoolAccessController` → `grantSchoolAccess($school, 'teacher')`.
- **Can a teacher exist before School access?** No — creation atomically assigns
  the role in the same transaction.
- **Is the `users.school_id` write a business rule or legacy compat?** **Legacy
  compat.** Access is already conferred by `assignRole`; `users.school_id` is a
  redundant home-School stamp on the *User*. It is distinct from
  **`teachers.school_id`** (the Teacher model's own home-School column, a
  `BelongsToSchool` field that is **NOT** part of S7 and stays).
- **Proposed writer change (still STOP-for-review, not implemented):** delete
  `'school_id'` from the two `User::create(...)` calls — **no new role grant is
  needed, it already exists.** One implementation guard: `assignRole` must run
  with the correct permissions-team set (true on the request path today); the
  writer slice must assert/So set the team explicitly so an off-request create
  path can never write a null-team (global) role row.

### S7 divergence-prevention — the assignRole team invariant + writer disposition (§2)

The objective is not that divergence is zero today but that the app can no longer
**create** it tomorrow. Two mechanisms:

**1. Permanent invariant (landed):** `User::assignRole` is overridden to throw
`NullTeamRoleAssignmentException` if a school-scoped role is assigned with a null
permissions-team (`super_admin` exempt). A null-team role grants access to no
School — the precise divergence S7 removes. Enforced at the model so no call site
can bypass it, on request or off (proven by `AssignRoleTeamInvariantTest`,
including the `runFor` off-request case). It surfaced one real test-setup bug
(`GuardianProfileTest` helpers assigned roles with no team — fixed to establish
context; they had only "passed" via team-state leaking between tests).

**2. Every writer to a legacy source — location · disposition:**

| Writer | Source | Writes a role too? | Divergence-capable? | Disposition |
|---|---|---|---|---|
| `TeacherService` `User::create` (×2) | `users.school_id` | Yes — `assignRole('teacher')`, now team-guaranteed | No | delete the `school_id` key (writer change, gated on prod count) |
| `GuardianService::enableLogin` scenario-1 `User::create` | `users.school_id` | Yes — `assignRole('guardian')` + a Guardian record | No | delete the `school_id` key at the writer change |
| `SuperAdmin\AdminController:105` `forceFill(['school_id'])` | `users.school_id` | **No** — resets the column only | **Yes** (column without role) | **delete** — nothing to maintain once the column is gone |
| `User::grantSchoolAccess` `schools()->syncWithoutDetaching` | `school_user` | Yes — role + pivot written together | No | delete the pivot write at the column drop (role write stays) |
| `User::revokeSchoolAccess` `schools()->detach` | `school_user` | removes role + pivot together | No | delete the pivot side at the column drop |
| `createGuardianWithUser` / attach path → `grantSchoolAccess('guardian')` | `school_user` (+ role) | Yes (via grantSchoolAccess) | No | pivot side removed at drop |
| backfill migration `2026_07_14_000002` | `school_user` | historical one-time | No | leave (history) |
| `Api\AuthenticationController` `forceFill(['school_id'])` (×2) | **`personal_access_tokens.school_id`** — NOT `users.school_id` | n/a | No | **out of S7 scope** (token column, not the user column) |

**Guardians (explicitly checked, §2):** guardian access is written as a Guardian
record **plus** a role — via `grantSchoolAccess('guardian')` (create/attach) or a
team-guaranteed `assignRole('guardian')` (enableLogin). Both sides are written
together, so no guardian path creates divergence. `enableLogin`'s bare
`assignRole('guardian')` is correct-by-context (the Guardian is `SchoolScope`d to
the active School, so the ambient team is the guardian's School) and is now
additionally null-team-guarded by the invariant; converting it to
`grantSchoolAccess` for symmetry is a recommended (non-blocking) tidy.

**Conclusion:** after the invariant, the **only** divergence-capable writer is
`AdminController:105` (column-only reset), whose disposition is deletion. No code
path can create a null-team role or a role/legacy-source mismatch once the writer
change + that deletion land.

**CORRECTION (2026-07, empirically verified) — the writer deletion is NOT
flag-independent.** The premise "the column write is redundant to a role grant"
holds only under `single_source_access = ON`. Under the **legacy path (flag OFF,
the production default)**, `accessibleSchoolIds()` does **not** read
`model_has_roles`; for two writers the column is load-bearing:

- **`TeacherService` (×2):** a teacher created role-only (no `users.school_id`,
  and teacher creation writes **no** `school_user` pivot) resolves to an **empty**
  accessible-school set under the legacy path — proven in tinker: flag OFF → `[]`,
  flag ON → `[13]`. An empty set means `SchoolAwareLoginResponse` rejects the
  login. Deleting this write while the flag is off **strips new teachers' login.**
- **`AdminController:105`:** it *resets* `users.school_id` when a home-School is
  revoked, precisely so the legacy fallback (`if ($this->school_id) push`) stops
  granting the revoked School. Deleting it leaves a **revoked School still granted
  via the fallback** — a security regression under the legacy path.
- **`GuardianService::enableLogin`:** SAFE to delete — guardians resolve via the
  guardian-record branch of the legacy union (proven: flag OFF → `[14]` with no
  column), so the column is redundant for guardians.

There is **no** role↔column/pivot sync listener (checked `app/Listeners`,
`app/Observers`), so the legacy path genuinely depends on the column.
**Corrected disposition:** the teacher and AdminController writes must be
**flag-gated** (write only while `single_source_access` is off) or **coupled to
the flag flip**, not deleted unconditionally. Deleting them now would regress
production. Held for review; only the guardian removal is safe today.

### S7 production divergence snapshot — the command (§3b/§1)

`php artisan s7:divergence-snapshot [--json]` (read-only) is the baseline-snapshot
gate. It counts users whose access came from a legacy source but was never
mirrored into `model_has_roles`, across **all three** sources
(`school_user`, `users.school_id`, guardian records), grouped by School,
**excluding super_admin** (team-less by design). Emits `taken_at` / environment /
database / per-source counts / total; exit 0 = PASS, exit 1 = STOP. Run against
**production or a fresh untouched snapshot** immediately before enabling the
parity instrumentation (dev is the wrong sample — sources seeded together agree by
construction). Non-zero is a **review STOP** (a backfill grants real access to
real people), never a mechanical fix. Locked by `S7DivergenceSnapshotTest`.

### S7 architectural corrections (2026-07)

- **§0 — `accessibleSchoolIds()` is EITHER/OR, not a union with roles.**
  `computeAccessibleSchoolIds()` returns `schoolIdsFromRoles()` (roles only) when
  `single_source_access` is ON, **else** `legacyAccessibleSchoolIds()` — which is
  `school_user` pivot ∪ guardian records ∪ `users.school_id`, and **does not read
  roles**. The two paths are mutually exclusive. This is why a role-only user
  resolves to `[]` under the flag-off legacy path. Any earlier phrasing implying
  the legacy branch *unions* `schoolIdsFromRoles()` is wrong; the model docblock
  is corrected to state the either/or explicitly.
- **§2 — writer strategy is Option B (not A).** Leave ALL compatibility writers
  (`TeacherService` ×2, `AdminController:105`, `GuardianService::enableLogin`)
  untouched until the column-drop slice; delete them together there. Option A
  (flag-gated writes that self-disable at the flip) is **rejected**: a teacher
  onboarded *after* the flip would have no column value, so a flip-back (soak
  surprise / prod ≠ staging) drops them to `[]` and rejects login — Option A makes
  the flag one-way for everyone onboarded after it, destroying the reversibility
  that is the point of expand/contract. Option B keeps the column populated until
  the schema actually changes, so the flip stays reversible. The guardian
  `enableLogin` write is safe to remove independently but is **not** removed early
  — that would fragment the writer set for one line that dies at the drop anyway.
  `AdminController:105` is reclassified: not maintenance but the control that
  stops the legacy fallback granting a revoked School — it dies **with** the
  fallback.
- **§3 — every role assignment flows through the invariant; no bypass.** All
  `assignRole` call sites are on `User` instances, so the `User::assignRole`
  override (the team invariant) governs them; the only direct `model_has_roles`
  access is a **read** (`AdminController:21`). No `syncRoles` / `roles()->attach` /
  direct role INSERT exists. Two seeders (`TeacherSeeder`, `GuardianSeeder`)
  assigned roles with no team — the invariant correctly caught them; fixed to
  establish context (`UserSeeder` already did). The dead, unrouted
  `AuthenticationController@register` `assignRole('admin')` would throw under the
  invariant if ever wired — a latent bug the invariant surfaces, left as-is
  (registration is disabled).
- **§4 — runtime-zero gate is now two sections.** *Section A* = application
  references (must reach 0 before the drop) — baseline **12**. *Section B* =
  migration tooling that legitimately references the legacy schema
  (`S7DivergenceSnapshot`, `SchoolAccessParity`) — reported separately, **never**
  gates Section A, expires with the S7 teardown. Section B = 3.
- **§7 — hidden write-path audit.** Sweep of observers, jobs, console commands,
  factories, seeders, listeners, imports, exports, notifications, scheduled tasks,
  services, traits: the ONLY writers to `users.school_id` are `TeacherService` ×2,
  `GuardianService::enableLogin`, `AdminController:105`, and the seeders
  (Teacher/Guardian/User — seed data). The ONLY writers to `school_user` are
  `User::grantSchoolAccess` (`schools()->syncWithoutDetaching`) and
  `revokeSchoolAccess` (`detach`). No hidden path in any other layer. A final
  re-sweep is required immediately before the drop (§7 gate).

### Runtime-zero gate — blind spots + compensating controls (§2)

The gate is a grep; it cannot see everything. Each blind spot and its control:

| Blind spot | Detected? | Compensating control |
|---|---|---|
| Explicit `school_user` string | **Yes** (pattern) | — |
| Implicit `belongsToMany(School)` pivot + `->schools()` consumers | **Yes** (patterns added §2) | the gate now fails while any `->schools()` call remains — it cannot report 0 with the pivot still resolved |
| `$user->school_id` / `->user()->school_id` reads, raw `users.school_id` | **Yes** (patterns) | — |
| `$this->school_id` in User.php | **Yes** (file-scoped pattern) | — |
| Column **writes** (`AdminController` forceFill, `TeacherService` `User::create`) | **No** (would over-match `'school_id' =>` everywhere) | **boundary-lint** (`school-id-fallback-context` + maintenance-write) + the writer disposition in the readiness matrix below |
| Dynamic property access (`$model->{$attr}`, `getAttribute('school_id')`) | **No** | code review + the readiness matrix; `ActivitySchoolResolver::schoolIdOf` uses `getAttribute('school_id')` polymorphically and is intentionally model-agnostic (not a users.school_id reference) |
| Dynamically-built SQL / raw `DB::statement` string interpolation | **No** | none automated — none exists today (grep for `DB::statement`/`DB::raw` with `school_user` is empty); a reviewer check at the writer/drop slice |
| Reflection / container resolution | **No** | none exists; no `school_id`/`school_user` is resolved by string via the container |
| Vendor callbacks (spatie relation naming) | Partially | the `belongsToMany(School)` + `->schools()` patterns catch the app-side wiring; spatie's internal pivot name derivation is covered by removing the relation |
| Frontend (`auth.ts` `school_id`) | **No** (PHP-only lint) | tsc + the readiness matrix (delete at drop) |

### S7 parity-soak exit criteria (§3) — required BEFORE `RBAC_SINGLE_SOURCE_ACCESS` in staging

Parity is "complete" only when the soak log (`school-access-parity` channel) shows:

- **Coverage — every category must appear** (count per category reported, not just the total):
  single-School user, multi-School user, super admin, zero-access user; **≥2
  Schools**; transports **HTTP and queue** (scheduled if any School-scoped
  scheduled job exists by then).
- **Minimum volume:** ≥1 observation per (category × transport) cell, and enough
  distinct users that every active School appears at least once. Report the
  decision count and its distribution — **zero mismatches over thin coverage is
  not evidence.**
- **Threshold:** **zero unexplained mismatches.** Any `lost` row blocks (a
  revocation risk → backfill). Any `gained` row must be explained (usually a role
  granted after the legacy source lapsed) or blocks.

### S7 production divergence count (§3b) — a GATE, not a re-confirmation

The dev-DB 0/0 result is **not evidence** — dev's `school_user` and
`users.school_id` were seeded together, so they agree by construction (wrong
sample). Drift only accumulates in production, and **the soak cannot substitute
for this count**: the soak only sees users who generate traffic in the window; a
teacher who does not log in that week is invisible to it but still loses access at
the drop. **Run against production, before the soak:**

```sql
-- (a) school_user rows with no matching model_has_roles row
SELECT su.school_id, COUNT(*) AS orphan_pivot_rows
FROM school_user su
LEFT JOIN model_has_roles mhr
  ON mhr.model_id = su.user_id AND mhr.model_type = 'App\\Models\\User'
 AND mhr.school_id = su.school_id
WHERE mhr.role_id IS NULL
GROUP BY su.school_id;

-- (b) users.school_id values with no matching role row
SELECT u.school_id, COUNT(*) AS orphan_home_school
FROM users u
LEFT JOIN model_has_roles mhr
  ON mhr.model_id = u.id AND mhr.model_type = 'App\\Models\\User'
 AND mhr.school_id = u.school_id
WHERE u.school_id IS NOT NULL AND mhr.role_id IS NULL
GROUP BY u.school_id;
```

Non-zero → a **backfill decision** (the single largest S7 risk), resolved before the soak.

### S7 column-drop readiness matrix (§4) — every runtime reference classified

The migration cannot begin until every row is `already removed` or has a landed
disposition **and** `runtime-zero-lint` reports 0.

| File / ref | Purpose | Owner slice | Disposition |
|---|---|---|---|
| `ActiveSchool::id()` source-3 (`$user->school_id` ×2) | single-School context fallback | S7 Step 3 | **delete** (redundant with session/token; §5 gates) |
| `User.php` `computeAccessibleSchoolIds` legacy branch (`$this->school_id`, `->schools()`) | legacy access union | column-drop slice | **delete** (single-source is the path) |
| `User.php` `belongsToMany(School)` + `schools()` (sync/detach/pluck) | pivot relation + grant writes | column-drop slice | **delete relation**; grant writes move to role-only (`grantSchoolAccess` already writes roles) |
| `SchoolAccess` `from('school_user')` branch | flag-off reader path | column-drop slice | **delete** the else branch (flag-on model_has_roles path remains) |
| `AdminController:104` read + `:105` forceFill write | home-School maintenance | writer slice | **delete** (nothing to maintain once the column is gone) |
| `TeacherService` `User::create([...'school_id'])` ×2 | legacy home-School stamp | writer slice | **delete the `'school_id'` key** (role grant already present) |
| `TeacherSchoolAccessController` `->schools()->get()` / `grantSchoolAccess` | extra-School grants | column-drop slice | repoint reads to roles; grant writes already role-based |
| commented `users.school_id` ownership checks (StudentSubject/StudentCurriculum) | dead ownership guards | column-drop slice | **delete** (redundant with SchoolScope) |
| `auth.ts` `school_id: string` | frontend user type | column-drop slice | **delete** + audit consumers |
| the 4 nested parent-child integrity checks | route integrity | — | **retain** (not redundant with SchoolScope) |

### S7 ADR regression gates (§5) — assert when source-3 is removed

When Step 3 lands, these become explicit regression tests (not assumptions):

- HTTP context resolves **only** from session/token School context (no
  users.school_id) — a request with neither yields no context, not a stale home
  School.
- Queue workers still honour `ActiveSchool::runFor()` (extend
  `ActivitySchoolResolverTest` / `NoTeamLeakBetweenJobs`).
- Console commands needing School context **fail closed** unless explicitly
  iterating Schools (extend `SchoolScopeFailsClosed`).
- Activity logging attributes to the effective School, not the authenticated user
  alone (`ActivitySchoolResolverTest` already covers runFor; add a
  session-vs-user divergence case).

### Role-gate inventory (§7.2 debt — running code, confirmed 2026-07)

The result/enrollment maker–checker workflow authorizes by **role**, in
violation of Constitution §7.2 (authorize by permission, never role). This is the
authoritative inventory; the permission model that replaces it is designed in
**ADR 0044** (implementation is a later slice).

- **Live `hasRole()` in FormRequests (enforcing now):**
  [RejectSubjectResultRequest](../app/Http/Requests/RejectSubjectResultRequest.php),
  [PromoteStudentRequest](../app/Http/Requests/PromoteStudentRequest.php),
  [UpdateStudentCurriculumStatusRequest](../app/Http/Requests/UpdateStudentCurriculumStatusRequest.php),
  [RegisterStudentCurriculumRequest](../app/Http/Requests/RegisterStudentCurriculumRequest.php)
  — all `admin || head_of_school`.
- **Live role-gate:** `PrincipalController@…` `abort_unless($principal->hasRole('principal'), 404)`.
- **Commented role checks (dormant, in the authz-lint baseline):**
  `CurriculumSubjectController` submit (`isTeacher`), approve / reject
  (`isReviewer`), `StudentCurriculumController@getScoresWithMarkingComponents`.
- **NOT authorization — presentation/relationship branching (leave; confirmed
  none is a security decision):** `DashboardController@…` guardian/teacher landing
  branch, `CurriculumController` / `StudentController` "guardian viewing their own
  child" checks, `GuardianService` guardian-relationship branch. These choose what
  to *show*, not whether to *permit*; the actual gate is the route `role:`
  middleware + FormRequest authorize on those endpoints.
- **Already correct (the convention to match):**
  `student_curriculum.unenroll` authorizes by permission
  ([UnenrollStudentRequest](../app/Http/Requests/StudentSubject/UnenrollStudentRequest.php)).

Re-audit is deferred to **after the implementation slice** (a design does not
change what is in the code).

## Governance — current state (not intent)

- CI: `linter` + `tests` workflows run on PRs to `staging`/`main`.
- **Branch protection / required status checks are not confirmed as enabled**
  on GitHub; merges are performed by the maintainer after review. Enabling
  protection is an outstanding GitHub-settings action (v10 §17.3), not a repo
  change.
- `plan_docs/` is untracked by design; this page and the ADRs are the
  in-repo record.
