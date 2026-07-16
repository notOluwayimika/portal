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
