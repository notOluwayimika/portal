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

**Continuous — open:** 1.2c1–c3 (34 commented-authz entries remain of 53) ·
1.2f remainder (drop `users.school_id` + `school_user` after parity; expires
ADR 0042's debt) · fail-closed per-model enablement (jobs no longer block it;
per-model request-path audit remains the gate) · 1.4b–e (Sequences, audit immutability,
observability, event bus) · frontend `formatNaira` (§12.3 names it; only ad-hoc
`toLocaleString` rendering exists today).

**Rollout flags currently dark:** `auth.gate_before_superadmin` (on by
default, verified) · `rbac.single_source_access` (off; parity-gated) ·
`rbac.fail_closed_models` (empty; per-model — 1.3b landed, so job context no
longer blocks any model; each enablement still needs its request-path audit).

## Governance — current state (not intent)

- CI: `linter` + `tests` workflows run on PRs to `staging`/`main`.
- **Branch protection / required status checks are not confirmed as enabled**
  on GitHub; merges are performed by the maintainer after review. Enabling
  protection is an outstanding GitHub-settings action (v10 §17.3), not a repo
  change.
- `plan_docs/` is untracked by design; this page and the ADRs are the
  in-repo record.
