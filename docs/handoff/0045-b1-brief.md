# Slice 0045-B1 — build the impersonation session mechanism — brief (drafted 2026-07-22)

`feat/superadmin-impersonation`. Builds the impersonation feature ADR 0045 assumed
existed and 0045-A found absent. **Security-critical, feature construction.** Opens
off `staging`. Additive — inert while the `Gate::before` bypass is on — so it lands
and can deploy ahead of the bypass removal (0045-C).

> ⚠️ **Drafted with only `docs/` readable.** Class names, paths, and the shape of
> `ActiveSchool::runFor` / the audit resolver are **RE-DERIVE** — read them from the
> tree before building. The design constraint below is binding regardless of names.

## Why this exists

0045-A established: no impersonation mechanism exists; the only prior art is the
`auth()->setUser($causer)` hack 1.3b killed and §5.6 bans. ADR 0045's whole model —
super_admin does domain work only by impersonating a real user, auditably — needs
this feature to be real before the bypass can come out. So B1 builds it, and the four
correctness claims that were 0045-A's *audit targets* are now B1's **birth
acceptance**, landing red-first.

## The binding design constraint (ADR 0045 Amendment A1) — do not deviate

Authorization and audit resolve against **different subjects**:

- **Authz + team/School context → the impersonated user.** Build a `runFor`-analogue
  scoped session (the `ActiveSchool::runFor` pattern) so `can()` / policies / gates
  and `SchoolScope` all resolve **as the impersonated user** for the session's
  duration.
- **Audit causer → the operator (super_admin), explicitly.** Every action inside the
  session attributes to the operator via `causedBy($operator)` (the #97 discipline),
  **never** auto-resolved from `auth()->user()`.

**Identity swap is forbidden.** `auth()->setUser($impersonated)` gets authz right but
makes audit record the impersonated user and lose the operator — reintroducing the
exact misattribution class #97 fixed, and the `auth()->setUser` shape §5.6 bans. The
two in-tree precedents are the spine: `ActiveSchool::runFor` (scoped context) + the
audit resolver / #97 explicit attribution. **Reuse them; do not invent a third
context mechanism, and do not swap identity.**

## Step 0 — re-derive before building

1. Read `ActiveSchool::runFor` and confirm it (or a sibling) can carry an
   impersonated **principal identity** for authz, not only a School id. If it only
   carries School, the session needs a thin companion that sets the authz subject —
   design it as a peer of `runFor`, not a fork of it.
2. Read how audit attribution currently resolves the causer (the resolver + #97's
   `causedBy`/`causedByAnonymous`), so operator-attribution wires into the existing
   path, not a parallel one.
3. Confirm how `can()` / `Gate` resolve the user in this codebase, so the session can
   redirect authz to the impersonated identity **without** touching `auth()->user()`.

## Scope

- An **impersonation session** primitive: enter(operator, impersonatedUser) →
  scoped block → exit, on the `runFor` pattern.
- Inside the session: authz + `SchoolScope` + team context resolve as the impersonated
  user; `auth()->user()` remains the operator.
- Every audited action inside the session attributes to the operator
  (`causedBy($operator)`), with the impersonated identity recorded as the acted-as
  subject (so the audit row answers *who drove it* and *as whom*).
- Entry/exit are themselves audited (an impersonation session is a security event).
- The surface to **start** a session is gated to super_admin's platform-admin
  `impersonation` permission (enumerated in 0045-A); it is not a general capability.

## Acceptance — the four claims, red-first, bite-proven

Each claim: write the test, watch it **fail** against the not-yet-built behaviour
(red-first proves the test discriminates), build until green, then plant a regression
and watch it go red again.

1. **Context is the impersonated user.** Inside a session, School-scoped data and
   `can()` resolve as the impersonated user — not super_admin's last context, not
   null/global. Plant: resolve without the session → different result; the session is
   provably what changes it.
2. **Attribution is the operator.** An audited action inside the session writes a row
   naming **super_admin** as causer and the impersonated user as acted-as. Plant:
   an identity-swap implementation → the row names the impersonated user → red. This
   is the claim that forbids identity swap, made mechanical.
3. **Bounded — no leak.** After the session, an unrelated request/job sees **no**
   residual impersonation context (`NoTeamLeakBetweenJobs` class). Plant: leak the
   context past exit → red.
4. **Clean exit to baseline.** Leaving restores the operator's own state; no
   half-exited state grants lingering access. Plant: exit that half-restores → red.

Plus:
- **Entry/exit audited** — starting and ending a session each produce an attributed
  audit row.
- **Start is permission-gated** — a non-super_admin (or super_admin lacking the
  `impersonation` platform permission) cannot open a session.
- **Inert while bypass on** — with `AUTH_GATE_BEFORE_SUPERADMIN` on, nothing here
  changes existing behaviour; `SuperAdminAuthorityTest` stays green in its current
  form (it is rewritten in 0045-B2, not here).
- `bin/quality` green; no baseline regressions; concurrency/leak proofs on real MySQL
  where the claim is about context bleed, not static reasoning.

## Explicitly OUT of scope

- **Seeding super_admin's platform-admin set + the self-healing seed** — 0045-B2 (A3).
- **Rewriting `SuperAdminAuthorityTest`** — 0045-B2.
- **Removing/narrowing the bypass, retiring the flag/guard/runbook line** — 0045-C,
  gated on the C2/C3 deploy being live + B2 verified in prod + super_admin's prod
  grants healed to canonical (A3 gate).
- **Break-glass artisan commands** — separate, per A4; not part of the impersonation
  feature.
- **The C2/C3 deploy and the Finance window** — unrelated scheduling.

## Sequencing note (ADR 0045 Amendment A5 — needs an answer)

Whether B1 must ship **before** 0045-C depends on an operational fact: does any real
workflow today rely on super_admin doing **domain** actions? If yes, B1 blocks C (no
capability gap). If no, C could ship first (platform-admin-only super_admin) and B1
follow. Get this answered before committing the B1↔C order; it does not block
*starting* B1 (B1 is additive and worth having either way).

## Finance coordination

Announce when B1 opens — Finance accepted 0045's attribution posture, and B1 is where
that posture becomes real (from-scratch build, not wiring). Announcement, not
re-approval; the direction is unchanged. No `app/Finance/**` access.

## Carried unverified

1. Whether `ActiveSchool::runFor` can carry an impersonated principal or needs a peer
   primitive — step 0 resolves.
2. The exact audit-causer resolution path — step 0 resolves; operator-attribution
   must wire into it, not beside it.
3. super_admin's `impersonation` platform permission name/existence — from 0045-A's
   enumeration; confirm it is in the set B2 will seed.
