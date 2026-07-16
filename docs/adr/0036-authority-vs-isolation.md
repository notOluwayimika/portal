# 0036 — Authority and isolation are orthogonal axes

**Status:** Accepted · implemented (M1.3c: `app/Models/Scopes/SchoolScope.php`)

## Context

`super_admin` is a team-less platform-support role. The `Gate::before` bypass
(ADR 0005) and the `EnsureRole` wrapper let it pass every *authorization* gate.
An early fail-closed implementation also exempted super admins from the
*isolation* scope — which would have made "super admin with no active School"
an unscoped read of every School's business data: a new fail-open path.

## Decision

**Authority (who may perform an action) and isolation (which School's data is
visible) are separate axes, and `super_admin` bypasses only the first.**

- `Gate::before` / `EnsureRole`: super_admin passes authorization checks.
- `SchoolScope`: no super-admin exemption, deliberately. Access to
  School-owned (`BelongsToSchool`) data requires an active School context for
  everyone — super admins select a School (or an approved impersonation flow)
  like anyone else.
- Platform models (`User`, `School`, `Role`) are not School-scoped, so
  platform operations (choosing a School, managing admins) remain globally
  reachable.

## Consequences

- With fail-closed enabled (per-model, `rbac.fail_closed_models`), a
  super_admin without context gets a clean 409 (`MissingSchoolContextException`)
  on school-scoped routes while platform routes stay 200 — regression-tested at
  model, console, worker and HTTP-route level
  (`tests/Feature/Isolation/`).
- Cross-School reads for platform features must be **declared read models**
  (explicit `withoutGlobalScope`, greppable), never an ambient exemption.
- Ph3 corollary: authority bypass never extends to approval semantics —
  ADR 0040.
