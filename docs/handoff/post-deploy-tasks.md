# RBAC — post-production-deploy task inventory

Companion to `docs/runbooks/phase1-deploy.md`. Everything the RBAC stream has built
that can only *take effect* in production, in dependency order. Sourced from the
roadmap, `rbac-implementation-plan.md`, ADRs 0040/0042/0043/0044/0045, and the slice
handoffs — consolidated here so the sequence lives in one ordered place.

## Read this first — deploying is not "done"

Deploying the stack flips RBAC from **built + staging-verified** to **rolling out in
production under observe-mode**. It is the *start* of the rollout, not the end.

- **The functional finish line is §24 closing** — authorization actually *enforcing*
  in production (a request lacking a permission gets 403), which happens at **A4→A5**
  below, not at deploy. Until then, every restored check runs in observe-mode:
  it records a would-be denial and continues, blocking nothing.
- **"Fully implemented" is later still** — after enforcement is stable, the
  transitional scaffolding is torn down (Authz removed, the super_admin bypass
  removed, legacy `users.school_id`/`school_user` dropped). Several of these are
  gated on "one stable release cycle" in prod.

So: at deploy, RBAC is *live but not enforcing*. At §24 close, it *enforces*. At
teardown complete, it is *fully implemented*. See "Definition of done" at the foot.

Every one-way step keeps a STOP-for-review before it. Nothing below is a big-bang.

---

## Phase 0 — deploy-time steps (in the deploy itself)

Embedded in `phase1-deploy.md`; listed here so the inventory is complete.

- [ ] Slice-(i) pre-flight: run the prod divergence query
  (`prod-divergence-and-cascade-queries.sql` §C), **list** offenders, remediate to
  zero, *then* migrate — the composite-FK migration aborts mid-deploy otherwise.
- [ ] `rbac:sync` after `migrate`, **before** traffic hits the swapped routes —
  skipping it is a 27-route-group lockout. (Wire an `rbac:verify` gate if not yet.)
- [ ] Set `AUTH_GATE_BEFORE_SUPERADMIN=true` explicitly in prod env (intent visible,
  not resting on the config default).
- [ ] `audit:verify-immutability` after `migrate` — confirms the `activity_log`
  triggers survived the deploy.
- [ ] Every FK-dropping migration's `down()` verified for **re-upgrade**, not just
  rollback (the found-once MySQL leftover-index bug).
- [ ] `npm ci && npm run build` **before** `artisan optimize` — `resources/js/routes`
  and the Vite manifest are gitignored, so a fresh checkout has neither. Skipping it
  500s every page whose entry is missing from the manifest.
- [ ] `php artisan optimize` — and treat it as a **check**, not a formality:
  `route:cache` is the only thing in the whole floor that rejects two routes sharing
  a name (see `clone-dress-rehearsal.md` § 4d).
- [ ] `php artisan queue:restart` after the code is in place — `QUEUE_CONNECTION=database`
  and workers hold the old code in memory until restarted.
- [ ] Browser click-through before promotion — the only gate that renders a page.

## Phase 1 — immediately after deploy (verification)

- [ ] Confirm the scheduler actually runs in prod (`authz:prune` fires) —
  registration ≠ execution.
- [ ] Production snapshot for the one-way slice-(i) migration, with a **named
  owner's written acknowledgement** at the moment of crossing.
- [ ] Confirm the RBAC stack's routes resolve (the 299-route oracle holds against
  the deployed tree).

---

## Track A — enforcement → §24 closure (the authorization finish line)

The reason the workstream exists. §24 closes only when all four hold: authz-lint = 0
(already ✅), `AUTHZ_ENFORCE=true` in prod, observation evidence reviewed, enforcement
verified live.

- [ ] **A3 (a wait, not a task).** Observe-mode evidence accrues on real prod traffic
  in `authz_observations`. The clock starts *at deploy*. Retention is 30 days,
  pruned; do not let observe-mode take prod traffic until the scheduler is verified
  (Phase 1).
- [ ] **Review the evidence (§24 condition 3).** Using `authz:observations
  --summarize` / the A2 tooling: classify every would-be denial as *expected* or a
  *legitimate-access regression*. The tooling `exit 1`s until every class is
  classified — that is the gate.
- [ ] **A4 — enforce in staging.** `AUTHZ_ENFORCE=true` in staging; drive every
  per-role flow; classify each denial; fix each legitimate-access regression as its
  **own reviewed change** (not a blanket revert).
- [ ] **A5 — enforce in prod (§24 condition 4).** `AUTHZ_ENFORCE=true` in prod; live
  403 verification (a real permission-less request receives 403, confirmed live).
  **This closes §24.** User-facing change — announce (⚑), jointly scheduled.
