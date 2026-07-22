# 0045 — `super_admin` has no ambient authority; domain action is via impersonation

**Status:** Proposed (2026-07-21). Supersedes the super-admin behaviour of ADR 0005
(`Gate::before` bypass) and makes ADR 0040 structural rather than grant-dependent.
Design only — no code changes until reviewed, and it does **not** alter the merged
C2/C3 stack, which deploys under the current (bypass-on) model while this is
designed.

## Context

`Gate::before` (ADR 0005) makes `super_admin` pass every authorization check —
ambient platform-wide god-mode, controlled by the `AUTH_GATE_BEFORE_SUPERADMIN`
flag. The authority probe (#75) confirmed this empirically (**Mode A**): while the
flag is on, the bypass decides every Gate-routed check, so `super_admin`'s seeded
permission set is irrelevant to what it can actually do.

This makes every guarantee about what `super_admin` *cannot* do a guarantee made of
an **absence** — no seeded grant — which any bypass defeats. ADR 0040
(`super_admin` never overrides maker–checker) rests on exactly such an absence, and
C1–C6 repeatedly had to pin "super_admin stays at exactly 15 permissions" as a
proxy for a control the bypass could still walk through. The probe already recorded
the deeper finding: a control that lives in the absence of a grant is not enforced,
it is merely unexercised.

## Decision

**`super_admin` has no ambient authority over any school-scoped or domain
operation.** Its direct authority is limited to a defined **platform-admin**
surface; everything else is performed by **impersonating** a specific user in a
specific School, acting *as* that user, on the audit trail.

### 1. What `super_admin` retains as itself

- **School lifecycle** — create / configure / archive Schools.
- **Cross-School user management** — provision users, assign roles, manage access
  across Schools (the existing `/super-admin` module surface).
- **Impersonation** — the control to enter a scoped session as a named user.

Nothing school-scoped or domain-level: no result approval, no enrollment mutation,
no Finance action, no academic write — **as `super_admin`**. These are reachable
only inside an impersonation session, where the actor is the impersonated user.

### 2. The bypass is narrowed, then removed

`Gate::before` no longer returns `true` for `super_admin` on arbitrary abilities.
Super-admin authority derives from the **explicit platform-admin permission set**
(§1), evaluated like any other role. The `AUTH_GATE_BEFORE_SUPERADMIN` flag is a
transitional control for this migration (like `AUTHZ_ENFORCE` / ADR 0043): it is
removed when the model lands, not kept as permanent config.

### 3. Segregation of duties becomes detection, not prevention (the honest posture)

Impersonation means `super_admin` *can* act as a checker by impersonating one. It
cannot be *prevented* from impersonating a maker to submit and a checker to approve
the same record — the structural `decided_by ≠ initiator_id` rule sees two distinct
identities and passes, though one human drove both. This is inherent to any
ultimate-admin account and is accepted:

- **The control is non-repudiation.** Every impersonated action is attributed, in
  the durable audit log, to the human `super_admin` behind the impersonated
  identity — not merely to the impersonated user. ADR 0040's third decision (a
  super-admin-initiated action "raises an exception signal in the audit trail")
  stops being a Ph11 placeholder and becomes **load-bearing**: it is what makes
  maker–checker abuse by `super_admin` detectable after the fact.
- **Prevention is retained where it is real** — a single *non-impersonating*
  identity still cannot both submit and approve the same record (structural rule,
  ADR 0044). Impersonation does not weaken that; it only means `super_admin` is not
  a magic exception to authorization, it is a logged act of becoming someone.

### 4. Impersonation correctness is a precondition, not an assumption

Impersonation already exists and already caused a production defect —
"impersonation masking scope" (roadmap Invariant 3). Making it the **sole** path to
super-admin domain action multiplies its criticality, so its correctness must be
re-derived and bite-proven **before** this model lands:

- an impersonation session sets `ActiveSchool` / permissions-team to the
  impersonated user's context correctly (the masking-scope defect was exactly this
  going wrong);
- every action inside the session writes an audit row attributing it to the
  `super_admin` operator, not only the impersonated user;
- the session is bounded (explicit enter/exit, no ambient leakage into the next
  request — the class of `NoTeamLeakBetweenJobs`);
- exiting returns `super_admin` to its no-ambient-authority baseline.

## Consequences

- **ADR 0040 becomes almost trivially true and structurally so:** `super_admin`
  cannot approve as itself because it has no authority as itself. The guarantee no
  longer rests on a seeded absence.
- **The probe invariant is rewritten, deliberately.**
  `SuperAdminAuthorityTest` flips from "super_admin passes via the bypass / holds
  exactly 15" to "super_admin is **denied** on school-scoped abilities as itself,
  and gains them **only** within an impersonation session." Same discipline as
  every prior invariant change: predict, rewrite, bite-prove — not a quiet edit.
- **Ask 1's flag question changes shape:** `AUTH_GATE_BEFORE_SUPERADMIN` becomes a
  flag to *remove*, not merely confirm. The staged C2/C3 stack still deploys under
  the current model; this is future work sequenced with the A-track teardown.
- **A break-glass question is opened, not closed here:** whether there exists any
  platform operation that impersonation cannot express (bulk data repair mid-
  incident). If so, it needs a *named, narrowly-scoped, loudly-audited* direct path
  — not a reinstated general bypass. Flagged for the review, not decided.

## Migration / teardown sequence (deletion must never remove a control)

1. Enumerate `super_admin`'s legitimate platform-admin abilities; seed them as its
   explicit permission set (§1).
2. Re-derive and bite-prove impersonation correctness (§4).
3. Make ADR 0040's audit signal real (attribution of impersonated actions to the
   operator).
4. Rewrite `SuperAdminAuthorityTest` to the new invariant; bite-prove red-when-
   reverted.
5. Narrow `Gate::before` to the platform-admin set, then remove the super-admin
   bypass; remove `AUTH_GATE_BEFORE_SUPERADMIN`.
6. Regression: a `super_admin` request to a school-scoped ability with no active
   impersonation session receives 403 (live check, not config).

Step 2/3 before step 5 is load-bearing: removing the bypass before impersonation is
proven correct and audited would strand super-admin operations with no sound path.

## Related

- ADR 0005 (the `Gate::before` bypass this supersedes for `super_admin`).
- ADR 0040 (super_admin never overrides maker–checker — made structural here).
- ADR 0043 (the "transitional flag with a defined teardown" pattern this follows).
- ADR 0044 (structural maker≠checker, which this leaves intact and relies on).
- roadmap Invariant 3 (impersonation masking scope — the precondition risk).
- Authority probe #75 (`SuperAdminAuthorityTest`, Mode A) — the invariant rewritten.
