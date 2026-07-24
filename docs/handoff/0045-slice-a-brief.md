# Slice 0045-A — super_admin de-bypass: investigation + impersonation-correctness — brief (drafted 2026-07-22)

`chore/superadmin-debypass-investigation`. The first slice of ADR 0045 (Accepted).
**Investigation only — no behaviour change, no bypass touched.** Opens off
`staging` after Track C completed (#97 merged).

> ⚠️ **Drafted with only `docs/` readable.** Every class name, path, and "N sites"
> figure is **VERIFIED (doc)** or **RE-DERIVE** (must be read out of the tree). The
> whole point of this slice is to convert the second category into the first —
> especially the impersonation claims, which are the load-bearing precondition of
> the entire ADR.

## Why this slice exists, and why it's investigation-first

ADR 0045 removes super_admin's ambient `Gate::before` bypass and routes all
domain action through impersonation. That terminal step is one-way-ish and rests
on two things being **true**, not assumed:

1. impersonation is correct (sets School context, attributes to the operator,
   bounds the session, exits clean), and
2. super_admin's legitimate platform-admin work is fully enumerated, so removing
   the bypass strands nothing.

The roadmap records impersonation already caused a production defect —
"impersonation masking scope" (Invariant 3). Making it the **sole** path to
super-admin domain action multiplies its criticality. So the correct first move is
to **attack impersonation and enumerate the surface**, not to write the removal.
Nothing here changes behaviour; it produces the facts the additive slice (0045-B)
and the subtractive slice (0045-C) depend on.

## The whole track, so sequencing is explicit

- **0045-A (this slice) — investigation.** Break-glass decision · impersonation
  correctness re-derived + bite-proven · platform-admin ability enumeration. No
  code behaviour change.
- **0045-B — additive foundation.** Seed super_admin's explicit platform-admin
  permission set · make ADR 0040's operator-attribution signal real · rewrite
  `SuperAdminAuthorityTest` to the new invariant. **All inert while the bypass is
  on** (the bypass makes super_admin pass regardless), so it is additive,
  reversible, and deployable *ahead of* the removal.
- **0045-C — subtractive, GATED.** Narrow `Gate::before` to the platform-admin
  set, remove the bypass, remove `AUTH_GATE_BEFORE_SUPERADMIN` + its guard test +
  the deploy-runbook line. **Gate: the C2/C3 deploy is live and stable** (it
  depends on the bypass being on — the permission-based path must replace it in
  prod first) **AND** 0045-B is verified in production. Expand/contract: never
  remove the bypass before its replacement is proven live.

This slice is A. Do not write B or C code here.

## Scope of 0045-A

1. **The break-glass decision** (settle first — it shapes the permission set).
2. **Impersonation-correctness re-derivation**, each claim bite-proven.
3. **Enumeration** of super_admin's legitimate platform-admin abilities.

## The one decision to settle first — break-glass

ADR 0045 left this open, and it gates enumeration: **is there any platform
operation impersonation cannot express?** Bulk data repair mid-incident, a
cross-School migration, a platform action that maps to no single user's authority.

- If **no** — impersonation covers everything, and super_admin's explicit set is
  purely platform-admin (school lifecycle, cross-School user management,
  impersonation itself).
- If **yes** — that operation needs a **named, narrowly-scoped, loudly-audited
  direct path**, NOT a reinstated general bypass. Name it, scope it, and it enters
  the permission set as an explicit, reviewed exception.

This is a decision, not an investigation output — surface the candidates from the
codebase (what does super_admin do today that is not a single user's action?), then
it needs a human ruling before 0045-B seeds anything.

## Impersonation correctness — the load-bearing audit (bite-prove every claim)

Re-derive from the tree, not from the ADR's assumptions. For each, plant the
failure and watch it, don't assert:

1. **School context is set correctly.** Entering an impersonation session sets
   `ActiveSchool` / permissions-team to the *impersonated user's* context — this is
   exactly what the "masking scope" defect got wrong. Prove: an impersonated action
   resolves School data as the impersonated user, not as super_admin's last context
   or a null/global scope.
2. **Every action attributes to the operator.** An action inside the session writes
   an audit row naming the **super_admin** behind the impersonated identity, not
   only the impersonated user. (This is the ADR 0040 signal 0045-B must make real —
   0045-A establishes whether it exists at all today.)
3. **The session is bounded — no leakage.** Context does not survive into the next
   request/job (the `NoTeamLeakBetweenJobs` class of failure). Prove: after a
   session, an unrelated request/job sees no residual impersonation context.
4. **Exit returns super_admin to baseline.** Leaving the session restores
   super_admin's own (soon: no-ambient-authority) state; no half-exited state grants
   lingering access.

**Output:** either "impersonation is correct on all four, bite-proven" — or a
defect list. Any defect is its own fix slice **before** 0045-C, because the ADR's
"detection, not prevention" posture is only real if attribution (claim 2) actually
works, and safe only if context/leakage (claims 1, 3, 4) are sound.

## Enumeration — what super_admin legitimately does as itself

Grep-verified inventory (targeted, not read-through — the slice-(ii) lesson):
every current super_admin capability, classified **platform-admin** (stays, becomes
an explicit permission) vs **domain** (goes, reachable only via impersonation).
Cross-reference the probe's "exactly 15" set and the `/super-admin` module surface.
Output feeds 0045-B's seeder. Flag anything that is neither cleanly platform nor
cleanly domain — those are the break-glass candidates above.

## Explicitly OUT of scope

- **Removing or narrowing the bypass** — 0045-C, gated on the C2/C3 deploy.
- **Seeding the permission set / attribution code / invariant rewrite** — 0045-B
  (additive). This slice produces the *inputs* to those, not the code.
- **`AUTH_GATE_BEFORE_SUPERADMIN`, its guard test, the runbook line** — all stay
  exactly as pinned; they are retired in 0045-C, not before.
- **The C2/C3 deploy itself** — separate, waits on the Finance window.

## Finance coordination

None owed for 0045-A — it's read-only investigation touching no shared surface.
0045-B/C will re-touch the SoD posture Finance reviewed and accepted; the
attribution work (0045-B) is the precondition Finance's acceptance was contingent
on, so keep them in the loop when B opens. No `app/Finance/**` access at any point.

## Acceptance

- The break-glass decision is recorded with a human ruling (or explicitly parked as
  the one open item, with candidates listed).
- All four impersonation claims are **bite-proven** (planted failure watched red,
  fixed/absent watched green) — or a defect list is produced, each defect with its
  own follow-up slice named.
- The platform-admin vs domain enumeration is complete, targeted-grep-verified, and
  cross-checked against the probe's 15 and the `/super-admin` surface.
- No behaviour changed; `bin/quality` green; no baseline moves (investigation +
  tests only). `SuperAdminAuthorityTest` still green in its **current** form (it is
  rewritten in 0045-B, not here).

## Carried unverified

1. **Impersonation's current correctness** — the entire premise; 0045-A exists to
   settle it. Assume nothing from the ADR.
2. **Whether ADR 0040's attribution signal exists at all today** — claim 2 above
   establishes it; 0045-B makes it real if not.
3. **super_admin's true current ability set** — "exactly 15" is a probe figure;
   re-derive against the tree as part of enumeration.
