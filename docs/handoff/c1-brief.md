# Slice C1 — `feat/rbac-foundation` — brief (drafted 2026-07-21)

Open **off current `staging`**, after #71/#72 merged. Enum expansion + RbacSeeder +
Spatie role-event auditing + the pre-swap route fixture. Shares no files with A1.

> ⚠️ **This brief was drafted without repository access** — only `docs/` was
> readable. Every count, file path, class name and "N sites" figure below is marked
> either **VERIFIED (doc)** — traceable to an authoritative repo doc — or
> **RE-DERIVE** — carried from conversation or inferred, and *not yet checked
> against the tree*. Do not treat a RE-DERIVE figure as a baseline. Re-deriving
> them is step 0 of the slice, not a formality: this repo has three recorded cases
> of a carried number being wrong (`docs/testing.md`, "the three ways the tsc count
> has lied here").

## Why this exists

§24 condition 1 is closed (authz-lint baseline empty, A1). Conditions 2–4 —
`AUTHZ_ENFORCE=true` in prod, observation evidence reviewed, enforcement verified
live — are all open, and **every restored check is still observe-mode**. C1 does
not move any of them. It builds the permission substrate the later enforcement and
role-swap slices need: a permission set that is *defined and seeded* (today's gap),
role mutations that are *audited*, and a route-middleware fixture that makes the
eventual `role:` → `permission:` swap a **provable diff** rather than a trusted one.

C1 is a foundation slice. **No authorization behaviour changes in it.**

## Step 0 (first commit, before any feature work) — re-derive the baselines

`staging` has just absorbed two merges. Per-branch green ≠ merged-result green, and
C1's own gate results are meaningless measured against an unconfirmed floor.

1. `php artisan wayfinder:generate --with-form`, then `pnpm run types:check` —
   regenerate `tsc-baseline` and **diff the error set, not only the count**.
   ⚠️ `tsc-baseline` is carried as **122** (RE-DERIVE) from PR #72's description, a
   drop of ~23–26 from the last doc-recorded figures (`docs/testing.md` says 145;
   `docs/roadmap.md`'s correction block says 148). A drop that size from an
   authz-only slice is the exact shape of every prior false-green here. Confirm or
   correct it now, in its own commit, before C1 adds a single line.
2. Regenerate and diff: authz-lint (expected **empty** — VERIFIED doc),
   boundary-lint (**20** — VERIFIED doc), runtime-zero Section A (**12**) /
   Section B (**3**) — VERIFIED doc, identifier-generation (**0** — VERIFIED doc),
   test ratchet (**13** — VERIFIED doc).
3. Run `bin/quality` on staging's merged HEAD and record the output in the PR.

**If any regenerated baseline disagrees with what is committed, stop and resolve
that first.** Regenerate against merged HEAD; never hand-pick lines.

## Scope

| Area | Change |
|---|---|
| Permission enum (1.2a) | Expand to cover the ADR 0044 result/enrollment permissions + any case the seeder audit shows missing. Re-derive the true defined count first. |
| `RbacSeeder` | Single authoritative seeder for roles + permissions + role→permission mapping. Idempotent, team-aware, runnable against a populated DB. |
| Seeded-coverage test | Every enum case is seeded; every seeded permission maps to at least one role. **This is the slice's load-bearing guard** — see below. |
| Spatie role/permission events → audit | Role and permission grants/revokes write to `activity_log` (ADR 0032), attributed to the effective School. |
| Pre-swap route fixture | A test asserting the current middleware stack (incl. ordering) for every route, so the later `role:` → `permission:` swap is a reviewable diff. |

## The decisions, already made

- **D1 — the win boundary.** C1's completion report MUST lead with: _"C1 defines
  and seeds permissions and audits role mutations. It changes no authorization
  behaviour, closes no §24 condition beyond the already-closed condition 1, and
  swaps no `role:` gate to `permission:`."_ Do not bank the ADR 0044 win here; C1
  only makes the permissions the swap will use *exist*.
- **D2 — "defined" and "seeded" are two different numbers, and the gap is the
  finding.** The roadmap's verified audit: v10 claimed **32** permissions, the real
  figure was **28**, and **19 of those were never seeded at audit time** (VERIFIED
  doc). An enum case no seeder writes is wallpaper by this project's own definition.
  C1 therefore lands the seeded-coverage test *before* expanding the enum, so the
  expansion cannot re-open the gap it is closing. **Both numbers get re-derived
  against the tree — 28 is a doc figure, not a measurement you took.**
- **D3 — the `assignRole` team invariant governs the seeder; work with it, not
  around it.** `User::assignRole` throws `NullTeamRoleAssignmentException` when a
  school-scoped role is assigned with a null permissions-team (`super_admin`
  exempt), enforced at the model so no call site can bypass it, on request or off
  (VERIFIED doc; `AssignRoleTeamInvariantTest`). It has already caught
  `TeacherSeeder` and `GuardianSeeder` doing exactly this. **Expect `RbacSeeder` to
  throw on first run.** The fix is to establish team context explicitly per School
  (`UserSeeder` is the precedent that already did this) — never to exempt the
  seeder, loosen the invariant, or assign with a null team "because it's just seed
  data". A null-team role grants access to no School and is precisely the
  divergence S7 exists to remove.
- **D4 — audit target is `activity_log`, never `authz_observations`.** ADR 0043 §4
  is explicit: `authz_observations` is temporary rollout evidence, path-only, pruned
  at 30 days and dropped at teardown; the durable record is `activity_log`
  (delete-protected, ADR 0032). Role grants are a permanent security fact and belong
  in the audit log. Writing them to the observations table would both lose them at
  teardown and violate the ADR.
- **D5 — the route fixture asserts ordering, not just presence.** ADR 0043 §3 fixes
  the authorization order (auth → `SetSchoolContext` → `SchoolScope` /
  `canAccessSchool()` → route middleware → permission/policy → business rules) and
  it is already covered by `AuthorizationOrderingTest` (VERIFIED doc). The fixture
  is complementary: a full-route-table snapshot of the middleware stack, so a swap
  slice that changes one route's guard produces a one-line diff and an unintended
  change to a second route cannot ride along invisibly.

## Folded in (not optional)

- **Confirm which Spatie events the installed version actually fires** — read
  `vendor/`, do not assume from the package docs or from memory of a different
  version. **RE-DERIVE.** If the installed version does not emit a usable
  grant/revoke event, say so and propose the alternative (model-event hook on the
  pivot, or an explicit call inside `grantSchoolAccess` / `revokeSchoolAccess`)
  rather than silently landing partial coverage.
- **School attribution for off-request role writes.** `ActivitySchoolResolver` now
  reads through `ActiveSchool::id()` (VERIFIED doc, S7 Step 2). A role assignment in
  a seeder or job must attribute to the School whose team it was written in — not to
  the authenticated user, and not to null. Cover the `runFor` case; the resolver's
  existing tests are the precedent.
- **`super_admin` interaction, stated explicitly.** `auth.gate_before_superadmin` is
  on by default and verified (VERIFIED doc), and `super_admin` is exempt from the
  team invariant. ADR 0040 binds super-admin to *never* override maker-checker. The
  seeder must not grant `super_admin` its capabilities via seeded permission rows in
  a way that re-opens that override — record which mechanism grants super-admin
  authority and confirm ADR 0040 still holds after the expansion.

## Explicitly OUT of scope

- **The `role:` → `permission:` swap and the ADR 0044 implementation.** The four
  live `hasRole()` FormRequests (`RejectSubjectResultRequest`,
  `PromoteStudentRequest`, `UpdateStudentCurriculumStatusRequest`,
  `RegisterStudentCurriculumRequest`, all `admin || head_of_school`) and
  `PrincipalController`'s `abort_unless($principal->hasRole('principal'), 404)`
  remain untouched (VERIFIED doc). C1 defines the permissions that replace them;
  the swap is its own slice with its own behaviour-change rollout.
- **`AUTHZ_ENFORCE`** — not flipped, not staged, not defaulted differently.
- **`rbac.fail_closed_models`** — no model enabled. Each needs its own request-path
  audit.
- **`rbac.single_source_access`, S7, the parity soak, the column drop** — separate
  workstream thread; C1 touches none of it.
- **Debt item 7** (auth-gated fail-closed throw) — owned by the ADR 0042
  transport-agnostic slice or a scope-level backstop.
- **Any Finance permission.** Finance authorizes through Policies/Gates from its
  first commit (ADR 0043 §1). C1 must not define Finance permissions on Finance's
  behalf.

## Finance coordination — announce, do not gate

Per ADR 0043 and the workstream boundary, these are **announcements to the Finance
owner**, not sign-off gates. Send them when the branch opens, not at review:

1. **The permission enum's shape is a seam.** Finance's Ph3 approvals engine and
   ADR 0040 maker-checker will define permissions against whatever naming and
   granularity C1 establishes. Finance should see the shape before it hardens.
2. **The route fixture touches a shared surface.** `routes/` is a coordination
   point; `/api/v1/finance` routes will appear in the fixture's snapshot, so
   Finance route additions will show up as fixture diffs and someone should expect
   that rather than be surprised by it.
3. **RbacSeeder touches the roles/permissions tables**, which Finance will later
   seed into. Flag the idempotency contract so a future `FinancePermissionSeeder`
   can compose rather than collide.

**Not a coordination point:** nothing in C1 introduces a Finance dependency on
`App\Support\Authz`, and nothing in C1 changes how any Finance-read model resolves
(no `fail_closed_models` change). C1 should not land in the mid-flight Finance
deploy window, and adds no migration that assumes post-migrate `finance_*` schema.

## Acceptance — every guard bite-proven

A gate you have not watched fail on a planted regression proves nothing. For each:
plant the violation, watch it go red, remove it, watch it go green.

- **Seeded-coverage test.** Add an enum case with no seeder entry → red on the
  *coverage assertion itself*, not incidentally. Remove → green. Then the inverse:
  seed a permission mapped to no role → red.
- **Team invariant holds for the seeder.** Run `RbacSeeder` with no team context
  established → `NullTeamRoleAssignmentException`. This is a **positive** result;
  record it as evidence the invariant covers the seed path, then fix the seeder to
  establish context and re-run clean.
- **Idempotency against populated data.** `RbacSeeder` twice in a row → no
  duplicate rows, no exception. Then `bin/quality-clean-db`, which is the only gate
  that exercises the **incremental path against populated data** (VERIFIED doc) —
  `migrate:fresh` is structurally blind to it.
- **Audit write is real and attributed.** Grant a role → exactly one `activity_log`
  row, with the correct School. Do the same under `ActiveSchool::runFor()` (off
  request) → still attributed to the right School, not null, not the actor's home
  School. Bite-prove the channel itself before trusting a zero, the way the
  CASCADE-damage audit did: probe-write first, confirm the row appears, *then*
  trust an absence as an absence.
- **Route fixture bites.** Change one route's middleware → red with a diff naming
  that route. Add a route with no guard → red. (If an unguarded route is *expected*,
  it goes in the fixture as an explicit, reviewed entry — never as a silent pass.)
- **No behaviour changed.** All Authz sites remain observe-mode (**RE-DERIVE the
  count**; carried as 45 from PR #72's description, not verified against the tree).
  `AuthorizationOrderingTest` and `FortifyPostureTest` still green — ADR 0043 §5
  names both as permanent invariant tests.
- **Baselines only shrink.** No baseline rises. Any that falls is lowered in the
  same commit that earned it.
- **Full floor:** `bin/quality` green on the branch, then re-run on the **merged**
  result before promotion.

---

## Carried unverified — resolve or state plainly, do not let them go quiet

1. **`tsc-baseline` = 122.** Unverified. Step 0 resolves it. If it does not
   reproduce, that is a finding about the merged floor, not about C1.
2. **#71's Finance sign-off.** The plan doc governing the RBAC/Finance interface
   merged; whether the Finance owner signed off — and where that is recorded — was
   not established. ADR 0043's rule is that shared-surface changes are *announced
   to* the Finance owner. If it merged without that, name it as a process gap now
   and record the announcement retroactively; discovering it later, when Finance
   hits something the plan committed to without their input, is the expensive path.
3. **Every RE-DERIVE marker above.** Each is a number carried from conversation, not
   measured. Step 0 converts them to VERIFIED or corrects them.
