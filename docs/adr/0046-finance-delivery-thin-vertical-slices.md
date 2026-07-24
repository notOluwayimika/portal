# 0046 — Finance delivery shape: thin vertical slices (UI + e2e with domain)

**Status:** Accepted (owner-adopted, Segun) — 2026-07. **Deciders:** owner + advisor.
Supersedes the advisor working note _"Finance — plan adjustment: thin vertical
slices"_ (which mis-scoped an intermediate "defer UI" step); this ADR is the settled
record. Changes the DELIVERY SHAPE only — not the domain architecture, the Constitution,
the enforcement floor, or the API contract.

## Context

The Finance backend arc is complete and merged to staging — invoicing, waivers, VOID,
School-scoped config, the over-allocation guard, the collation floor, the wallet
(student-account projection + overpayment→credit + apply-forward), and §10 C1 (credit
notes & write-offs) — plus an **API acceptance harness** (`FinanceApiAcceptanceTest`)
that proves the slices compose through the real HTTP stack, and the closed W3
read-modify-write money guard.

Every slice to date is **backend/domain only**. v10 specifies "13 **independently
shippable** Finance phases" — each meant to ship its screens, with admin UI woven through
Phases 3/6/7 and the parent portal in Phase 8. The **execution drifted** to backend-first
and shipped no Finance UI: the parent dashboard is a stub, `formatNaira()` (named by the
Constitution) is unbuilt, and the backend is **value-inert** — a financial control system
no one can yet operate.

Staffing: the build is currently owner + AI agent; a **second developer joins from Phase
8** (the parent portal). There is no separate frontend developer before then.

## Decision

Adopt **thin vertical slices** as the Finance delivery shape for the remaining work: each
increment ships **domain + its UI + end-to-end validation together**, rather than backend
in isolation.

1. **Build the admin/bursar UI now** (owner + agent) over the completed backend — the
   operational screens (view statement, record payment, issue credit note). This
   back-fills what the backend-first drift skipped and re-aligns with v10's per-phase-UI
   intent.
2. **Remaining Phase 1–7 backend ships as thin verticals** — each new domain increment
   (maker-checker/Ph3, the rest of the Phase-6 payments engine, percentage credits/C2)
   ships with its UI and e2e, not backend-only.
3. **The API acceptance harness is the standing per-phase integration + contract gate** —
   extended to cover each new phase's flows. Net-new practice, retained regardless of UI.
4. **The parent portal remains Phase 8**, owned by the incoming developer, gated on fixing
   the broken guardian portal-invite delivery. Admin UI is _not_ deferred to them.

What does **not** change: the v10 domain architecture, the Architecture Constitution, the
enforcement floor (`bin/quality`, DB guards, bite-proofs), and the API-first contract
(`/api/v1/finance/*`). Only the delivery _shape_ returns to v10's intent.

## Rationale

- The backend-first drift was defensible for building the hard domain core under maximum
  rigor, but continuing it through all remaining phases accumulates unused, value-inert
  code toward a big-bang integration — the exact risk "independently shippable" was
  designed against.
- The acceptance harness (step 1) already closed the _integration-validation_ half of the
  concern mode-natively — so a real UI is no longer needed to _validate_ the backend, only
  to _deliver value_ and exercise the API contract against a real consumer (the class of
  bug the guardian-modal incident showed only a real consumer surfaces).
- Owner + agent have capacity for both modes; the incoming developer's scope is the parent
  portal, so building admin UI now does not conflict with them and gives them a working
  system to inherit.
- No acute urgency forces UI (payments are not operationally live), but incremental value +
  real-consumer contract validation beat deferral, and the harness makes the interleave
  safe.

## Consequences

**Positive:** value delivered incrementally; the API contract is exercised by a real
consumer, not only tests; no big-bang UI integration at the end; the incoming Phase-8
developer inherits a working, harness-proven system plus `formatNaira`/conventions already
established.

**Negative / accepted:** the workstream now spans two validation modes (DB bite-proofs +
component/e2e), and the advisor review discipline adapts accordingly; some API churn is
likely when real screens or the incoming developer surface UX-driven needs — mitigated by
the harness catching regressions.

**Neutral:** admin/bursar UI ownership is explicitly the owner + agent (not the Phase-8
developer); this is recorded here so it is not orphaned.
