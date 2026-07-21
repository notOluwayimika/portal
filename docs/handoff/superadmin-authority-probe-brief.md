# Probe — how `super_admin` authority actually resolves — brief (drafted 2026-07-21)

`chore/superadmin-authority-probe`. Read-only investigation + one standing invariant
test. **No authorization behaviour changes.** Opens off `staging` after #73; blocks
nothing, but its result is an input to both C2 and C3, and it is cheap enough that
discovering the answer mid-swap would be the expensive path.

> ⚠️ **Drafted without repository access** — only `docs/` was readable. Figures and
> mechanisms below are tagged **VERIFIED (doc)** (traceable to an authoritative repo
> doc) or **RE-DERIVE / VERIFY** (carried from conversation, or framework behaviour
> that is version-dependent and must be read out of `vendor/`, never assumed). The
> point of this slice is to convert the second category into the first.

## Why this exists

C1 seeded `super_admin` **none** of ADR 0044's seven permissions, per ADR 0040
("super admin never overrides maker-checker") — RE-DERIVE, from the #73 report.
That is the right intent. But the guarantee it makes is an **absence**, and an
absence only holds if nothing else grants the ability by another route.

`auth.gate_before_superadmin` is on by default and verified (VERIFIED doc), and a
`Gate::before` callback that returns non-null short-circuits every ability check
routed through the Gate — policies included. So there is a real possibility that
the seeded absence decides nothing.

The inverse is equally possible and lands sooner. **Both are settled by the same
probe**, which is why this is one slice and not two.

## The two failure modes

| | Mode A — over-permission | Mode B — lockout |
|---|---|---|
| **Mechanism** | `Gate::before` returns `true` for `super_admin` on every ability, so the unseeded maker-checker permissions resolve `true` anyway | Spatie's `permission:` middleware resolves permissions **without** the Gate (`hasPermissionTo` / `hasAnyPermission`), so `Gate::before` never runs and `super_admin`'s empty grant set is decisive |
| **Consequence** | ADR 0040's non-override is violated at the gate, not the seed. C1's "super_admin gets none" is cosmetic | C2's swap of 28 `role:` groups → `permission:` locks `super_admin` out of 27 of them (`/super-admin` stays `role:super_admin` — VERIFIED doc, plan C2) |
| **Bites at** | C3 (ADR 0044 implementation) | **C2 — the nearer risk** |
| **Detected by** | rows 1 + 3 + 4 of the matrix | row 2 of the matrix |

Both can be true at once for different call paths — that is the outcome to watch
for, and the reason the matrix is per-path rather than a single yes/no.

## Scope

1. The resolution matrix below, run and recorded.
2. A `vendor/`-level read of the three mechanisms named under "must be read, not
   assumed."
3. One standing invariant test encoding whatever is found.
4. A short findings note appended to the roadmap (or its own ADR amendment
   proposal, if the result warrants — see "what this does not settle").

Nothing else. No seeder change, no `Gate::before` change, no middleware change, no
flag flip. If the probe finds a defect, the **fix is a separate reviewed slice** —
this one only establishes what is true.

## Method — predict first, then run

