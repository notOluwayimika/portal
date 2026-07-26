# Finance Module — Phase 1 (Engineering Foundation) Execution Plan

## Context

`docs/Finance Module — Implementation Master Plan - v10.md` is the approved source of truth: an
Invoicing & Receivables **financial control system** (segregation of duties, maker–checker, immutable
audit, period locking, deferred income, Sage 50). The spec is explicit that **the platform cannot
support those controls today** and mandates a **6-week Engineering Foundation (Phase 1)** that repairs
and hardens the platform *before any Finance code exists*. Phase 1 blocks everything.

This plan covers **Phase 1 only** (sub-phases 1A–1F). It does not redesign the approved architecture,
ADRs, Shared Kernel, Module Blueprint, or Constitution — it sequences their delivery. I verified every
material claim in the spec against the live codebase (three parallel code sweeps). The debt is real and
in places worse than the spec states; a handful of the spec's *numbers* are stale and are corrected below
so we build against reality, not the document.

**Locked decisions (from clarification):**
- **Test DB:** standardize the *entire* suite on **MySQL** (the SQLite default currently fails outright —
  INFORMATION_SCHEMA migrations don't run on SQLite).
- **Observability:** **Sentry** (error tracking + failed-job alerting) + **Laravel Horizon** (queue
  dashboard). Horizon requires **Redis** — Redis therefore becomes a Phase 1 infra dependency (also
  satisfies Risk #8's Redis recommendation for cache/session/queue).
- **Package manager:** **pnpm** (matches recent commit history); delete `package-lock.json`.

---

## Phase 1 Gap Analysis (verified against live code)

### Existing (usable foundations — do not rebuild)
- `App\Support\ActiveSchool` with `id()` + `getOrFail()` — but **no `runFor()`**, and `id()` itself
  embeds the `users.school_id` fallback ([app/Support/ActiveSchool.php:41](app/Support/ActiveSchool.php#L41)).
- `SchoolScope` + `BelongsToSchool` trait exist and auto-fill `school_id` on `creating` (when authed).
- Spatie **teams mode enabled** (`team_foreign_key => school_id`); custom **uuid-bearing** `App\Models\Role`
  / `App\Models\Permission`. `SetTenantContext` middleware already calls `setPermissionsTeamId()` +
  `canAccessSchool()` (behaviour correct; needs rename to `SetSchoolContext`).
- `composer ci:check` exists and *already includes* `types:check` (tsc) — it is simply **orphaned** (no
  workflow calls it).
- `accessibleSchoolIds()` / `canAccessSchool()` already exist on `User` (need to be re-pointed at a single
  source). Custom `App\Models\Role/Permission` are correct base for the Permission enum.
- `ResolvesTermFilter` is **already School-scoped** (spec's "first active term across all Schools" bug is
  stale/fixed) — a direct `terms.school_id` is still wanted, but the acute bug is gone.
- 13 domain enums, `HasAdmissionNumber`/`HasStaffNumber` traits, `Notifications` channel scaffolding.

### Missing (greenfield — must be built in Phase 1)
- **`.env.example`** — absent; CI's `cp .env.example .env` step fails, so **CI has never been able to run**.
- `ActiveSchool::runFor()` (with `finally` restore + `setPermissionsTeamId` + `unsetRelation('roles')`);
  `SchoolAware` job middleware.
- Any **Policy** class (0 exist) and any **`Gate::`** usage (0) — including the `Gate::before` super-admin bypass.
- **`Permission` enum** + single wired seeder + a test asserting the exact Permission/Role set.
- Entire **Shared Kernel primitive set**: `Money` VO + `MoneyCast` + `formatNaira()`, `Sequences`,
  `Approvals` (polymorphic `ApprovalRequest`), `Idempotency` table + middleware, `FeatureFlags`, `Pdf` engine.
- **Domain event bus** + first events (`StudentEnrolled`, `StudentWithdrawn`, `TermStarted`, `TermClosed`).
  No `app/Events/` dir exists today.
- `students.status` (+ `left_at`, `leave_reason`, `index(school_id,status)`); `schools.timezone` + working hours.
- **Observability** (Sentry + Horizon + Redis + `failed_jobs` alerting) — none installed.
- Arch-test suite (`pest-plugin-arch`) + lint gates; Larastan; `SchoolFactory` + factories for Finance-touched models.
- README / CONTRIBUTING (the Constitution) / CLAUDE.md / `docs/module-blueprint.md` / ADRs 0001–0035.

### Technical debt (must be resolved before Finance)
- **Live cross-School IDOR** — `downloadExport` has permission check, filename regex, and existence check
  all commented; unvalidated `$filename` → `Storage::download`
  ([app/Http/Controllers/ActivityLog/ActivityLogController.php:269-287](app/Http/Controllers/ActivityLog/ActivityLogController.php#L269)).
- **53 commented-out authorization checks** across **7** controllers (spec said 52/5): Guardian (16),
  CurriculumSubject (8), StudentSubject (8), ActivityLog (8), GuardianImport (6), SavedActivityFilter (4),
  StudentCurriculum (3).
- **19 permissions never seeded** (Guardian 10 + StudentSubject 9). Total defined is **28**, not 32; and
  **3** seeder calls are commented in `ArmsDatabaseSeeder` (ActivityLog is nonetheless seeded directly via
  `DatabaseSeeder`, so net-unseeded = Guardian + StudentSubject).
- **7-route public leak** (spec said 6): routes declared above the auth group at `routes/api.php:29-57`
  win over their re-declarations at `:216-229`; the "gated per-endpoint" comment at `:226-227` is false
  (those gates are the commented-out `activity_log.*` checks).
- **Hardcoded "Secondary School"** self-registration in `CreateNewUser:29` and `AuthenticationController:32`
  (assigns `admin`).
- `SchoolScope` **fails open** — unscoped when no auth/context, and `catch (\Throwable)` swallows and skips
  the `where`. 4 escape hatches unguarded (`withoutGlobalScope`, `?? $user->school_id`, `auth()->setUser`,
  `DB::table()`).
- **6/7 jobs impersonate the causer** (`auth()->setUser($causer)`) and **never** call `setPermissionsTeamId`
  → async runs in whatever team the worker last held.
- `config/permission.php` `events_enabled => false` (RBAC changes leave no audit trace).
- `terms` has no `school_id` (scoping only transitive via `academic_session`). `ClassLevelArm` imports
  `BelongsToSchool` but never applies it and omits `school_id` from `$fillable`; `MarkingComponent` is also
  unscoped (has `school_id` in fillable but no trait — differs from spec).
- `guardian_student` has only `unique(guardian_id, student_id)`; **no same-School constraint** (pivot has
  no `school_id` at all).
- **Broken test infra:** `phpunit.mysql.xml` targets `portal-live` as `root`; both factories broken
  (`UserFactory` writes non-existent `name` column; `GuardianFactory` calls `School::factory()` but `School`
  lacks `HasFactory`, and no `SchoolFactory` exists). Committed UTF-16 `tsc_errors.log`. Two lockfiles.
- **CI is not a gate:** `.env.example` missing; `tsc` never runs in CI; lint.yml runs fixers then discards
  them (commit step commented). Queue `after_commit => false`, `retry_after=90` (vs long-running jobs).
  `HasAdmissionNumber` is racy (read-then-write, no lock, no unique index).

### Refactoring (behaviour-preserving moves)
- Rename `SetTenantContext` → `SetSchoolContext` (behaviour unchanged; terminology per §3 — "tenant" banned).
- Collapse the **five School-access sources** into `model_has_roles` via **expand/contract**, gated by a
  parity test before dropping `users.school_id` + `school_user`.
- Route `accessibleSchoolIds()`/`canAccessSchool()` at the single source; add caching + invalidation.
- Move the 5 jobs to `SchoolAware` (carry `public readonly int $schoolId`), remove `auth()->setUser`.

### Risks (Phase-1-relevant, from §22)
IDOR exploitable today (#1) → 1A first. Cannot lock a derived balance (#2) → resolved by design in Ph2 but
enforced-safe factories/tests land now. Silent failures (#3) → observability (1E). `forceCreate` bypasses
`MoneyCast` + SQLite≠MySQL (#5) → MySQL suite + ban `forceCreate`. Removing `users.school_id` strips access
(#12) → parity test gates the drop. Fail-closed scope breaks console/seeders (#14) → per-model rollout.
`Gate::before` may misbehave in Spatie 7.4 teams (#11) → verify vs vendor source first. `activitylog:clean`
deletes the trail (#10) → disable + DB-level DELETE deny. Cultural reflex to disable checks (#26) → the
commented-authz lint rule is the load-bearing mitigation.

### Dependencies
- **Internal ordering:** 1A and the CI-bring-up slice of 1B are the unblockers; 1C/1D/1E build on them;
  1F (arch tests + lint rules) must land *before* any Finance code (Phase 2).
- **New infra:** Redis (for Horizon/queue) + a Sentry project/DSN.
- **External (unblock in parallel, do not block Phase 1 merge):** accounting-policy sign-off, per-School
  bank accounts, invoice/receipt reference + gap policy, WCBS extract, Paystack sandbox, SMS provider,
  §18 backup ruling. These gate Phases 2/5/9/12/13/14, not Phase 1.

---

## Validation Review — Shared-Kernel classification, over-engineering & boundaries

*(Added after review. Preserves the approved §8 boundary; refines only the **timing** of primitives, not
their ownership or design. Deferred items still land in `app/Support` when built — deferral changes* when,
*never* where. *The §17.1 arch tests + §17.2 lint gates (M1.5) mechanically enforce the boundary before any
Finance code exists.)*

### A. Platform vs Finance — Phase-1 keep/defer

| Capability | Ownership | First real consumer | Phase-1 verdict | Justification |
|---|---|---|---|---|
| `Money` VO + `MoneyCast` + `formatNaira()` | Shared Kernel | Ph2 | **Keep (lean)** | Pure, zero-coupling VO (ADR 0002); Ph2 begins on it. Build VO+cast+tests only — no Finance columns/tables. |
| `Sequences` | Shared Kernel | **Ph1** + Ph5 | **Keep** | Has a *live Phase-1 consumer*: replaces the racy `HasAdmissionNumber`. A genuine prerequisite, not speculative. |
| `ActiveSchool::runFor()` + `SchoolAware` | Shared Kernel | **Ph1** (5 jobs) | **Keep** | The 6/7 impersonating jobs are unsafe today; whole-app benefit. |
| Domain **event bus** | Shared Kernel | **Ph1** | **Keep** | The seam that keeps Finance out of Academics (§13). |
| `StudentEnrolled/Withdrawn/TermStarted/TermClosed` | **Academics-published** platform facts | Ph5 (first Finance listener) | **Keep events, defer listeners** | Emit as Academics facts now (cheap, no coupling); **no Finance listener until Ph2+**. These are *not* Finance events. |
| Audit immutability (guards + `DELETE` deny + disable `activitylog:clean`) | Shared Kernel (cross-cutting) | **Ph1** | **Keep** | Protects the *existing* audit log today; whole-app. |
| Observability (Sentry + Horizon + Redis) | Platform | "before Ph6" (ADR 0031) | **Keep (early)** | Whole-app benefit; impersonating jobs are risky now. Latest safe date is pre-Ph6; standing it up now is cheap and de-risks every later phase. |
| `Permission` enum + seeder + assert-test | Platform *mechanism* | Ph1 | **Keep mechanism; defer `finance.*` entries** | Build the enum/seeder/test over the *existing* (non-Finance) permissions. `finance.*` vocabulary is added in Ph2 — no Finance terms in Phase 1. |
| `Idempotency` table + middleware | Shared Kernel | Ph5/6/12 | **Defer → Ph5** | No consumer for weeks; build in the Kernel when "record payment"/webhooks need it. Avoids speculative middleware. |
| `FeatureFlags` | Shared Kernel | Ph2 (per-School Finance flag) | **Defer → Ph2** | Its first use *is* gating Finance; build alongside Ph2 config. |
| `Approvals` engine (`ApprovalRequest` + state machine + limits) | Shared Kernel | Ph3 | **Defer → Ph3** | **Phase 3 is the approval-engine phase.** Nothing in Ph1 approves anything — building the engine now is the speculative-generality trap. |
| `Pdf` engine | Shared Kernel | Ph5 (invoice/statement) | **Defer → Ph5** | No Phase-1 consumer; templates are Module-owned; engine choice is ADR 0014 (Ph5), made when the first template exists. |
| 4 Finance roles (`accounts_officer`…) | Finance RBAC data | Ph2 | **Defer → Ph2** | Pure data rows, but Finance-specific vocabulary — seed with Finance config to keep Phase 1 Finance-agnostic. |

**Net Phase-1 Shared-Kernel surface:** Money · Sequences · event bus · `runFor`/`SchoolAware` · audit
immutability · observability. Every one has a Phase-1 justification. Everything Finance-specific (Ledger,
`LedgerPoster`, invoices, fee models, Finance events/services) is **Phase 2+** and appears nowhere in Phase 1.

### B. Preserve existing functionality — disposition (Reuse / Refactor / Replace / Leave)

| Component | Disposition | Breaking-change watch / note |
|---|---|---|
| `App\Support\ActiveSchool` | **Refactor** | Add `runFor()`; remove the `:41` `school_id` fallback. Single-school users who relied on it get context from `model_has_roles` backfill — gated by the parity test (M1.2). |
| `SchoolScope` | **Refactor** | Fail *closed*. Console/seeders/queue reads that relied on fail-open now throw → per-model rollout + explicit `withoutSchoolScope()` in seeders/migrations. |
| `BelongsToSchool` | **Refactor** | Off-request fill via `runFor` context (today only fills when `auth()->check()`). |
| `SetTenantContext` | **Rename** → `SetSchoolContext` | Behaviour untouched (§3 terminology only). |
| Spatie `Role`/`Permission` (uuid models) | **Reuse** as-is | Already correct; the Permission enum wraps them. |
| `accessibleSchoolIds()`/`canAccessSchool()` | **Refactor** | Single source + cache, behind the parity test. |
| `ResolvesTermFilter` | **Leave** | Already School-scoped. Only backfill `terms.school_id` + add a direct scope. |
| `MarkingComponent` / `ClassLevelArm` | **Refactor** | Apply the scope. Previously cross-visible rows become scoped → **data audit + `school_id` backfill before enabling**; rollback = drop the scope. |
| 5 jobs | **Refactor** | `SchoolAware` + drop `auth()->setUser`. Causer identity preserved via `schoolId`. |
| Controllers w/ commented authz | **Refactor per line** | Restore *or* intentionally delete + Policy — **decide per line**; some removals may have been deliberate. No blanket restore. |
| Factories · `phpunit.mysql.xml` · `tsc_errors.log` | **Replace/fix** | Broken artifacts. |
| `composer ci:check` + scripts | **Reuse** | Wire it up; do not rewrite. |
| Queue config | **Refactor** | `after_commit=true` changes dispatch timing → verify nothing depends on pre-commit dispatch. |
| 40+ models / services / controllers | **Leave untouched** | Opportunistic migration only (§8.4). No rewrite-for-consistency. |

### C. Boundary guarantee

Phase 1 introduces **only reusable infrastructure + repairs** — no `App\Finance` namespace, no `fee_*`/
`finance_*` tables, no Finance vocabulary (the deferred `finance.*` permissions and Finance roles land in
Ph2). The M1.5 arch/lint gates are the *mechanical* proof that Finance cannot leak into unrelated modules,
and they land **before** Finance code exists — so the boundary is enforced from the first Finance commit.

---

## Execution Plan — milestones (small, independently reviewable)

Each milestone: explain approach + affected files → implement → verify against acceptance criteria →
summarize + edge cases → **stop for review**. One milestone at a time. Conventional Commits with scope.

The six milestones are decomposed into **slices, each sized to complete + review in 1–3 days** (M1.2 and
M1.3 were multi-week and are split below). "Flag" = a temporary rollout flag (env/config, removed once
verified — *not* the Ph2 FeatureFlags service). Every slice's **Done-signal** is a measurable check; the
formal Definition of Done applies to all (see "Success criteria").

> **Engineering rule (binding on every slice):**
> **Every slice must be independently mergeable, releasable, deployable, and rollbackable. No slice may
> depend on another unfinished slice before it can be merged into the main branch.**
> *Mechanism:* branch each slice off `main`, merge in dependency order (a depended-on slice is **merged**,
> i.e. finished, before the next branches off it — never branch off an unmerged branch), and put every
> behavioral cutover behind a rollout flag so the code merges (flag off) without waiting on downstream work.
> This is enforced, not aspirational — see the "Independence verification" table.

### M1.0 — CI can run at all *(1B; prereq for all verification)*
| ID | Slice | Est | Depends | Flag | Done-signal (measurable) |
|---|---|---|---|---|---|
| **1.0a** | `.env.example`; **whole suite on MySQL**; make `phpunit.mysql.xml` unable to target a live-named DB; MySQL+Redis services in CI | 1–2d | — | — | `cp .env.example .env && pest` green locally; CI test job runs (not skipped) |
| **1.0b** | **Split gate** (not the monolithic `ci:check`): convert `lint.yml` to **check mode** — `pint --test`, `prettier --check`, `eslint` (no `--fix`) — **plus `tsc --noEmit`**; drop the commented auto-commit + unused `contents:write`. `tests.yml` keeps behavior+ratchet. Standardize on **pnpm** (delete `package-lock.json`). *(Branch triggers already fixed in 1.0c.)* | 1–2d | 1.0c | — | A style/type/format violation PR goes red; one lockfile remains; lint runs with no DB |
| **1.0c** | `SchoolFactory`+`HasFactory` on `School`; fix `UserFactory`/`GuardianFactory`; seed 4 Schools; delete `tsc_errors.log`; tsc baseline+ratchet; un-gitignore `docs/` | 1–2d | 1.0a | — | Each factory creates a valid row in a test; tsc baseline recorded; a +1 tsc error fails CI |

### M1.1 — Security hotfix *(1A; standalone patch, ship first)*
| ID | Slice | Est | Depends | Flag | Done-signal |
|---|---|---|---|---|---|
| **1.1a** | Close the IDOR: restore 3 checks; **repartition exports** to `exports/{schoolId}/{userId}/{uuid}.csv` + DB row; serve **by DB id** | 1–2d | 1.0a | — | Other-School artifact → **403 by DB lookup**; `ExportPartitioning` + `IDOR` tests green |
| **1.1b** | Seed the 2 missing permission seeders; move 7 public routes into the auth group; delete `/curricula/queued`; fix hardcoded "Secondary School" default | 1–2d | 1.0a | — | `GET /api/sessions` no-auth → **401**; `SeededPermissionSet` green; self-register lands in the chosen School |

### M1.2 — RBAC rebuild *(1C; split into 6)*
| ID | Slice | Est | Depends | Flag | Done-signal |
|---|---|---|---|---|---|
| **1.2a** | `Permission` enum **mechanism** + single seeder wired into `DatabaseSeeder` + assert-test, over **existing** perms only (no `finance.*`, no Finance roles) | 1–2d | 1.1b | — | `SeededPermissionSet` asserts the exact set; enum has no magic strings |
| **1.2b** | `Gate::before` super-admin bypass in null-team context; **verify vs Spatie 7.4 source** first | 1d | 1.2a | `auth.gate_before` | `SuperAdminPermission` green; super-admin passes a check *inside* a School |
| **1.2c1** | Restore commented checks — **Guardian (16) + GuardianImport (6)** cluster (incl. credential-change gates, highest risk) | 1–2d | 1.2a | — | Zero commented-authz lines in these files; behaviour tests per restored gate |
| **1.2c2** | Restore commented checks — **StudentSubject (8) + CurriculumSubject (8) + StudentCurriculum (3)** cluster | 1–2d | 1.2a | — | Zero commented-authz lines in these files; behaviour tests |
| **1.2c3** | Restore commented checks — **ActivityLog (8) + SavedActivityFilter (4)** cluster | 1d | 1.2a | — | Zero commented-authz lines in these files; behaviour tests |
| **1.2d** | **Baselined** commented-authz lint rule (§17.2, risk #26) — snapshot the existing 53 as an allowlist and **fail only on NEW** ones (ratchet; Continuous 1.2c burns the baseline down); + `permission:` middleware + one reference Policy | 1–2d | 1.2a | — | A PR adding a *new* commented check fails CI; baseline count only ever decreases |
| **1.2e** | Collapse 5 access sources → `model_has_roles`: **backfill migration** + single-source `accessibleSchoolIds` + **parity test** + cache/invalidation | 2–3d | 1.2a | `rbac.single_source` | `SchoolAccessParity` green (identical set per user); query-count test shows cache hit |
| **1.2f** | Remove all `?? $user->school_id` reads (`ActiveSchool:41`, `ActivityLogQueryService:32`, `User`) + arch rule; **delayed** drop of `users.school_id`+`school_user`; `LogsActivity` on Role/Permission (`events_enabled=true`); share Permissions to Inertia (`usePermissions`/`<Can>`, drop `rolesFull`); per-role 2FA + failed-login logging | 2–3d | 1.2e (parity green) | `rbac.single_source` | `grep '?? \$user->school_id' app/` empty + arch rule; reference RBAC scenario passes at API |

### M1.3 — School isolation hardening *(1D; split into 6)*
| ID | Slice | Est | Depends | Flag | Done-signal |
|---|---|---|---|---|---|
| **1.3a** | `ActiveSchool::runFor()` (finally-restore + `setPermissionsTeamId` + `unsetRelation`) + `SchoolAware` job middleware + rename `SetTenantContext`→`SetSchoolContext` | 2d | 1.2b | — | `NoTeamLeakBetweenJobs` green; unit test proves finally-restore |
| **1.3b** | Retrofit 5 jobs to `SchoolAware` (`public readonly int $schoolId`), **remove `auth()->setUser`**; scheduled commands iterate Schools | 2–3d | 1.3a | `jobs.school_aware` | `SchoolContextInJob` green for a super-admin causer; grep finds no `auth()->setUser` in jobs |
| **1.3c** | `SchoolScope` **fails closed** (remove `catch` swallow) + per-model rollout; `ActivitySchoolResolver` prefers `ActiveSchool::id()` | 2–3d | 1.3a | `scope.fail_closed` (per model) | `SchoolScopeFailsClosed` green (throws from console+worker); per-model enablement list |
| **1.3d** | `terms.school_id` (backfill from `academic_sessions`) + scope; fix `ClassLevelArm`+`MarkingComponent` (audit+backfill) | 2d | 1.3c | — | `TermIsolation` + `ClassLevelArmIsolation` green |
| **1.3e** | `students.status` + `left_at` + `leave_reason` + `index(school_id,status)` **(blocks Ph5)** | 1–2d | 1.0a | — | `StudentStatusBilling` green; "active students at School X" answerable with no join |
| **1.3f** | `guardian_student` same-School constraint (audit+resolve violations first) + backfill; `schools.timezone`+working hours; queue `after_commit=true` + reconcile `retry_after`/`timeout` | 2d | 1.0a | — | `GuardianStudentSameSchool` green; a cross-School link is rejected; no pre-commit-dispatch race |

### M1.4 — Shared Kernel primitives *(1E; Phase-1 justified set only)*
*Idempotency→Ph5, FeatureFlags→Ph2, Approvals→Ph3, Pdf→Ph5 (Validation Review §A).*
| ID | Slice | Est | Depends | Flag | Done-signal |
|---|---|---|---|---|---|
| **1.4a** | `Money` VO + `MoneyCast` + `formatNaira()` + unit tests (pure; ADR 0002) | 1–2d | 1.0a | — | Money round-trip through `MoneyCast` is exact; a `decimal:` money cast fails the lint rule |
| **1.4b** | Shared `Sequences` — **replaces racy `HasAdmissionNumber`**; concurrency test | 2–3d | 1.0a | — | Admission numbering gap-free under parallel load on MySQL |
| **1.4c** | Audit immutability: `updating`/`deleting` guards + capture IP/UA/reason/approver; **disable `activitylog:clean`** + DB-level `DELETE` deny | 1–2d | 1.0a | — | `activity_log` rejects DELETE at the DB; guard test green |
| **1.4d** | Observability: **Sentry** + **Horizon** (+ **Redis**) + `failed_jobs` alerting | 2d | 1.0a | — | A forced job failure surfaces in Sentry + `failed_jobs`; Horizon dashboard reachable |
| **1.4e** | Domain **event bus** + 4 Academics facts (emit only, no Finance listeners) | 1–2d | 1.3a | — | Events fire with `schoolId`; arch test: zero Finance coupling |

### M1.5 — Standards, governance & docs *(1F; the enforcement floor before Finance)*
| ID | Slice | Est | Depends | Flag | Done-signal |
|---|---|---|---|---|---|
| **1.5a** | Full arch-test suite (§17.1) + lint gates (§17.2) + Larastan level 5 in CI | 2–3d | 1.1–1.4 landed | — | `pest --group=arch` + Larastan green and enforced in CI |
| **1.5b** | README · CONTRIBUTING (the Constitution) · CLAUDE.md · `module-blueprint.md` · ADRs 0001–0035 (Phase-1 set) | 2–3d | — (author throughout) | — | Docs un-gitignored + present; each Phase-1 ADR written |

---

## Critical path vs parallel

**Critical path (sequential):** `M1.0 → M1.1 → M1.2 → M1.3 → M1.4 → M1.5`. M1.0 unblocks all verification;
M1.1 ships standalone as a security patch but its regression tests need M1.0's runnable suite (so M1.0 lands
first or alongside). M1.2 (RBAC) precedes M1.3 (isolation depends on the single access source + Gate::before).
M1.4 primitives depend on the hardened context from M1.3. M1.5 arch/lint gates must be last in Phase 1 but
before Phase 2.

**Parallelizable (second developer):**
- Docs/ADRs (M1.5 authoring) can be drafted alongside M1.2–M1.4; only the *arch-test/lint enforcement* must wait.
- Observability infra (Sentry/Horizon/Redis provisioning, part of M1.4) can be stood up in parallel from M1.0.
- Factory build-out (M1.0) and the tsc ratchet baseline are independent of the RBAC/isolation track.
- External blockers (accounting policy, bank accounts, Paystack, SMS, WCBS extract) are chased in parallel and
  gate Phases 2+, not Phase 1.

---

## Per-milestone dependency & rollback analysis

| Milestone | Prerequisites | Parallelizable with | Expected impact (blast radius) | Key risk | Rollback strategy |
|---|---|---|---|---|---|
| **M1.0** CI/test infra | none | Sentry/Redis provisioning; doc drafting | Repo-wide (CI, factories, test DB) — no runtime code | MySQL-only suite surfaces latent test failures | Additive: revert workflow + `phpunit` changes; factories are new files |
| **M1.1** Security hotfix | M1.0 (to run regression tests) | Independent of M1.2+ | Security endpoints + export storage path | Repartitioning breaks existing export links | Standalone patch PR → revert single PR; keep a filename→DB shim during transition |
| **M1.2** RBAC rebuild | M1.1 (seeders), M1.0 | Doc/ADR authoring | Auth layer app-wide | Dropping `users.school_id` strips access (#12); `Gate::before` in Spatie teams (#11) | **Expand/contract** — columns retained ≥1 release; drop gated on parity test; `Gate::before` behind a flag until verified |
| **M1.3** Isolation hardening | M1.2 (single access source before fail-closed) | limited (touches shared scope) | Every scoped query + all jobs | Fail-closed breaks console/seeders (#14); scoping previously-unscoped models leaks/breaks reads | **Per-model rollout** — each model's scope is one revertible change; `runFor`/`SchoolAware` are additive; scope enablement gated on data audit + backfill |
| **M1.4** Kernel primitives | M1.3 (hardened job/context) | Observability infra; `Money` (pure) fully independent | Additive Kernel code + one audit DB grant | `DELETE`-deny on `activity_log` could block a legit op; `after_commit=true` shifts dispatch timing | Primitives are new, unreferenced → delete; audit grant + queue config are reversible migrations/config |
| **M1.5** Standards & gates | M1.1–M1.4 landed (arch tests assert their shape) | Doc authoring throughout Phase 1 | CI gates + docs (no runtime) | Arch/lint rules retro-fail existing code | Introduce each rule as warning→error incrementally; every rule independently revertible |

**Integration-conflict note:** M1.2→M1.3 is the tightest coupling (single access source must exist before
the scope fails closed). Keep them on one branch line; branch M1.1 (standalone patch), docs, and observability
provisioning off in parallel to minimize merge conflicts.

---

## Expand/contract & data-migration plan

**Data scale is small** (a single-institution SIS: a handful of Schools, low-thousands of students/users) —
so every backfill below is **sub-second to a few seconds**. On MySQL 8, add-column-with-default is metadata-
only (instant) and add-index/add-FK use online DDL (`ALGORITHM=INPLACE, LOCK=NONE`), so **no table locks of
consequence** and **no maintenance window**. Batching (chunks of 1,000) is applied as a habit to any `UPDATE`
backfill but is not performance-critical at this scale.

| # | Table / target | Change | Backfill | Verify | Rollback | Delayed cleanup | Lock/contention |
|---|---|---|---|---|---|---|---|
| 1 | `model_has_roles` | Insert access rows (1.2e) | From `users.school_id` + `school_user` | **Parity test** (identical set per user) | Old columns retained | **Drop `users.school_id`+`school_user` ≥1 release later**, gated on parity green | INSERT-only; none |
| 2 | `terms` | Add `school_id` (1.3d) | Join via `academic_sessions.school_id` | Row counts per School reconcile | Column nullable → drop | Enforce `NOT NULL`+FK after backfill verified | Instant add; INPLACE index |
| 3 | `class_level_arms`, `marking_components` | Add/enable `school_id` scope (1.3d) | Derive from parent relation | Cross-School isolation test | Drop the scope (one revert) | — | **Audit for cross-School rows first**; resolve before enabling |
| 4 | `students` | Add `status`,`left_at`,`leave_reason`,`index(school_id,status)` (1.3e) | Default `active`; optionally derive `withdrawn` from `StudentCurriculum` | "active students, no join" query | Drop columns | — | Default = instant; index INPLACE |
| 5 | `guardian_student` | Add `school_id` + same-School constraint (1.3f) | From guardian/student (must match) | `GuardianStudentSameSchool` test | Drop constraint+column | — | **Existing violations block the constraint → audit + resolve first** |
| 6 | Export storage | Repartition to `exports/{schoolId}/{userId}/{uuid}` + DB rows (1.1a) | Create rows for existing files (or expire) | IDOR/partition test | Filename→DB shim during transition | Remove flat-path shim after cutover | File move; none |

**The two that need a data audit before the DDL:** #3 (previously-unscoped models may hold cross-School rows)
and #5 (existing cross-School guardian↔student links must be resolved, or the constraint fails to apply). Both
audits run as read-only queries in the slice before the migration.

## Deployment safety & order

- **No maintenance mode required** for any Phase-1 change — all are additive or expand/contract, and DDL is
  online at this data scale.
- **Deployment order per slice:** (1) ship the migration adding the new column/table (additive, nullable);
  (2) ship code that *writes* the new shape while still reading the old; (3) backfill + verify; (4) flip the
  flag to *read* the new shape; (5) in a later release, stop writing the old shape and drop it. Never drop a
  column in the same release that stops reading it.
- **Zero-downtime confirmed** for: all M1.0/M1.4/M1.5 (additive), 1.2a–d, 1.3a/d/e/f, 1.1. The **flag-gated**
  cutovers (1.2e/f single-source, 1.3b jobs, 1.3c fail-closed) are enabled *after* deploy, per-model/per-job,
  and are instantly reversible without a redeploy.

## Temporary rollout flags (removed once verified)

| Flag | Guards | Rollout | Retire when |
|---|---|---|---|
| `auth.gate_before` | `Gate::before` super-admin bypass (Spatie 7.4 risk #11) | off → verify in staging → on | bypass verified in prod |
| `rbac.single_source` | `accessibleSchoolIds` reads only `model_has_roles` (risk #12) | off → parity green → on | old columns dropped |
| `jobs.school_aware` | Jobs use `SchoolAware` vs legacy path | per-job | all 5 jobs migrated |
| `scope.fail_closed` | `SchoolScope` throws on no-context (risk #14) | **per model** | all models rolled out |

These are simple env/config gates for de-risking deployment — **distinct from the Ph2 per-School
`FeatureFlags` service**, which is deferred (Validation Review §A).

## Merge / branch strategy (2 developers, minimal conflict)

- **Integration flow (project policy):** short-lived slice branches → **`staging`** (the integration branch) →
  **`main`**. A slice is merged into `staging`, where **CI must pass**, **manual validation** is performed, and
  **cross-slice compatibility** is verified. **Only after a whole milestone is validated on `staging`** is it
  merged into `main`. No slice merges directly to `main`.
- **Branch protection** on both: `staging` requires `composer ci:check` + arch + MySQL green per slice PR; `main`
  additionally requires the milestone's `staging` validation. One PR per slice (`feat(...)`/`fix(...)` with scope).
- **CI trigger implication (do in M1.0b):** add **`staging`** to the workflow triggers (currently `develop`/`main`/
  `master`/`workos`) and drop the branches that don't exist — matches spec §17.3.
- **Merge order:** `1.0a → 1.0b/1.0c → 1.1a/1.1b (patch, tag release)` first — 1.0 unblocks everyone's CI.
- **Track A — Dev 1 (critical path):** the `1.2 → 1.3` chain on a serial line (tightest coupling; `User.php`
  and `SchoolScope` are the hot files — keep them single-writer).
- **Track B — Dev 2 (parallel, cold files):** `1.3e` (students.status) and `1.3f` (guardian/timezone/queue —
  independent tables), `1.4a–d` (new Kernel files), `1.4d` observability infra, `1.5b` docs/ADRs.
- **Conflict hotspots:** `app/Models/User.php` (all of 1.2) and `SchoolScope`/`BelongsToSchool` (1.3a/c) — assign
  to a single developer for the duration. `routes/api.php` (1.1b) lands before RBAC work touches routes.
- **1.5a (arch/lint gates) merges last** in Phase 1 — after 1.1–1.4 so it does not retro-fail in-flight work.

## Success criteria (Definition of Done — per slice)

A slice is **Done only when all five hold** (no slice advances otherwise):
1. **Code** merged via PR + review.
2. **Tests** green — including the named regression test in its Done-signal (a money/isolation/authz slice adds
   its concurrency / cross-School / maker≠checker test as applicable).
3. **CI** green — `composer ci:check` (lint+format+types+test on **MySQL**) + arch + Larastan level 5.
4. **Docs** updated where the slice changes a rule (ADR for architectural decisions; CONTRIBUTING/CLAUDE.md for
   conventions).
5. **Rollback** documented (and, for the flag-gated cutovers 1.2e/f, 1.3b/c, **rehearsed** in staging).

**Phase-1 exit (M1) is met when** every slice is Done, the §25 regression suite is green on MySQL, `tsc` is at
or below baseline, `grep '?? \$user->school_id' app/` is empty, and the arch/lint gates (1.5a) are enforced —
i.e. **Finance cannot begin until the boundary is mechanically enforced.**

---

## API Compatibility Report (goal: zero unintended frontend regressions)

**Frontend under test:** React 19 + Inertia (TSX), calling `/api/*` via axios (§4.2) + Inertia shared props.
*(No Vue frontend exists in the repo — the analysis is identical for the React/Inertia SPA.)* **Guardrail:**
§16 freezes the existing `/api/*` contract; all Finance is `/api/v1/finance/*` (Ph2+). So Phase 1 changes are
**repairs**, and response *shapes* are held stable — the risks are in **auth behavior** and **route reachability**,
not payload schemas.

### Breaking changes (require coordinated frontend work / adapter)

| Slice | Change | Frontend impact | Adapter / required update | Deploy safely by |
|---|---|---|---|---|
| **1.1a** | Exports served **by DB id**, not filename | Any download link built from a filename 404s | **Filename→DB-id shim** resolves old links for one release; update the download call to the id route | Ship shim + new route together; frontend switches before shim removed |
| **1.1b** | 7 routes move **into the auth group**; `/curricula/queued` deleted | A call made *before login* now → **401**; a call to the deleted route 404s | Audit the SPA for pre-auth calls to `/sessions`, `/class-structure`, `/exam-types`, `/subjects`, `/grade-boundaries`, `/curricula`, `/scholarships`, `/sport-houses`; move them behind auth | Land route change only after the audit confirms all callers are authenticated |
| **1.2c/d** | **53 authz checks restored** + Policies + `permission:` middleware | Endpoints that return 200 today (checks commented) return **403** for users lacking the permission | **Seed permissions → assign to roles FIRST**; ship `<Can>`/`usePermissions` UI gating so unauthorized actions are hidden, not attempted | Order: seed+assign → deploy `<Can>` (still permissive) → enforce checks |
| **1.2f** | Stop shipping the `rolesFull` Inertia prop | Any component reading `auth.rolesFull`/`props.rolesFull` breaks | **Keep `rolesFull` for one release (deprecated)** while the SPA migrates to `usePermissions`; remove next release | Prop removal is a *separate later* release from permission-sharing |
| **1.2f** | Per-role **2FA** enforcement | Login flow for affected roles gains a 2FA challenge step | Frontend must handle the challenge response; roll out per-role behind config | Enable per-role only after the SPA handles the challenge |
| **1.3c** | `SchoolScope` **fails closed** | A scoped-list call with **no active school** (e.g., super-admin pre-selection) now errors instead of returning unscoped rows | **Map `MissingSchoolContextException` to a clean `409`** ("select a school"), never a raw 500; SPA prompts school selection | Ship the structured error + SPA handling *before* flipping `scope.fail_closed` |
| **1.3d** | `terms`/`ClassLevelArm`/`MarkingComponent` become School-scoped | Any **cross-School / super-admin "all Schools"** list shrinks to the active School | Confirm no legitimate cross-School list exists (spec says none should); scope such views per-School | Per-model rollout; verify each list endpoint after enabling |
| **1.3f** | `guardian_student` same-School constraint | A cross-School guardian↔student link request now fails | Surface as a validation error; the operation was always invalid under isolation | Resolve existing violations first (data audit), then enforce |

### Non-breaking changes (additive / behavior-expanding — no frontend action required)

- **1.2b `Gate::before`** — *expands* super-admin access (grants bypass); can only turn 403→200, never the reverse.
- **1.3e `students.status`** — new response field on Student; additive. (Note: list endpoints that start filtering to `active` will *correctly* drop withdrawn students — verify any roster UI that expected them.)
- **1.3a rename** `SetTenantContext`→`SetSchoolContext` — **keep the `tenant` middleware alias** so `routes/api.php` (`auth:sanctum,tenant,…`) is unchanged; pure internal rename, no route/contract change.
- **1.3b jobs `SchoolAware`**, **1.3f queue `after_commit`** — async/internal; no API shape change.
- **1.4a Money VO** — no existing column serializes Money in Phase 1 (Finance tables are Ph2), so no current response changes.
- **1.4b Sequences** — **must preserve the existing `admission_number` format** so any SPA display/parse is unaffected (internal generation swap only).
- **1.4c–e audit immutability / observability / event bus**, **1.5a/b arch tests + docs** — no runtime API surface.
- **1.0\* + 1.2a + 1.2e** — CI/test infra and the access-source collapse are gated by the **parity test** (identical `accessibleSchoolIds` per user), so the School-switcher UI sees no change.

### Backward-compatibility adapters needed (net list)
1. **Export filename→DB-id shim** (1.1a) — one release.
2. **`rolesFull` Inertia prop retained + deprecated** (1.2f) — one release.
3. **`MissingSchoolContextException` → structured `409`** (1.3c) — permanent error contract, not a 500.
4. **`tenant` middleware alias preserved** on rename (1.3a).
5. **`admission_number` format preserved** across the Sequences swap (1.4b).

### Safe deployment order (frontend-aware)
1. **1.0** infra → **1.1** security patch (with export shim + pre-auth route audit) → tag release.
2. **Permissions before enforcement:** seed + assign permissions to roles (1.2a) → ship SPA `usePermissions`/`<Can>` while still shipping `rolesFull` (1.2e/f, permissive) → **then** enforce restored checks (1.2c/d).
3. **Error contract before fail-closed:** ship the `409` no-context handler + SPA prompt → **then** flip `scope.fail_closed` per model (1.3c).
4. **Data audit before constraints:** resolve cross-School rows/links → enable scoping (1.3d) + `guardian_student` constraint (1.3f).
5. **Deprecations last:** remove `rolesFull` and drop `users.school_id`/`school_user` in a **later** release, each gated on its verification (parity / SPA migration) being green.

**Net:** with the five adapters and this ordering, every Phase-1 change is either additive or flag-gated with a
reversible cutover — **no unintended frontend regression**, and each behavioral break is opt-in and coordinated.

---

## Delivery-risk review — Phase-1 Core vs Continuous Hardening

**Honest assessment: yes, Phase 1 as written is too large for a single 6-week foundation phase.** It had
accreted a platform-wide modernization (security remediation + RBAC + isolation + Kernel + event bus +
observability + CI + governance + arch enforcement + migrations + deploy strategy + API-compat). Much of it
does **not** gate Finance Phase 2 — it gates *later* Finance phases, or nothing specific. Cramming it into the
front creates exactly the long-lived branches this review warns against.

**The fix (no architecture change): split the Phase-1 *deliverable* into two tracks.**
- **Phase-1 Core** — the minimum that lets Finance Ph2 begin and lands the boundary enforcement. Short,
  serial-ish, one primary owner on the hot files.
- **Continuous Hardening** — runs in parallel from day one and beyond Phase 1; **each item is pinned to the
  phase it actually blocks**, not to Ph2. Nothing here delays Finance.

### Reclassification

| Slice | Track | Actually gates | Rationale |
|---|---|---|---|
| 1.0a/b/c (CI/test infra) | **Core** | all verification | Nothing is testable until this lands |
| 1.1a/b (security patch) | **Core** | ship now | IDOR is live; standalone patch, independent of everything |
| 1.2a (Permission enum mechanism) | **Core** | Ph2 (Finance perms extend it) | — |
| 1.2b (`Gate::before`) | **Core** | Ph2 authz semantics | Finance Policies rely on the super-admin bypass |
| 1.2d (Policy pattern + `permission:` mw + **lint rule**) | **Core** | Ph2 + risk #26 | Finance Policies follow the pattern; the lint rule is the cheap cultural mitigation |
| 1.2e (single access source + parity) | **Core** | isolation correctness | Finance reads `accessibleSchoolIds` |
| 1.2f-core (remove `?? school_id` + arch rule + share Permissions to Inertia) | **Core** | Ph2 | `ActiveSchool` correctness + `<Can>` |
| 1.3a (`runFor` + `SchoolAware` + rename) | **Core** | Ph2 reconciliation job | Finance jobs need it |
| 1.3d (`terms.school_id`) | **Core** | Ph2 (billing periods bind to Term) | — |
| 1.4a (Money VO) | **Core** | Ph2 (built on it) | — |
| 1.5a (arch/lint gates) | **Core** | before any Finance code | Must exist before Ph2 commit 1 |
| 1.5b-core (boundary ADRs 0001/0002/0018/0026/0027/0033/0034/0035) | **Core** | Ph2 | The rest of the docs author continuously |
| **1.2c1/c2/c3** (53 legacy authz checks) | **Continuous** | security debt (not Ph2) | Finance has its own correct Policies. Do **1.2c1 first** (Guardian credential gates); lint rule (Core) stops new ones |
| **1.2f-cont** (per-role 2FA, failed-login logging) | **Continuous** | Ph11 (audit) | Not a Finance prerequisite |
| **1.3b** (retrofit 5 legacy jobs) | **Continuous** | general safety | Mechanism (1.3a) is Core; the 5 *existing* jobs aren't Finance; Finance jobs are born `SchoolAware` |
| **1.3c** (fail-closed rollout across *legacy* models) | **Continuous** | gradual | Finance + new models are **born fail-closed**; the legacy long-tail rolls out per-model over time |
| **1.3e** (`students.status`) | **Continuous** | **Ph5** | Additive; cheap to do early, but only blocks invoicing |
| **1.3f** (guardian constraint / timezone / queue) | **Continuous** | Ph6 / Ph11 (queue fix sooner) | Constraint blocks allocation; the `after_commit` fix should land early as a correctness fix |
| **1.4b** (Sequences) | **Continuous (early)** | **Ph5** | Do early — it fixes the *live* admission-number race — but it does not block Ph2 |
| **1.4c** (audit immutability) | **Continuous (early)** | protects existing audit | Cheap; land soon |
| **1.4d** (observability) | **Continuous** | **before Ph6** (spec ADR 0031) | Whole-app; explicitly "before money moves", not before Ph2 |
| **1.4e** (event bus + 4 facts) | **Continuous** | **Ph5** (`StudentEnrolled`) | Bus is cheap; events land with their first consumer |

**Reduced Phase-1-Core critical path:**
`1.0 → 1.1 → (1.2a → 1.2b → 1.2d) ∥ (1.2e → 1.2f-core) → (1.3a, 1.3d) → 1.4a → 1.5a`
— roughly **3–4 weeks**, after which **Finance Ph2 can start** while the Continuous track proceeds in parallel,
each item landing before *its own* dependent phase. This is the single biggest delivery-risk reduction: it
takes the platform-modernization pressure off the Finance start date without dropping any of the work.

### Independence verification (the 5 independences)

Every slice is **independently implementable, testable, reviewable, deployable, and reversible.** The mechanism:
- **Additive slices** (all of 1.0, 1.1, 1.2a/c/d, 1.3a/d/e/f, 1.4\*, 1.5\*) — new files/columns/tests; merge and
  deploy on their own; revert = revert the PR. Trivially independent.
- **Behavioral-cutover slices** — independence is preserved **by a rollout flag** so the code merges (flag off)
  without waiting on anything, deploys dark, and reverses by toggling:

| Slice | Flag | Independently mergeable? | Independently reversible? |
|---|---|---|---|
| 1.2b | `auth.gate_before` | Yes (merges off) | Yes (toggle) |
| 1.2e / 1.2f | `rbac.single_source` | Yes (parity computed while off) | Yes (toggle; old columns retained) |
| 1.3b | `jobs.school_aware` | Yes (per-job) | Yes (per-job toggle) |
| 1.3c | `scope.fail_closed` | Yes (per-model, off) | Yes (per-model toggle) |

No slice needs another *unfinished* slice to merge. Build-order dependencies (e.g. 1.3b imports the 1.3a
middleware) are satisfied by **merge order**, not stacked branches: 1.3a is merged to `main` before 1.3b
branches — never branch-on-branch.

### Hidden coupling & single-writer files

The only real coupling is **shared hot files**, which reduce parallelism if two devs touch them at once:
- `app/Models/User.php` — all of 1.2 → **one owner** for the 1.2 chain.
- `app/Models/Scopes/SchoolScope.php` + `app/Concerns/BelongsToSchool.php` — 1.3a & 1.3c → **same owner**.
- `app/Support/ActiveSchool.php` — touched by **1.2f** (remove fallback) *and* **1.3a** (add `runFor`) →
  sequence 1.2f before 1.3a (or same owner) to avoid a merge collision.
- `routes/api.php` — 1.1b lands early, before any RBAC route work.
- `config/permission.php` — 1.2f (`events_enabled`) only; isolated.

Everything else (new Kernel files, migrations on distinct tables, docs, observability config) is **cold** and
fully parallelizable by the second developer — the Continuous track is deliberately routed through cold files.

### Delivery-risk reductions applied (quality unchanged)
1. Split the deliverable → Finance start no longer waits on platform modernization.
2. Largest slice (1.2c) decomposed into 3 controller-cluster slices, prioritized by risk.
3. Binding independence rule + per-cutover flags → short-lived branches integrated on `staging`, never long-lived.
4. Single-writer assignment on the 5 hot files → minimal merge conflict.
5. Continuous track routed through cold files → true parallelism for Dev 2.

---

## Verification (drive it, don't assume it)

```bash
cp .env.example .env && php artisan key:generate
composer ci:check                       # lint:check + format:check + types:check + test  (the merge gate)
vendor/bin/phpstan analyse --level=5
vendor/bin/pest --group=arch
vendor/bin/pest -c phpunit.mysql.xml     # AFTER M1.0 makes it MySQL-safe
grep -rn '?? \$user->school_id' app/     # must return nothing
npx tsc --noEmit | tail -1               # baseline must never increase
```
Regression suite proving Phase 1 landed (§25): `IDOR`, `SuperAdminPermission`, `SchoolContextInJob`,
`TermIsolation`, `ClassLevelArmIsolation`, `SeededPermissionSet`, `SchoolAccessParity`,
`SchoolScopeFailsClosed`, `NoTeamLeakBetweenJobs`, `ExportPartitioning`, `StudentStatusBilling`,
`GuardianStudentSameSchool`.

**Added by the readiness review (were missing):**
- **Authorization:** a per-cluster `RestoredCheckEnforces403` (each restored gate 403s an unpermitted user) as
  each 1.2c slice lands.
- **Cross-School isolation:** `MarkingComponentIsolation` (was scoped but had no test).
- **Rollout-flag behavior:** for each of the 4 flags, a `Flag{On,Off}Behaviour` test — **off = legacy behavior
  preserved, on = new behavior** (e.g. `rbac.single_source` off ⇒ old union; on ⇒ `model_has_roles` only).
- **Rollback rehearsal:** `MigrationRollback` — every expand/contract migration's `down()` is exercised in CI
  (esp. `model_has_roles` backfill, `guardian_student` constraint, `terms.school_id`), and the flag-gated
  cutovers (1.2e/f, 1.3b/c) are reverted-in-staging as part of their DoD.
- **Concurrency:** `SequenceGapFreeUnderLoad` (admission numbering, parallel, MySQL) — the one Phase-1 race
  (Finance money-path concurrency tests come in Ph6).

**Manual walkthroughs (§25):** super-admin → Activity Logs populated (proves `Gate::before`); teacher hits
`GET /api/activity-logs/export` directly → 403; request another user's export artifact → 403; bulk job as
super-admin causer → rows in correct School; `GET /api/sessions` no auth → 401; the reference RBAC scenario by
hand; queue a School-A export while acting in School B → file contains School B's records.

**Milestone gate:** no milestone proceeds until its acceptance criteria pass. Phase 1 is complete at **M1**
("Foundation complete") — Finance may not begin until M1.5's arch/lint enforcement is green.

---

## Principal-Engineer readiness review

### Critical Issues
1. **Lint-rule vs remediation conflict (found & fixed).** The commented-authz lint rule (Core, 1.2d) would have
   failed CI immediately against the 53 still-commented checks now in the Continuous track (1.2c). **Resolved:**
   the rule is **baselined/ratcheted** — snapshot the existing 53, fail only on *new* ones, Continuous burns the
   baseline down. This was the only day-one-blocking issue; it is now closed in the plan.

*No other critical (Go-blocking) issues remain.* The residual hardest work — the RBAC access-source collapse
and the fail-closed scope rollout — is real risk but is **mitigated, not unmitigated** (expand/contract, parity
gate, per-model flags, rollback rehearsals).

### Recommended Changes (non-blocking, applied to the plan)
- **Testing gaps closed:** added flag on/off tests (all 4 flags), `MigrationRollback` down()-rehearsals for the
  expand/contract migrations, `MarkingComponentIsolation`, and per-cluster `RestoredCheckEnforces403`.
- **Seeder/migration opt-out:** fail-closed rollout (1.3c) must have seeders/migrations call `withoutSchoolScope`
  explicitly (§5.5) so the scope change cannot break the seed/migrate path.
- **Slim 1.2d-core:** ship the lint rule + `permission:` middleware + **one** reference Policy in Core; move the
  full Guardian/Student/ActivityLog Policy set to Continuous (Finance defines its own Policies from the pattern).
- **1.3d (`terms.school_id`) is Core-optional:** `ResolvesTermFilter` is already scoped, so this can slip to
  Continuous if the Core critical path needs shortening; kept in Core only because it is cheap and low-risk.

### Updated dependency / order
- **1.2d now depends on 1.2a only** (was 1.2c1–c3) — the baseline decouples the lint rule from the remediation,
  removing the one stacked dependency. Core critical path is unchanged otherwise:
  `1.0 → 1.1 → (1.2a→1.2b→1.2d) ∥ (1.2e→1.2f-core) → (1.3a,1.3d) → 1.4a → 1.5a`.
- **Single-writer note reaffirmed:** `ActiveSchool.php` is touched by 1.2f and 1.3a → sequence 1.2f before 1.3a.

### Delivery scaling
- **1 dev:** Core serial (~3–4 wk) then Continuous — feasible, longer wall-clock; no branch problem.
- **2 devs (target):** Dev 1 owns the Core hot-file chain (`User`, `SchoolScope`, `ActiveSchool`); Dev 2 runs
  Continuous through cold files (Sequences, observability, `students.status`, docs). Optimal.
- **3 devs:** the 3rd adds a second **Continuous** stream + test authoring; it does **not** speed the Core
  critical path (inherently serial on the hot files). Marginal dev → hardening/tests, not Core.
- **Merge-conflict hotspots:** the five single-writer files (above). Everything else is cold.

### Technical debt to defer until after Finance
Full tsc cleanup (ratchet only in Ph1; 143→0 later) · `wayfinder` 0.1.x pin (risk #24) · Redis for
session/cache beyond the queue (risk #8; go-live scaling) · opportunistic extraction of legacy `app/` into
Modules (§8.4) · completion of the 53-check remediation baseline burn-down (Continuous, may extend past Ph1).

### Verdict
- **Go / No-Go:** **GO** — the one critical issue is fixed in the plan; all slices are 1–3 days, independently
  mergeable/testable/reviewable/deployable/reversible, with no stacked branches.
- **Architecture Score:** **9 / 10** — approved v10 architecture preserved intact; −1 for the inherent risk of
  the RBAC/isolation cutovers (well-mitigated, but the riskiest real work in the phase).
- **Delivery Risk:** **Medium** (trending Low as the parity gate, per-model flags, and rollback rehearsals
  prove out in staging) — driven by the RBAC access-source collapse and fail-closed rollout, not by scope.

**The plan is implementation-ready.** Recommended first action on approval: **M1.0a** (create `.env.example`,
stand up the MySQL test suite) — the unblocker for all verification — followed by the standalone **M1.1**
security patch.