- [ ] **A6 — Authz teardown** (ADR 0043 §5, gated on A5 + **one stable release
  cycle**). In order, each step preserving authorization: migrate all 46 `Authz::`
  call sites to their permanent home (Policy / FormRequest `authorize()` / Gate /
  `abort_unless`) → assert no `Authz::` remains → delete `App\Support\Authz` → remove
  `AUTHZ_ENFORCE` + `config/authz.php` enforce key → drop `authz_observations` +
  `authz:prune`/`observations` + schedule entry → delete rollout-only tests, **keep**
  `AuthorizationOrderingTest` + `FortifyPostureTest`.

## Fail-closed rollout + Debt 7

- [ ] **Notice / Export — prod enablement**, after the staging soak proves the audit
  was complete against real traffic. Per-model env-list entry, independent revert.
- [ ] **Further B-waves** on Finance-read models (`Student`, `StudentCurriculum`,
  `Invoice`, `Payment`, …) — each gated on its own request-path audit **and** Finance
  coordination (⚑ I2: flipping a Finance-read model from read-unscoped to throwing is
  a coordination point), and only when Finance is not mid-churn on that model.
- [ ] **B-7 — close Debt item 7.** Remove the `auth()->check()` gate so the
  fail-closed throw is transport-agnostic (ADR 0042 direction); remove the residual
  `catch (\Throwable)` fail-open. Gated on enough B-waves that every job/command-
  touched model is audited (⚑ — widens the throw to Finance's future scheduled jobs).

## 0045-C — the super_admin de-bypass

The subtractive step of ADR 0045. **All build (B1/B2) is done; this is the flip.**

- [ ] **Gates, all required:** C2/C3 live and stable · B2 verified in prod · prod
  grant-set **by-name** parity (super_admin = canonical, `rbac.impersonate` present —
  a count check is insufficient) · the A5-reframed **pre-C usage audit** (every
  current super_admin domain action mapped to impersonation or a named break-glass
  command).
- [ ] Stand up the **break-glass artisan commands** (per-incident, named, under
  `runFor`, audited) as the sanctioned path for anything impersonation can't express.
  Authorization is operational (prod shell access), not app-enforced — stated, not
  implied.
- [ ] **The flip:** narrow `Gate::before` to the platform-admin set, remove the
  super-admin bypass, remove `AUTH_GATE_BEFORE_SUPERADMIN` + its guard test + the
  runbook line; live 403 regression (super_admin hits a school-scoped ability with no
  impersonation session → 403). Retires the flag pinned at deploy.

## S7 — remove `users.school_id` + `school_user`

Expires ADR 0042's recorded debt. Fully one-way at the end.

- [ ] Run the **prod divergence count** (the S7 SQL set) — the authoritative number;
  dev's 0/0 agrees by construction and proves nothing. Non-zero → a backfill
  decision (real access for real people), resolved before the flag.
- [ ] Enable `rbac.single_source_access`; run the **parity soak** — dual-compute both
  paths per decision, full coverage matrix (every user category, ≥2 Schools, HTTP +
  queue), zero unexplained mismatches.
- [ ] Repoint the three direct `school_user` readers (`GuardianService`, `Teacher`,
  `Guardian`) to `model_has_roles` **before** the pivot drop — they sit outside the
  flag.
- [ ] Rollback rehearsal → **STOP-for-review** → drop `users.school_id` +
  `school_user` (working `down()`, boundary-lint baseline 5→1). One-way.

---

## Human rulings — gather any time, not deploy-gated

- [ ] **A5 pre-C usage audit** — whoever operates super_admin: enumerate every
  super_admin domain action today; each needs a home (impersonation or a named
  break-glass command) before 0045-C.
- [ ] **Break-glass ruling ratification** — architecture owner: confirm ADR 0045 A4
  (no standing permission; per-incident audited artisan commands).
- [ ] **I6 (Finance-owned)** — Finance seeds its 4 roles, which unblocks tightening
  the interim `finance.access` and setting the Finance-role `two_factor_required`
  defaults (subject to the `rbac.two_factor_enforced` platform flag).

---

## Definition of done — three distinct milestones, not one

1. **Deployed** — the stack is in prod, authorization runs in **observe-mode**
   (records, never blocks). RBAC is *live but not enforcing*. `super_admin` still has
   the ambient bypass; legacy columns still present.
2. **§24 closed (A5)** — authorization **enforces** in prod (permission-less request →
   403), evidence reviewed, verified live. This is the functional finish line for
   *authorization*. Scaffolding still present.
3. **Fully implemented** — teardown complete: `Authz` and `AUTHZ_ENFORCE` gone (A6),
   the super_admin bypass gone and impersonation the sole domain path (0045-C),
   `users.school_id`/`school_user` dropped (S7), fail-closed enabled on its target
   models and Debt 7 closed (B-7). Several of these sit behind "one stable release
   cycle" after §24.

Between (1) and (2), if enforcement surfaces a legitimate-access regression, the fix
is its own reviewed change — not a rollback of enforcement. Between (2) and (3), the
system is fully *functional*; what remains is removing the transitional machinery so
no future reader mistakes scaffolding for the mechanism.