Write the expected matrix down **before executing it**, in the PR description.
A divergence between prediction and result is the finding; a test authored to match
already-observed behaviour records a fact but proves no understanding, and this repo
has been bitten specifically by tests that asserted whatever happened to be true
(`docs/roadmap.md`: the GuardianProfile fixture whose setup silently no-op'd; the
#73 `super_admin: []` guard collision).

| # | Decision path | ADR 0044 ability (unseeded for super_admin) | Ordinary ability (also unseeded) |
|---|---|---|---|
| 1 | `$user->can()` called directly | predict → observe | predict → observe |
| 2 | Spatie `permission:` route middleware | predict → observe | predict → observe |
| 3 | Policy method via `Gate::authorize` / `$this->authorize()` | predict → observe | predict → observe |
| 4 | FormRequest `authorize()` | predict → observe | predict → observe |

Run the full matrix **twice** — `auth.gate_before_superadmin` on and off. The flag's
existence means the answer is environment-dependent, so:

- **Also establish which state production is actually in.** That is an environment
  fact the tree cannot prove (the same class as the scheduler-execution gap recorded
  in `docs/roadmap.md`). Do not infer it from the config default.

Include a non-super-admin control row for at least one cell. A matrix where every
cell says "allowed" proves nothing unless something in it is capable of saying
"denied" — bite-prove the instrument before trusting its output, the way the
CASCADE-damage audit probe-wrote before trusting a zero.

## Must be read out of `vendor/`, not assumed — all three are version-dependent

1. **Spatie's `PermissionMiddleware` call path.** Some released versions call
   `$user->can()` (→ Gate → `before` applies); others call `hasAnyPermission()` /
   `hasPermissionTo()` (→ direct role lookup, `before` does **not** apply). This
   single fact decides Mode B. **VERIFY.**
2. **Whether Spatie registers its own `Gate::before` callback.** Recent versions
   resolve permissions through a `before` callback of their own. If both that and
   the app's `super_admin` bypass are registered, **the first to return non-null
   wins, and registration order falls out of service-provider boot sequence** —
   ambient and fragile. If this is the case, say so explicitly; it is a finding in
   its own right regardless of which mode it produces. **VERIFY.**
3. **The app's own `Gate::before` callback (1.2b).** What exactly it returns, for
   which guards, and whether it distinguishes abilities at all. **VERIFY.**

Framework semantics that are stable and may be stated (still worth confirming once
against the installed version): a non-null `Gate::before` return short-circuits the
check and skips policies entirely; `$user->can()` on the `Authorizable` trait routes
through the Gate; Spatie's `hasPermissionTo()` does not.

## Decision gates — what each outcome obliges

- **Mode B confirmed (middleware bypasses the Gate):** C2 cannot open without a
  declared decision — either seed `super_admin` the full permission set, or leave
  those groups `role:`-gated with the deviation recorded. Both are defensible; what
  is not defensible is discovering it across 28 groups mid-swap. This becomes a
  named entry in C2's brief.
- **Mode A confirmed (bypass reaches maker-checker abilities):** ADR 0040 is not
  currently enforced by the mechanism C1 used. Raise it at ADR level (below) rather
  than patching a denylist into `Gate::before` inside C3.
- **Neither (bypass excludes the seven, middleware routes through the Gate):** the
  current design is sound — encode it as the standing test so it stays sound, and
  record what it passes *because of*, so the next person does not have to re-derive
  it.
- **Mixed by path:** the most likely and most important result. Record per-path;
  do not summarise it to a single verdict.

## Explicitly OUT of scope

- **Any fix.** The probe establishes truth; remediation is a reviewed slice.
- **The SoD architectural question** (below) — an ADR-shaped decision, not an
  implementation detail to fold in here.
- **C2's swap, C3's ADR 0044 implementation, A2's tooling** — all unaffected by this
  slice landing; it is additive.
- **`AUTHZ_ENFORCE`, `rbac.fail_closed_models`, S7** — untouched.
- **Finance.** No shared surface, no announcement owed. (If the probe finds Mode B,
  the *C2 consequence* is a ⚑ I1 coordination point — but that belongs to C2's
  announcement, not this one.)

## Acceptance

- The matrix is recorded with **prediction and observation side by side**, both
  flag states, and production's actual flag state established separately.
- All three `vendor/` mechanisms are read and stated, with the installed version
  numbers quoted.
- A standing invariant test — proposed name `SuperAdminAuthorityTest` — encodes the
  result, sitting alongside `AuthorizationOrderingTest` and `FortifyPostureTest`,
  both of which ADR 0043 §5 explicitly **keeps** through the `Authz` teardown. This
  test must survive teardown too: it asserts a permanent property of the
  authorization model, not a rollout state.
- **Bite-proven:** grant `super_admin` one of ADR 0044's seven → the test goes red
  *naming that ability*; revoke → green. And the instrument-level proof: at least
  one matrix cell must be capable of producing "denied", demonstrated.
- **The corrected `super_admin` fixture row is re-verified against a live check,
  not against the regenerated fixture.** It is the exact row that was wrong for
  months (#73 F1–F3, the web/api guard-keying collision that recorded
  `super_admin: []` over 16 real grants — RE-DERIVE), and it is exactly the row
  C2's `RouteAccessParityTest` will assert against. A fixture regenerated by the
  same code that mis-keyed it is not independent evidence.
- `bin/quality` green; no baseline moves (this slice adds tests only).

## What this probe does NOT settle — the ADR-shaped question behind it

Whatever the matrix says, **ADR 0040's guarantee should probably not rest on
`super_admin` lacking a grant.** A guarantee made of an absence is defeated by any
bypass — `Gate::before` today, an impersonation feature tomorrow, a future
super-role, a `Gate::after` someone adds in two years. Each would re-open it
silently.

Segregation of duties is arguably not an authorization fact at all. *"May this user
approve?"* is authorization. *"May this record be approved by the same person who
submitted it?"* is a constraint on the **transition** — which is precisely the
category ADR 0043 §4 already carves out ("business-rule checks are state validation,
not authorization... never gated by `AUTHZ_ENFORCE`"). Modelled as authorization,
every authorization bypass defeats it. Modelled as a domain invariant on the
approval transition (`approver_id <> submitter_id`, enforced where the transition
happens and ideally backed at the DB, as F3/F4/F6/F7 are), **no authorization
mechanism can bypass it, because none is consulted** — and ADR 0040's "never"
becomes an unrepresentable state rather than an asserted one. `super_admin`'s empty
grant set then becomes defense-in-depth instead of the guarantee.

**That is an ADR amendment, not a probe finding.** Raise it separately; this brief
only records why the probe's result should not be treated as closing it either way.

## Carried unverified

1. **C1's seeding claim** — "super_admin gets none of the seven" is from the #73
   report, not measured here. The probe re-derives it as its own precondition.
2. **The 16 collided grants / F1–F3** — same source. Re-verify as part of the
   fixture check above.
3. **Production's `auth.gate_before_superadmin` state** — an environment fact,
   unknowable from the tree. Establish it explicitly; do not infer from the default.
