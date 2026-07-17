# 0043 — `App\Support\Authz` is temporary authorization-rollout scaffolding

**Status:** Accepted. This ADR fixes the *end-state* of the S5 authorization
rollout so the scaffolding cannot silently become a second, permanent
authorization framework alongside Laravel Policies/Gates.

## Context — why a rollout gate exists at all

Phase 1 inherited **53 commented-out authorization checks** across 7 controllers
(Constitution rule 15; risk #26). Restoring them all at once would flip a large
set of endpoints from "returns 200" to "returns 403" in one deploy, with no
evidence of who legitimately relies on the current (unauthorized) behaviour — a
guaranteed production incident. The safe path is **observe → analyse → enforce**:

- **`App\Support\Authz`** — every restored check is expressed as one call
  (`abilityCheck()` for `->can()` permissions; `ensure()` for boolean
  ownership/business-rule guards). In **observe** mode it evaluates the check,
  records a would-be denial to `authz_observations`, and **continues** (never
  blocks). In **enforce** mode it `abort()`s on failure.
- **`config/authz.php`** (`AUTHZ_ENFORCE`, default `false`) selects the mode.
- **`authz_observations`** — one row per would-be denial, queried via
  `php artisan authz:observations`, so the operational impact of each check is
  measured on real traffic before any request is blocked.

This clears the commented-authz debt (the checks become live code) while
deferring the *behavioural* cutover to a single, evidence-backed enforcement
slice.

## Decision

### 1. `Authz` is transitional scaffolding, not a framework

`Authz` exists to sequence the rollout, not to become the app's authorization
API. Its removal is planned, its consumers are bounded, and Laravel's
Policies/Gates remain the long-term mechanism.

- **Long-term authorization is Laravel-native.** Controllers authorize via
  Gates / Policies / FormRequest `authorize()` — the mechanism ADR 0035
  (Constitution) and the 1.2d reference Policy already establish. `Authz` is a
  migration shim over the *legacy* commented checks, chosen over restoring them
  directly to bare `->can()` so the rollout can observe before it enforces.
- **No new business logic may depend on `Authz`.** Finance (Ph2+) and every
  other new module authorize through Policies/Gates from their first commit.
  `Authz` call sites are confined to the specific legacy controllers enumerated
  by the S5 rollout; an arch/boundary check should fail a new `Authz` reference
  outside that legacy set. `Authz` is not part of any module's public API.

### 2. End-state (two permitted outcomes, one default)

- **Default — delete it.** Once §24 authorization closes (roadmap: the four-part
  checkpoint — lint 0, `AUTHZ_ENFORCE=true` in prod, evidence reviewed,
  enforcement verified active) and enforcement has been stable for one release
  cycle: fold each `Authz` call back into the Laravel-native form (a Policy/Gate
  or a direct `$user->can()` / `abort_unless`), delete the `enforce`/`observe`
  branch and `record()`, drop `config/authz.php`'s `enforce` key + the
  `AUTHZ_ENFORCE` env var, and prune + drop `authz_observations` (§4).
- **Exception — keep as a thin wrapper.** `Authz` may survive *only* as a
  zero-logic convenience over `Gate`/`$user->can()` (no second mode, no
  observation, no policy decisions of its own). Retaining it in any richer form —
  a permanent enforce/observe toggle, authorization logic that lives nowhere
  else — is a **reversal of this ADR and requires a superseding ADR** justifying
  why it is not a second authorization framework. Absent that ADR, the default
  (delete) is binding.

### 3. Authorization ordering is fixed (context before any authz decision)

No authorization decision may evaluate before School context is established, or
it risks deciding against the wrong School. Every protected endpoint resolves in
this order, enforced by middleware ordering (`SetSchoolContext` runs before any
permission/policy check) and covered by a regression test
(`tests/Feature/Rbac/AuthorizationOrderingTest.php`):

1. **Authentication** — `auth` (the user is known).
2. **School context** — `SetSchoolContext` sets `ActiveSchool` +
   `setPermissionsTeamId()` (permissions resolve in the active School's team).
3. **School isolation** — `SchoolScope` / `canAccessSchool()` (the user may act
   in this School at all).
4. **Route middleware** — `role:` / `permission:` route guards.
5. **Permission / policy checks** — `Authz` (rollout) → Policy/Gate (long-term).
6. **Business-rule validation** — state guards (archived/locked/closed), which
   are **not** authorization (§4 below).

The regression test proves a permission check cannot evaluate against the wrong
School because context is not yet established (a permission granted only in
School A must not authorize the same action while the active context is School B).

### 4. `authz_observations` is temporary evidence, and business rules are not authorization

- **The table is temporary rollout evidence, not permanent telemetry.** It is
  platform infrastructure (no `BelongsToSchool`; `school_id` captured as data),
  written fail-safe (a failed insert is logged, never raised, so observation can
  never break a request), timestamped on the audit clock (`now()`), and
  **pruned** (`php artisan authz:prune`) with the table dropped when the rollout
  completes (§2). It is deliberately *not* the audit log (that is
  `activity_log`, delete-protected per ADR 0032) and *not* 1.4d observability
  (Sentry/Horizon). Unbounded growth is bounded by pruning; volume is naturally
  low because rows accrue only on would-be denials.
- **Business-rule checks are state validation, not authorization.** Guards like
  "subject is archived before it can be restored" (409) or
  "record not already approved" answer *may this action happen in this state*,
  not *may this user perform it*. They have their **own** rollout, are **never**
  gated by `AUTHZ_ENFORCE`, must never record as permission denials, and are not
  counted in the authz-lint baseline. Restoring them is orthogonal to §24.

## Consequences

- The commented-authz debt clears without a big-bang 403 cutover; enforcement is
  one reversible, evidence-backed flag flip.
- The scaffolding has a defined owner, removal condition, and deletion plan
  (roadmap §24 record), so it cannot ossify into permanent debt.
- New code has exactly one authorization mechanism (Policies/Gates); `Authz` is
  invisible to it.
- **Expiry anchor:** this ADR is discharged when `authz_observations` is dropped
  and `AUTHZ_ENFORCE` is removed. Until then it is live debt tracked in the
  roadmap Continuous list.

## Related

- ADR 0035 (Constitution — authorization by permission, never commented out).
- ADR 0032 (audit log is the durable record; `authz_observations` is not it).
- ADR 0042 (the sibling "recorded debt with an expiry" pattern).
- roadmap.md — the §24 four-part checkpoint and the `AUTHZ_ENFORCE` removal plan.
