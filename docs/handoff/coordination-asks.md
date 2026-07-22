# Two coordination asks — RBAC workstream (2026-07-21)

Two messages to send. They gate the RBAC stream's remaining critical path (§24
closure runs through the Finance deploy → observe evidence → enforce → teardown),
so answering them shortens the path more than any further RBAC build does.

If the deploy owner and the Finance owner are the same person, merge the two into
one message under their headings.

---

## Ask 1 — RESOLVED 2026-07-22 (a code fact, not a question anyone owed us)

`config/auth.php` defaults `gate_before_superadmin` to **true** when the env var
is absent — so an unset prod var means the bypass is ON at deploy: no lockout.
Locked down two ways: an explicit `AUTH_GATE_BEFORE_SUPERADMIN=true` line in the
phase1-deploy runbook (intent visible, not implicit), and a guard test pinning
the config default true until ADR 0045 retires the flag. The C2/C3 deploy is
now gated only by the Finance deploy-window freeze (§4.5) — a scheduling
question, not a decision. Original ask kept below for the record.

## ~~Ask 1~~ — to whoever owns production environment config / the deploy

**Subject: What is `AUTH_GATE_BEFORE_SUPERADMIN` set to in production right now?**

One question, and it blocks the RBAC deploy.

C2 and C3 are merged to `staging` and undeployed. They swapped 27 of 28 route
groups from `role:`-gated to `permission:`-gated. After that swap, `super_admin`'s
access to those groups depends entirely on the `Gate::before` super-admin bypass —
which the `AUTH_GATE_BEFORE_SUPERADMIN` flag controls.

- **Flag ON** (the assumed state): `super_admin` passes everything, deploy is safe.
- **Flag OFF**: `super_admin` holds only its 15 explicit permissions and is **locked
  out of 27 of 28 route groups** the moment the swap deploys.

So before the RBAC stack deploys I need the **current production value**. If it's
off, that's not a blocker we route around — it means the flag flips on as part of
the deploy, or C2 is adjusted first. Either way I can't safely schedule the deploy
without knowing.

**What I need back:** the current prod value of `AUTH_GATE_BEFORE_SUPERADMIN`. If
off, a two-minute conversation about sequencing before deploy.

_(Related, if you also own the Finance deploy: the RBAC stack rides in that deploy's
window, and its evidence clock for enforcement can't start until the deploy is
declared complete. A heads-up when that lands would unblock the next RBAC phase.)_

---

## Ask 2 — to the Finance owner

**Subject: Four RBAC↔Finance items, one of which gates a merge**

RBAC's Track C is built. Four things now need your call — bundled so it's one
conversation, not four. Only #4 is time-sensitive against a merge; #1 is
time-sensitive against your own Phase-3 work.

**1. ADR 0040 "configured limits" — shape it before Ph3 inherits a default.**
C3 implemented maker≠checker separation for the result workflow (structural
`decided_by ≠ initiator_id`, at Policy and DB). ADR 0040 also references approval
**limits**, which C3 did *not* build — it's out of the result workflow's scope. When
Finance's Ph3 approvals engine lands, it will inherit the result workflow's
separation model **by default** unless you want a different limits shape. The
accounting policy already contemplates a per-School configurable **approver**, so
limits may attach there as config rather than as new RBAC surface. The window to
decide this cheaply is now, while Finance is still pre-Ph3 — once the approvals
engine is built around one shape, changing it is rework. **Do you want to shape the
limits model now, or accept the result-workflow separation as the Ph3 default?**

**2. #86 policy-timing — a factual question, no action implied.**
`Money::percentage/allocate` rounding shipped in #86, and ADR 0002 gated that
rounding on a **signed** accounting policy. `docs/finance/accounting-policy.md` was
updated in the same wave. **Was the policy signed *before* the rounding was written,
or reconciled to match it *after*?** If before, the gate worked as intended and
this is closed. If after, the gate didn't actually gate — not a fire, but worth
knowing so ADR 0002's intent isn't quietly hollow for the next money operation.

**3. Finance-role 2FA defaults — yours to set when the roles land.**
The 4 Finance roles aren't seeded yet (I6). C7 built the `roles.two_factor_required`
column and the enrolment mechanism, and set the default true for `super_admin` and
`admin`. When Finance seeds its 4 roles (Finance's slice, Finance's call on the role
semantics), their `two_factor_required` default rides in the same seeder row —
presumably **true** for finance roles, but that's your decision. **Confirming you
own that default**, so it isn't silently forgotten and the roles don't land
2FA-optional by omission.

**4. #92 merge confirmation — this one gates the merge.**
C7 (2FA enrolment) is built and up as #92, deliberately **not merged on my clock**.
Merging it requires 2FA enrolment for `admin` and `super_admin` on protected
routes — which means any unenrolled Finance developer acting as `admin` on `staging`
gets redirected to enrol. The test suite stays green regardless (fixtures
pre-enrol), so this breakage is invisible to the RBAC floor — I need you in the
loop before it lands. **Can you confirm your dev/test admin users are enrolled, or
that the enrolment flow's exemptions cover them, before #92 merges?**

**Three FYIs, no action needed** (I7 shared-surface announcements):
- `app-sidebar.tsx` and `HandleInertiaRequests` are now permission-gated (`<Can>`)
  surfaces — Finance nav items added via `<Can>` after C4 will compose cleanly.
- A route-access oracle now pins all 299 routes' middleware. Finance route
  *additions* (in `routes/endpoints/finance.php`) will show up as fixture diffs but
  are **not** blocked by design — just regenerate the fixture when you add them.
- `finance.access` was seeded as an **interim** permission in C2, to be replaced by
  the real Finance permission set when I6 lands. Flagging so it isn't mistaken for
  the final grant.

---

**Why both are worth sending today, not at the next sync:** everything RBAC can
build without them is lower-value (fail-closed waves on low-fan-in models) or
blocked. These two answers are what let the stream reach production and close §24 —
the reason the workstream exists. The pile of undeployed, merged slices only grows
until Ask 1 is answered.
