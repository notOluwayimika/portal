# 0040 — `super_admin` never overrides maker–checker

**Status:** Accepted as a binding constraint on ADR 0009 (Approvals engine, Ph3). Not yet implementable — nothing approves anything in Phase 1.

## Context

`Gate::before` (ADR 0005) makes `super_admin` pass every permission check.
Segregation of duties (maker ≠ checker, amount limits) is the core control the
Finance module exists to provide; an authority bypass that extends to approval
semantics would nullify it — one compromised or careless platform account could
both raise and approve a payment.

## Decision

When the Approvals engine lands (Ph3):

- **`finance.*.approve` abilities are excluded from the `Gate::before`
  super-admin bypass.** Approval authority comes only from an explicit,
  per-School role with configured limits.
- Maker ≠ checker is enforced at **Policy + DB level** (ADR 0009), and no role
  — super_admin included — bypasses it.
- Any super-admin-initiated Finance action raises an exception signal in the
  audit trail (Ph11 audit scope) rather than passing silently.

## Consequences

- The Ph3 Approvals ADR must implement this exclusion in its `Gate::before`
  interaction design; this ADR is its recorded, pre-agreed constraint.
- Parallels ADR 0036: authority never silently crosses a control boundary —
  isolation there, segregation of duties here.

## Implementation status (updated 2026-07-21, slice C3 `feat/rbac-policies`)

The status line above says "not yet implementable — nothing approves anything in
Phase 1". **ADR 0044 overtook that**: the moment `result.approve` / `result.reject`
existed, an approval semantics existed to protect, so the mechanisms landed in
C3 rather than waiting for Ph3. Ph3's Approvals engine inherits them; it does not
design them.

**Decision 1 — bypass exclusion: IMPLEMENTED, and generalised.** This ADR words
the exclusion as `finance.*.approve`. `result.approve` and `result.reject` do not
match that pattern, so a literal reading would have left the academic approvals
outside the very guarantee this ADR exists to give — denylist drift, arriving in
the first implementation. The implemented rule is therefore a **convention, not a
list**: *any ability whose terminal segment is `approve` or `reject` is never
bypassed* (`App\Support\ApprovalAbility`), enforced by a test that enumerates
`App\Enums\Permission` and asserts every matching case is excluded
(`SuperAdminBypassExclusionTest`). `finance.invoice.approve` is covered on the day
Ph3 creates it, with nobody having to remember this ADR. The convention also
covers **bare Policy ability names** (`Gate::authorize` passes `approve`, not
`result.approve`), without which every Policy `approve()` would still be bypassed.

**Decision 2 — maker ≠ checker at Policy + DB: IMPLEMENTED for results.**
`SubjectResultPolicy` denies a decider who is the recorded submitter, and a CHECK
constraint on `subject_result_statuses` enforces `submitted_by <> decided_by` for
every writer that never reaches the Policy. This required a schema change: the
table previously held a single `updated_by` overwritten at each transition, so
the approver's write destroyed the submitter's identity and the comparison had
nothing to compare against.

The two decisions are **independent, and both are required**. The exclusion stops
platform authority from granting approval; the structural rule stops *any* single
identity approving its own submission and survives someone later re-enabling a
bypass. Neither implies the other.

**Decision 3 — super-admin-initiated Finance actions raise an audit signal:
NOT IMPLEMENTED (Ph11 audit scope).** Recorded here so its home is known rather
than rediscovered: the plumbing now exists, so this is a classification rule, not
new infrastructure. C1 wired RBAC mutations into `activity_log` via
`App\Listeners\LogRbacChange`, and severity/sensitivity classification is already
config-driven in `App\Services\ActivityLog\ActivitySeverityService`. The signal
belongs there — an elevated-severity rule keyed on *causer is super_admin* for
activity in the Finance log channel — not as a bespoke logger inside
`app/Finance/**`, which ADR 0043's binding rule keeps free of authorization
concerns. Owner: Ph11, alongside the rest of the audit-severity work.

**Scope not covered:** this ADR's "configured limits" (per-School approval
authority with amount thresholds) remains undesigned. C3 implements
*separation*, not *limits*. That is a Finance interface-map item — the shape
should be agreed with the Finance owner before Ph3 hardens it, since the result
workflow now has a working precedent that Ph3 will otherwise inherit by default.
