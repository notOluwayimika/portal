# RBAC Workstream — Plan + Coordination Protocol (FOR REVIEW — nothing built)

**Deliverable status:** This is a plan for review, per the workstream brief. No slice opens until the
Finance owner signs off on the interface points listed in §5. The RBAC stream runs parallel to the
Finance stream on one repo; this document exists to make the seams safe.

---

## 1. Real current RBAC state — re-derived 2026-07-21, not carried

Every figure below was re-measured against HEAD (`b08009e`) this session. Stale roadmap figures noted.

| Item | Re-derived value | Stale figure it replaces |
|---|---|---|
| Commented-authz remaining | **2** — [StudentSubjectController.php:228](app/Http/Controllers/StudentSubjectController.php#L228), [StudentCurriculumController.php:63](app/Http/Controllers/StudentCurriculumController.php#L63); both in-line classified "recommend deletion, awaiting §7 decision" (redundant under SchoolScope; restoring would re-couple to `users.school_id`, ADR 0042 debt) | roadmap "34 of 53"; roadmap 9-guard cluster list (PR #67 `fix/commented-guard-cluster-and-authz-lint` resolved it — the listed locations now hold triage documentation, not guards) |
| authz-lint | **green, baseline = 2 lines**, dedup bypass closed (PR #67) | — |
| S5 restoration footprint | **45 `Authz::` call sites across 7 controllers** (ActivityLog, SavedActivityFilter, CurriculumSubject, Guardian, GuardianImport, StudentCurriculum, StudentSubject) — all observe-mode | my own earlier note of "2 controllers" |
| `AUTHZ_ENFORCE` / `authz.enforce` | **false** (observe). `authz_observations`: 0 rows in dev — evidence only accrues in prod, post-deploy | — |
| `rbac.fail_closed_models` | **[] (empty)** — no model fails closed; SchoolScope throw additionally **auth-gated** (`auth()->check() &&`), so principal-less off-request reads are **unscoped** = **Debt item 7, OPEN**. Residual `catch (\Throwable)` fail-open remains (MissingSchoolContextException is rethrown above it) | — |
| `rbac.single_source_access` / `parity_soak` | both **false**; S7 prod divergence count run 2026-07-19: **CLEAN (0/0)**; S7 steps 3–8 remain, writer strategy = Option B (compat writers untouched until the drop slice) | — |
| `permission.events_enabled` | **false** (Spatie attach/detach events not firing; no RBAC-change audit trail) | — |
| `auth.gate_before_superadmin` | **true**, tested (`SuperAdminGateTest`) | — |
| Role-gate debt (§7.2) | live `hasRole()` in **4 FormRequests + PrincipalController**; permission model designed in **ADR 0044** (seven permissions), implementation unstarted | — |
| Finance ↔ Authz coupling | **0 references** in `app/Finance/**` — ADR 0043's binding rule currently honored | — |
| Finance deploy | **mid-flight**: pre-flight passed in prod (§C1 clean, `students.school_id` 0 nulls), migration pending/paused. Locally PR #69 (deploy runbook + 7 down() fixes) landed | — |
| Route enforcement today | 28 `role:` middleware groups (custom `EnsureRole`) across web+api; `permission:` middleware aliased, **used 0 times**; 1 policy (`ExportPolicy`); permissions not shared to Inertia; `rolesFull` shipped but consumed nowhere | — |

**§24 exit condition — stated correctly (the binding four-part form, roadmap L1269-78):** authz-lint = 0 **and** `AUTHZ_ENFORCE=true` in production **and** every `authz_observations` would-be denial reviewed & classified expected **and** enforcement verified live (a permission-lacking request actually 403s in prod). `authz-lint = 0` alone is a false signal — it hits 0 the moment checks are restored in observe mode, while nothing enforces.

---

## 2. Sequenced RBAC plan — continues the existing rollout

Slices branch off `staging` per convention (`fix/`, `feat/`, `chore/`, `docs/`); every slice passes
`bin/quality`; baselines only shrink. **One-way-ness** and dependencies marked per slice.
⚑ = Finance coordination point (see §3).

### Track A — S5 spine → §24 closure (the priority track)

| # | Slice | Content | One-way? | Depends on |
|---|---|---|---|---|
| A1 | `fix/authz-final-two` | Resolve the last 2 baselined guards per their in-line classification (the pending "§7 decision"): **delete** (they re-couple to `users.school_id`) — or, if review prefers, restore as `Authz::ensure` ownership checks. Decide per line, bite-proof, shrink baseline → **0**. Closes §24 condition 1 | No (revertible) | — |
| A2 | `feat/authz-evidence-tooling` | Observation review tooling: `authz:observations --summarize` report (by ability × route × role), classification workflow doc. Read-only tooling; makes condition 3 executable | No | — |
| A3 | *(wait state, not a slice)* | Observe evidence accrues in **prod** — starts only after the Finance deploy completes and observe-mode traffic flows (scheduler already verified running in prod, so `authz:prune` retention holds) | — | **Finance deploy complete** ⚑ |
| A4 | `chore/authz-enforce-staging` | `AUTHZ_ENFORCE=true` in **staging**; drive per-role flows; classify every denial via A2 tooling; fix legitimate-access regressions each as its own reviewed change | No (env flag) | A1, A2, evidence window |
| A5 | `chore/authz-enforce-prod` | Flip prod; **live 403 verification** (condition 4). Closes §24 | Behavioral flip; revertible by env but a **user-facing event** — announce ⚑ | A4 + prod evidence reviewed (condition 3) |
| A6 | `chore/authz-teardown` | ADR 0043 §5 sequence: migrate all 45 call sites to Policies/`can()`/`abort_unless`, delete `Authz` + `config/authz.php` + `AUTHZ_ENFORCE`, drop `authz_observations` + prune command + rollout-only tests | **Yes** (deletes scaffolding + evidence table) | A5 + one stable release cycle |

### Track B — fail-closed rollout + Debt 7 (parallel to Track A)

| # | Slice | Content | One-way? | Depends on |
|---|---|---|---|---|
| B1..Bn | `feat/fail-closed-<model>` (waves) | Per-model `RBAC_FAIL_CLOSED_MODELS` enablement, each gated on its own request-path audit (the slice-(ii) pattern: enumerate route bindings, `exists` rules, joins, jobs/commands touching the model; give each off-request path explicit context). Start with low-fan-in models (e.g. `Notice`, `Export`); **any Finance-read or Finance-owned model** (`Student`, `StudentCurriculum`, `Invoice`, `Payment`, … all use `BelongsToSchool`) is ⚑ | No (env list; per-model revert) | 1.3b (done) |
| B-7 | `fix/schoolscope-debt-7` | Close Debt item 7: remove the `auth()->check()` gate (fail-closed decision becomes transport-agnostic — consistent with ADR 0042's direction) and remove the residual `catch (\Throwable)` fail-open (keep fail-loud). Off-request readers of opted-in models then **throw** instead of reading unscoped | No, but **widens throw surface** — every scheduled/queued read of opted-in models must already run under `runFor` ⚑ | Enough B-waves that job/command-touched models are audited |

### Track C — 1C completion (decisions locked earlier with user; each ⚑-marked item needs §5 sign-off)

| # | Slice | Content | One-way? | Depends on |
|---|---|---|---|---|
| C1 | `feat/rbac-foundation` | Expand `Permission` enum (~30 dotted cases; results/enrollment domain uses **ADR 0044's seven names verbatim**; finance uses v10 §343 `finance.<resource>.<action>`: `finance.invoice.create/cancel/view`, `finance.payment.record` ⚑). One **`RbacSeeder`** (13 roles = existing 7 + `registrar` + `super_admin` + 4 Finance roles ⚑; full grants map; **non-destructive re-run**, `rbac:sync --fresh` for reset). Delete the 5 scattered seeders. `permission.events_enabled=true` + attach/detach listeners → `activity('rbac')` + `flushSchoolAccessCache()` (closes the assignRole invalidation gap). `LogsActivity` on Role/Permission. Regenerate grants fixture; `rbac:derive-map` command captures the **pre-swap route→roles fixture** | No | A1 (baseline settled) |
| C2 | `feat/rbac-permission-middleware` | Swap all 28 `role:` groups → `permission:` (map derived from the groups; `/super-admin` stays `role:super_admin`). **`RouteAccessParityTest`**: static per-role pass/fail per route vs the pre-swap fixture (asserts only fixture-present routes — new Finance routes never blocked) + ~10 live per-role HTTP smokes. One declared deviation: `POST /logout` → plain `auth:sanctum`. Finance's group at api.php:200 is rewritten ⚑ | No (parity-proven; revert = restore groups) | C1 |
| C3 | `feat/rbac-policies` | Guardian/Student/Activity policies (ExportPolicy pattern, explicit `Gate::policy`); **implement ADR 0044** (4 FormRequests + PrincipalController `hasRole()` → the seven permissions; new-enforcement checks via `Authz` observe, re-expressions via direct `can()`); then the roadmap's deferred role-gate re-audit. Does **not** touch the 45 existing Authz sites (A6 owns them) | No | C1; **sequence against Finance's enrollment-controller slices — never concurrent** ⚑ |
| C4 | `feat/rbac-inertia` | Share `permissions` to Inertia; drop `rolesFull` (verified unused); `usePermissions()` + `<Can>`; migrate `app-sidebar.tsx` to permission gating ⚑ (Finance adds its nav items after this, via `<Can>`) | No | C1 |
| C5 | `feat/rbac-admin-module` | School-admin users page (`/setup/users`, `permission:rbac.manage_school_users`): list users in active school + guarded role sync (never `super_admin`; `admin` only by super admin; no self-modification; team-context assignment) | No | C1, C4 |
| C6 | `feat/rbac-superadmin-module` | Super-admin role×permission **matrix editor** (site-wide `syncPermissions`, activity-logged; roles/permissions not creatable at runtime — enum is code; seeder = canonical default, runtime edits survive `rbac:sync`) + per-role `two_factor_required` toggle | No | C1, C4 |
| C7 | `feat/rbac-2fa` | `roles.two_factor_required` migration; default **true** for `super_admin`, `admin`, 4 Finance roles ⚑; `EnsureTwoFactorEnrolled` middleware (**after `SetSchoolContext`** — reads roles, needs team context; `AuthorizationOrderingTest` must stay green); exempt security-settings/logout/select-school; web redirect / API 403 `TWO_FACTOR_REQUIRED` | No | C1; announce before merge ⚑ (breaks unenrolled admin logins incl. Finance-dev test users — factories set `two_factor_confirmed_at`) |

### Track D — S7 prep (prep only; flip + drop stay soak-gated)

Backfill continuity via the C5 module (role assignments now actively populate `model_has_roles`);
enable `RBAC_PARITY_SOAK` in dev/staging; author the drop migration guarded on
`rbac.single_source_access=true`, PR-marked **DO NOT MERGE** until the roadmap's S7 exit criteria hold
(coverage × transport cells, zero unexplained mismatches, runtime-zero-lint = 0, readiness matrix all
landed). Writer strategy stays Option B (compat writers deleted only in the drop slice). **One-way:** the
drop itself (not in this plan's scope to execute).

**Sequencing summary:** A1 → C1 → C2 → {C3…C7, B-waves, A2} in parallel → A3 wait (deploy) → A4 → A5 → A6.
Track D rides alongside; its terminal steps are outside this plan.

---

## 3. Finance interface map — every touchpoint, owner, and rule

| # | Touchpoint | Owner | RBAC may change freely | Requires telling Finance owner first |
|---|---|---|---|---|
| I1 | **Authorization on `/api/v1/finance/*`** (today `role:admin|super_admin` at api.php:200) | Definitions: RBAC · consumption: Finance | Add permission definitions, seed grants, observe-mode checks | **Any change to the effective access set of a Finance endpoint** (the C2 swap of that group; future Finance-role grants). Parity test must show identical access; any Finance test breakage = stop and coordinate |
| I2 | **`SchoolScope` / fail-closed** — Finance models (`Invoice`, `Payment`, `LedgerTransaction`, …) use `BelongsToSchool`; `BillableEnrollmentAdapter` reads `StudentCurriculum` | Mechanism: RBAC · affected models: each model's owner | Enabling fail-closed on RBAC-owned, non-Finance-read models | **Enabling fail-closed on any Finance-owned or Finance-read model** (`Student`, `StudentCurriculum`, all `finance_*`) — can flip a Finance read from resolving to throwing. Also Debt-7 closure (B-7): widens the throw to off-request paths Finance's future scheduled jobs (recognition, dunning, drift-verify) will use |
| I3 | **ADR 0043 rule** — no Finance dependency on `Authz` | Both (standing constraint) | — | **Verified 0 references today.** Standing: Finance never imports `Authz`; RBAC never adds `Authz` calls inside `app/Finance/**`. Enforcement: extend the boundary lint to fail any `Authz` reference under `app/Finance/` (wallpaper otherwise) — proposed in C1 |
| I4 | **The 2 remaining commented guards** — both on Finance-read models (StudentSubject/StudentCurriculum controllers) | RBAC | Deleting or restoring **in observe mode** (records, never blocks) | The later **enforcement flip** (A4/A5) is the coordination point — if restored rather than deleted, enforcement changes those endpoints' behavior on Finance-read paths |
| I5 | **`RbacSeeder` in the deploy runbook** | RBAC change · deploy owned by Finance lead | Authoring the seeder | **Inserting it into the phase1-deploy sequence** (after `migrate`, before observe traffic) — the deploy is mid-flight; nothing RBAC lands in that window without the deploy owner scheduling it |
| I6 | **Finance roles + 2FA defaults** (C1, C7) | RBAC seeds; Finance owns the role semantics (v10 §7.2/Ph2 "Finance Permissions + 4 roles seeded") | Creating the 4 role rows + `finance.invoice.view` grant | Grant set for Finance roles (write powers are Ph2/Ph3 maker-checker territory, ADR 0040-constrained); 2FA-required default on those roles |
| I7 | **Shared files**: `routes/api.php`, `DatabaseSeeder`, `config/rbac.php`/`authz.php`, `app-sidebar.tsx`, `docs/roadmap.md` | Coordination surfaces | — | Announce every touch (see §4 protocol); Finance route *additions* stay in `routes/endpoints/finance.php` |

---

## 4. Parallel-work collision protocol

1. **File ownership.** RBAC never touches `app/Finance/**`, `finance_*` migrations, or
   `tests/**/Finance/**`. Finance never touches `App\Support\Authz`, `config/authz.php`,
   `config/rbac.php`, seeders, or the authz/RBAC test suites. Shared surfaces (§3-I7) are
   announce-before-merge, never silent.
2. **Baselines — the highest-frequency collision.** `tests/ratchet-baseline.txt`, `tsc-baseline`,
   `authz-lint-baseline.txt`, `boundary-lint-baseline.txt` will move on both streams (RBAC especially).
   **Rule: every baseline conflict is resolved by REGENERATING against the merged HEAD** (re-run the gate,
   commit its output) — never by hand-picking lines. Two different "N"s are not the same N; this bit once
   already (#65). Baselines only shrink.
3. **Migrations.** Coordinate timestamps (RBAC prefixes its migrations after Finance's pending set);
   deterministic ordering; **no RBAC migration may reference `finance_*` tables or assume the
   post-migrate schema** — those tables do not exist in prod until the paused deploy completes.
4. **Promotion — merged-HEAD rule + one promoter.** Per-branch green ≠ merged green (learned twice).
   Every `staging → main` promotion runs `bin/quality-promote` on staging's **actual merged HEAD**
   (re-verifying the Finance+RBAC combination); the stamp gates the push. **One promoter at a time** —
   agree per promotion who runs it; the other stream holds merges to `staging` during a promotion window.
5. **Deploy freeze.** The Finance deploy is mid-flight (pre-flight passed, migration pending). Until the
   deploy owner declares it complete: no RBAC merge that would land in that deploy's scope, no RBAC
   dependence on post-migrate state, and A3's evidence clock does not start.
6. **Working method (inherited in full).** Propose → review → implement → attack-verify. Bite-prove every
   guard red-when-removed. Re-derive counts at slice-open. Observe-mode discipline: restored checks record,
   never silently enforce. A rule without a gate/lint/constraint is wallpaper — each protocol rule above
   that can be mechanized (I3's lint, the parity test, the baseline regenerate rule via a check script)
   should be.

---

## 5. Review gate — what the Finance owner must sign off before slice A1/C1 opens

1. The **§3 interface map** — especially I1 (api.php:200 rewrite in C2), I2 (which models may enter
   `fail_closed_models` and when), I5 (RbacSeeder's slot in the deploy runbook), I6 (Finance role grant
   set + 2FA defaults).
2. The **A-track sequencing** — that §24 evidence accrual (A3) waits on their deploy, and the A5 prod
   flip is jointly scheduled.
3. The **C3-vs-enrollment-slices ordering** (never concurrent in `StudentCurriculumController` /
   `StudentSubjectController`).
4. The **promotion cadence** (§4.4) — who promotes, when, and the merge-hold convention.
5. Residual housekeeping note: iCloud duplicate cleanup is done (101+15 dups removed, tree clean); the
   repo-relocation out of `~/Documents` was deferred mid-session — recommend scheduling it between
   sessions (it invalidates open editor/session paths).

**This document is the deliverable. Nothing beyond the plan file has been built, and no slice opens
until this review completes.**
