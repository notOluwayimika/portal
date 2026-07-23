# 0045 — `super_admin` has no ambient authority; domain action is via impersonation

**Status:** **Accepted (2026-07-22), then AMENDED (2026-07-23) — see the Amendment
at the foot of this ADR.** The direction is unchanged and binding; the amendment
records that a load-bearing premise (impersonation exists) was false, discovered by
slice 0045-A's investigation, and revises scope accordingly. Read the amendment
before acting on the original Decision/Teardown sections — where they conflict, the
amendment governs.

_(Original status line, kept for the record:)_ Proposed (2026-07-21). Supersedes
the super-admin behaviour of ADR 0005
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

---

## Amendment (2026-07-23) — impersonation does not exist; scope revised

Slice 0045-A (investigation, #100, `docs/handoff/0045-a-findings.md`) was written to
**audit** impersonation's correctness against the four claims in §4. It found a third
outcome the brief did not anticipate: **there is no impersonation mechanism to
audit.** Every `impersonat*` reference in the tree is the `auth()->setUser($causer)`
job hack that slice 1.3b eradicated and §5.6 bans; the "masking scope" production
defect (roadmap Invariant 3) was *that* hack, not a session feature. The premise
under which this ADR's §4 and Teardown §2 were written — "impersonation exists,
re-derive its correctness" — was false.

**The direction is unchanged.** No ambient authority; domain action via
impersonation; SoD as detection-not-prevention backed by operator attribution. What
changes is that impersonation must be **built**, and the four claims convert from
*audit targets* into *build acceptance criteria* landing red-first at the feature's
birth. This is recorded here per the governance rule that an invalidated premise is
amended by ADR, not quietly worked around.

### A1 (corrected by step 0, 2026-07-23) — operator attribution is the invariant; the mechanism is a bounded acting-as session

Step 0 corrected this section's original wording. "Session wrapper, never identity
swap" is infeasible: `PermissionMiddleware` reads `$authGuard->user()` directly and
calls `$user->canAny(...)` — no injection point, and the swappable `Gate`
`$userResolver` is never consulted. Resolving C2's 28 groups + policies +
FormRequests as the impersonated user therefore requires setting the acting user
**on the guard** for the bounded session; anything else is the per-check-site
rewrite. The original wording failed contact with the framework — recorded here,
the same premise-fails-reality pattern this amendment exists to document.

**Binding constraint:** Operator attribution is the invariant. The acting-as
session sets the impersonated principal on the guard — bounded, entry/exit
audited, `(user, school)` explicit, all context finally-restored — with the audit
causer pinned to the operator for the session's duration. What remains forbidden
is the ADR 0026 shape: an unbounded, unaudited identity swap whose attribution
follows the swapped identity.

**Mechanism (tree-derived):** the session takes `(user, school)`, sets **three**
things and restores **all three** in a `finally` (built on `runFor`'s
captured-prior-state + finally-restore shape — restore to the CAPTURED values,
never a hardcoded baseline, so a mid-session throw or nested context set cannot
strand the wrong context): guard user → impersonated user; `ActiveSchool`
override → target school; permissions-team (`setPermissionsTeamId`) → target
school. Attribution is enforced mechanically: spatie's `CauserResolver`
resolver-level override pins the operator once per session, not per write-site.
The exit bite-proof asserts the three restores INDEPENDENTLY (a finally that
restores two of three is a silent team leak, the `NoTeamLeakBetweenJobs` family).

**§5.6 / ADR 0026 carve-out** (operative text amended in CONTRIBUTING.md): the
ban targets the off-request context hack — swap identity so context resolves,
attribution silently lands on the faked causer, unbounded, unaudited. A
sanctioned impersonation session is distinct on every axis and permitted:
on-request; context set **explicitly** from `(user, school)` — it does not set
the user *to obtain* context; attribution pinned to the operator; entry/exit
audited. Outside the ban's letter; the carve-out is recorded because mechanical
adjacency is when governance should name the distinction.

### A2 — the track is re-sliced

- **0045-B1 — build the impersonation session mechanism** (per A1). The four §4
  claims are its birth acceptance, red-first (planted failure watched red *before*
  the feature makes them green): context set as impersonated user; every action
  audit-attributed to the operator; session bounded (no leak — `NoTeamLeakBetweenJobs`
  class); clean exit to baseline. Security-critical; its own adversarial pass.
- **0045-B2 — additive foundation.** Seed super_admin's explicit platform-admin set
  (self-healing, see A3) and rewrite `SuperAdminAuthorityTest` to the new invariant.
  Inert while the bypass is on.
- **0045-C — subtractive, gated** (unchanged from Teardown §5, plus A3's prod gate).

### A3 — self-healing seed for super_admin (from finding #2)

Dev's super_admin row has drifted to **7 of 15** grants, and the seeder's
non-destructive contract (C6 — runtime matrix edits survive `rbac:sync`) never heals
a drifted super_admin row. After the de-bypass, super_admin's access *is* its explicit
grant set, so silent prod drift would strand it.

**Resolution — super_admin is exactly the role for which a self-healing seed is safe,
because C6 (D1) made its row immutable at runtime.** There are no legitimate runtime
grants to preserve, so healing to canonical cannot clobber anything the
non-destructive contract exists to protect. B2 seeds super_admin's platform-admin set
**self-healingly** (re-asserted to canonical every run), documented as the deliberate,
immutability-justified exception to the non-destructive contract.

**New gate on 0045-C:** super_admin's **production** grant set must equal canonical
(the heal must have run and been verified in prod) **before** the bypass is removed —
an environment gate, same class as the others; a drifted prod row + de-bypass =
super_admin stranded.

### A4 — break-glass ruling

**No standing permission.** A persistent bulk-repair grant is ambient latent power
that exists 100% of the time for an operation used ~0% of the time — the exact
anti-pattern this ADR removes. The one in-tree precedent (the commented `/cleanup`
bulk-repair route) is retired in favour of **per-incident, named, reviewed artisan
commands run under `runFor`, fully audited**. Honest scope: such a command's
*authorization* is **operational** (who holds production shell access), not
application-enforced — the app makes it auditable, not gated. That is acceptable for
a deliberate break-glass escape hatch, and is stated rather than implied.

### A5 — open operational question (shapes B1↔C sequencing)

Does any real workflow today depend on `super_admin` performing **domain** (not
platform-admin) actions? It *can* via the bypass — but if it does not in practice,
then C (remove bypass) and B1 (build impersonation) **decouple**: super_admin could
ship platform-admin-only first with no capability gap, and impersonation follows. If
it does, B1 must precede C. This is an operational question for whoever runs
super_admin in practice — answer it before committing to "B1 blocks C."

### A6 — Finance

Finance accepted this ADR on its SoD/attribution posture. That posture is now a
**from-scratch build** (B1), and operator-attribution — the thing Finance cared about
— is B1's core, not a later wiring step. Keep Finance in the loop when B1 opens
(announce, not re-approval — the direction they accepted is unchanged). No
`app/Finance/**` access at any point in the track.
