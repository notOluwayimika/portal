# Contributing

This document is the repository's working copy of the **Architecture
Constitution** (spec §11, ADR 0035) plus the delivery workflow and CI gates.
The Constitution is non-negotiable: violations fail CI, not code review, and
changing any rule requires an ADR (see [docs/adr/](docs/adr/README.md)).

**Authority:** the v10 Specification is the *architecture* authority — the
Constitution below and every technical decision come from it. The approved
Execution Plan governs **only sequencing, rollout and milestone packaging**;
it does **not** supersede any architectural decision. See
[docs/roadmap.md](docs/roadmap.md) for the full reconciliation.

## The Architecture Constitution — 16 non-negotiables

**Boundaries**
1. **`school_id` is the only isolation boundary.** No cross-School reads, writes or transactions — ever.
2. **Modules own their data.** No Module reads another's tables, models or repositories.
3. **No cross-Module database coupling.** `DB::table()` on another Module's tables is banned.
4. **No circular dependencies.** The Shared Kernel never depends on a Module.
5. **A Module's public API is `Contracts`/`Events`/`Enums`.** Everything else is private.

**Layering**
6. **No business logic in controllers.** Controllers validate, authorize, delegate, respond.
7. **Actions own transactions.** Services orchestrate and query. Controllers never open a transaction.
8. **Policies own authorization.** Route middleware is defence-in-depth, never the only gate.
9. **Events for cross-Module side effects. Contracts for cross-Module reads.**

**Money & data**
10. **Money is integer minor units + explicit currency.** Never float. Never `decimal:N`. (ADR 0002/0037/0038/0039)
11. **The Ledger is append-only.** Corrections are contra entries. Nothing is ever deleted.
12. **Balances derive from the Ledger** and materialize only as a **lockable projection**.
13. **School context is explicit or absent** — never inferred, never defaulted from a user column. (ADR 0026/0042)
14. **The audit log is permanent** and delete-protected at the database level.
15. **Authorization checks are never commented out.**
16. **Architecture changes require an ADR.**

Two platform rules that follow from these and are already enforced:

- **Authority and isolation are orthogonal axes.** `super_admin` bypasses
  *authorization* (`Gate::before`, `EnsureRole`) but never *isolation*
  (`SchoolScope`). School-owned data requires an active School for everyone.
  (ADR 0036)
- **Off-request School context comes only from `ActiveSchool::runFor()`**
  (jobs use the `SchoolAware` middleware). Impersonating a causer via
  `auth()->setUser()` to obtain context is banned in new code. (ADR 0026)
  _Carve-out (ADR 0045 A1):_ a sanctioned super-admin impersonation session —
  bounded, entry/exit audited, context set explicitly from `(user, school)`,
  causer pinned to the operator — is distinct on every axis and permitted.

## Workflow

- One PR per slice, branched off **`staging`** — never off another unmerged
  branch. Conventional Commits with scope (`feat(isolation): …`).
- Slices merge into `staging` (integration branch: CI must pass, manual
  validation, cross-slice compatibility). Only a validated milestone merges
  from `staging` into `main`.
- Behavioral cutovers ship dark behind temporary rollout flags
  (`config/rbac.php`, `config/auth.php`) and are enabled per environment /
  per model / per job after verification.
- **Verify by driving the app**, not tests alone: migrate the dev database and
  exercise the affected flows before calling a slice done.

## CI gates & baselines (all ratchets — baselines only shrink)

| Gate | Where | Baseline |
|---|---|---|
| Test failures | `tests.yml` → `bin/ci-test-ratchet.php` | `tests/ratchet-baseline.txt` |
| tsc errors | `lint.yml` → `bin/ci-tsc-ratchet.php` | committed count |
| Commented-out authz (rule 15) | `lint.yml` → `bin/ci-authz-lint.php` | `authz-lint-baseline.txt` |
| Boundary rules (§17.2: school-id fallbacks, decimal money casts, `fee_*` outside Finance, Finance escape hatches, `forceCreate` in Finance tests) | `lint.yml` → `bin/ci-boundary-lint.php` | `boundary-lint-baseline.txt` (entries carry documented expiries) |
| Architecture rules (§17.1) | `lint.yml` → `pest --group=arch` (`tests/Arch/`) | none — hard; Finance rules auto-activate when `app/Finance` appears |
| Static analysis | `lint.yml` → `composer analyse` (Larastan **level 5**, fixed for Phase 1; **baseline-relative** — see [roadmap decision](docs/roadmap.md)) | `phpstan-baseline.neon` |

Baseline mechanics: the lint baselines are **content-keyed**
(rule + file + trimmed line), not line-number-keyed — see ADR 0041 for the
trade-off and its one residual. When you fix a baselined finding, remove its
entry in the same PR (the gates report removable entries).

If your PR turns a gate red, the fix is to fix the violation — never to widen
a baseline. Baselines are regenerated only when a slice deliberately accepts a
documented, expiring exception, and that requires review.

## Testing

- Whole suite on **MySQL** (`portal_testing`); `phpunit.mysql.xml` refuses
  live-named databases. See [docs/testing.md](docs/testing.md).
- New money code must round-trip through `MoneyCast` in tests — `forceCreate`
  bypasses casts and is banned in Finance tests (boundary lint).
