# Slice C7 — `feat/rbac-2fa` — brief (drafted 2026-07-21)

The last Track C slice. `roles.two_factor_required` migration + toggle,
`EnsureTwoFactorEnrolled` middleware, enrolment enforcement. Opens off `staging`
after C6 (#91) merged.

> ⚠️ **Drafted with only `docs/` readable** — the app tree was not accessible.
> Every count, class name, middleware slot and "N roles" figure is **VERIFIED
> (doc)** (traceable to an authoritative repo doc) or **RE-DERIVE / VERIFY**
> (carried from the plan or conversation, or framework/vendor behaviour that must be
> read out of the tree, never assumed). Step 0 converts the second category.
>
> ⚠️ **This slice's merge is gated on a human, not on the floor.** See "Finance
> coordination" — a 10/10 floor does **not** clear it to merge.

## Why this exists

Track C's plan row: `roles.two_factor_required` migration; default **true** for
`super_admin`, `admin`, and the 4 Finance roles (⚑); `EnsureTwoFactorEnrolled`
middleware; web redirect / API 403 `TWO_FACTOR_REQUIRED`; exempt
security-settings / logout / select-school (VERIFIED doc, plan C7). C6 correctly
deferred the toggle here, next to its column — a toggle ahead of its column is
wallpaper.

The point of the slice: a role can be **declared** to require 2FA, and a user
holding such a role **cannot proceed** on protected surfaces until enrolled. Today
`roles` has no such column and no such middleware (RE-DERIVE — confirm at step 0).

## Step 0 — two re-derivations before any code

1. **Do the 4 Finance roles actually exist in the seeder?** C7 sets their 2FA
   default; if C1 held them back pending I6 sign-off (the open #73 question, never
   cleanly closed — RE-DERIVE), then the Finance-role half of C7 references rows
   that do not exist and is **Finance-gated**, not yours. Grep the seeder; list
   which of the 13 planned roles are actually seeded. Build the academic-role half
   regardless; hold the Finance-role defaults for the announce.
2. **Re-derive the middleware stack and the `AuthorizationOrderingTest`
   expectations** against merged HEAD, so D3's bite-proof is measured against the
   real order, not the plan's description of it.

## Scope

| Piece | Change |
|---|---|
| Migration | `roles.two_factor_required` boolean, default false; set true for the roles D1/step-0 resolve |
| Toggle | The per-role `two_factor_required` control C6 deferred, wired to the column, activity-logged like every other role mutation (C1 path) |
| `EnsureTwoFactorEnrolled` | Middleware: a user holding any 2FA-required role that has not enrolled is redirected (web) / 403 `TWO_FACTOR_REQUIRED` (API) |
| Exemptions | security-settings, logout, select-school, **and the enrolment route itself** — a deadlock check, not a list (D2) |

## The decisions

- **D1 — 2FA-required is GLOBAL-to-the-user, not scoped-to-active-School.**
  The plan's "runs after `SetSchoolContext` because it reads roles, needs team
  context" silently encodes the *contextual* reading: a user who is `admin` in
  School A and `teacher` in School B would be forced to enrol only while acting in
  School A. Contextual is defensible — per-request gating means she can never
  actually *use* the School-A admin power without enrolling. But **global** (holds a
  2FA-required role in **any** team → must enrol) is chosen, for three reasons:
    - **Defense-in-depth:** the sensitive capability exists on the account
      regardless of which context is active; enrolment should not depend on which
      School the user happens to be in.
    - **UX:** enrol at login, not bounced mid-workflow on a School switch.
    - **Robustness of the ordering (the load-bearing one):** a global requirement
      reads the user's role assignments **team-agnostically** (`model_has_roles`
      without team scope), so its correctness **does not depend on
      `SetSchoolContext` having run**. Keep the middleware in its planned slot after
      `SetSchoolContext` (harmless — context is set and ignored for the requirement
      read), but the ordering is no longer *load-bearing*: a future middleware
      reorder cannot silently disable 2FA. Under the contextual reading the ordering
      **is** load-bearing, which is a standing fragility this avoids.
  Encode the global semantics in a test: a multi-team user with a 2FA-required role
  in only one team is required to enrol in **every** context, including one where
  they hold only exempt roles.
- **D2 — exemptions are proven as a NON-deadlock, not asserted as a list.** An
  unenrolled user redirected to the enrolment route must be able to *reach* it, and
  must be able to *log out* (C2 already found `POST /logout` broken once — VERIFIED
  doc — so this is not hypothetical). Bite-proof the full loop: unenrolled
  2FA-required user hits a protected route → redirected to enrolment → can load the
  enrolment page → enrols → reaches the original route; and separately, can log out
  while unenrolled. A missing self-exemption is a lockout, the same failure class as
  C5's non-atomic `syncRoles`.
- **D3 — `AuthorizationOrderingTest` stays green, bite-proven.** ADR 0043 §3 fixes
  the order (auth → `SetSchoolContext` → isolation → route middleware →
  permission/policy → business rules — VERIFIED doc). C7 inserts into that chain;
  prove the middleware slots where D1 says, and that moving it red-fails the
  ordering test. (Per D1 the *behaviour* survives a reorder, but the *fixed order*
  is still an invariant the test guards.)
- **D4 — the requirement read does not route through the Gate.** It reads role
  membership directly, so `Gate::before`/super_admin bypass does not apply (unlike
  every prior authorization decision in this track). super_admin is 2FA-required by
  a **seeded default**, not by a permission check — confirm super_admin actually
  gets redirected when unenrolled, precisely because the bypass that governs its
  *authorization* is irrelevant to its *enrolment* requirement. This is the one
  place in the track where super_admin is constrained by a mechanism the bypass
  cannot reach — worth an explicit test.

## Folded in (not optional)

- **Factories set `two_factor_confirmed_at`** (VERIFIED doc, plan C7), which is why
  the suite stays green while real users break. That is a **coverage hole, not a
  pass**: add at least one test with an *unenrolled* 2FA-required user proving the
  redirect/403 fires. A green suite whose factories all pre-enrol proves nothing
  about the enforcement C7 exists to add.
- **API vs web branch.** Web redirects; API returns 403 `TWO_FACTOR_REQUIRED`.
  Prove both — an API client getting an HTML redirect instead of the JSON code is a
  broken contract for Finance's API consumers.

## Amendment (2026-07-21) — platform enable flag, per-env default, NOT a prod check

Decided after the brief was first written; it replaces any hard
`environment('production')` gate.

- **D5 — enforcement is gated by a config flag, not an environment check.** A
  platform 2FA-enforcement flag (e.g. `rbac.two_factor_enforced`) governs whether
  `EnsureTwoFactorEnrolled` enforces at all. Its **default is set per environment —
  on in production, off in staging/dev** — so developers (Finance included) get no
  2FA friction locally, **but the enforcement path stays one code path a test can
  exercise and staging can soak.** A hard `app()->environment('production')` branch
  is explicitly rejected: it would make the enforcement path run *only* in prod,
  unverifiable anywhere prod-like — the "nothing in the gate renders a page /
  production-only behaviour" failure class (roadmap Invariant 10; `testing.md`
  blank-login incident). The flag also satisfies the "disable platform-wide if
  necessary" requirement — one lever, both jobs.
- **D6 — precedence is explicit: platform flag is the master switch above the
  per-role toggle.** `rbac.two_factor_enforced = off` ⇒ nobody is checked,
  regardless of any role's `two_factor_required`. Test both levels: master-off with
  a required role → not enforced; master-on + required role + unenrolled → enforced.
- **D7 — flipping the flag is audited.** Disabling platform 2FA is disabling a
  security control; the change writes to `activity_log` (the C1 mutation path) —
  who disabled it, when. A silent 2FA-disable is precisely what must be detectable
  after the fact.
- **Environment gate (Invariant 10):** the enforcement path must be **soaked in
  staging with the flag ON** before production relies on it — registration/tests
  are not runtime evidence. This closes the hole a prod-only check would have left
  permanently open.

## Explicitly OUT of scope

- **Enabling `AUTHZ_ENFORCE`, `rbac.fail_closed_models`, S7** — untouched.
- **The `Authz` teardown (A6)** — separate, behind the parked A-track.
- **Any Finance-role 2FA default whose role is not yet seeded** — held for the
  announce, not invented here (step 0).

## Finance coordination — this one is a MERGE GATE, not just an announce

Unlike C4–C6 ("announce, don't block"), C7's ⚑ is pre-merge blocking:

1. **Merging C7 breaks every unenrolled admin login on staging**, including
   Finance-dev/test admin users, and the broken state is **invisible to your
   floor** because factories pre-enrol. Sequence: build → announce → Finance
   confirms their dev/test admins are enrolled or exempt → **then** merge. Do not
   let 10/10 authorize the merge on your own clock.
2. **The 4 Finance roles' 2FA default is I6 territory** — Finance owns the role
   semantics; RBAC seeds. Their default-true is the Finance owner's call, bundled
   into the same conversation as the ADR 0040 "configured limits" and #86 timing
   questions that are already standing.
3. **`app-sidebar.tsx` / security-settings surfaces** touched for the enrolment
   flow are shared (I7) — announce any nav/route change.

Not a coordination point: the migration adds a column to `roles` (RBAC-owned), no
`finance_*` table, no assumption of post-migrate Finance schema.

## Acceptance — every guard bite-proven

- **Enforcement fires on the real gap:** unenrolled 2FA-required user → redirect
  (web) / 403 `TWO_FACTOR_REQUIRED` (API). Remove the middleware → the test goes
  red on the enforcement assertion itself.
- **Global semantics (D1):** multi-team user, 2FA-required role in one team only →
  required to enrol in every context. Flip the implementation to contextual → this
  test goes red.
- **No deadlock (D2):** the full enrol-loop + logout-while-unenrolled loop passes;
  remove the enrolment-route self-exemption → lockout, test red.
- **Ordering (D3):** `AuthorizationOrderingTest` green; move the middleware → red.
- **super_admin constrained by enrolment despite the bypass (D4):** unenrolled
  super_admin → redirected; bite-proven with the flag ON.
- **Unenrolled-user coverage exists** (not all factories pre-enrol).
- **`bin/quality` green; baselines only shrink.** Then re-run on the **merged**
  result before promotion — and remember the merge itself waits on Finance (above).

## Carried unverified

1. **Whether the 4 Finance roles are seeded** — step 0 resolves; determines how much
   of C7 is yours vs Finance-gated.
2. **`roles` has no `two_factor_required` column today** — RE-DERIVE at step 0.
3. **The standing human items** (prod `AUTH_GATE_BEFORE_SUPERADMIN`, ADR 0040
   limits, #86 timing) — C7 does not depend on the first, and folds the Finance-role
   default into the same conversation as the other two. None is closed by this
   slice.
