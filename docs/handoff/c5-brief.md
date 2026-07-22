# Slice C5 — `feat/rbac-admin-module` — brief (drafted 2026-07-22)

A school-admin **Users** page (`/setup/users`, gated `permission:rbac.manage_school_users`):
list the users in the active School and let an admin sync their roles, behind five
guards that are the actual content of the slice. This is the first **human-driven**
role write (every prior role mutation was a seeder), so it is also the first live
exercise of C1's role-mutation audit path and the null-team `assignRole` invariant.

## Step 0 — re-derived against merged staging (`2de20fc`), not carried

1. **The `<Can>` semantic is effective-via-`can()`** (C4 merged as built:
   `EffectivePermissions` filters the enum through `can()`; Inertia ships it as
   `auth.permissions`). C5's UI renders exactly what that governs, so it builds on
   it cleanly — no divergence to discover mid-slice.
2. **`rbac.manage_school_users` DOES NOT EXIST.** Grep of the merged enum and
   `RbacSeeder` finds no such case — C1 never seeded it; the plan's reference was
   wallpaper. **C5 creates it** (enum case + seeded grant). It is granted to
   `admin` and **NOT** added to super_admin's explicit list: super_admin reaches
   the page through the `Gate::before` bypass (this is not a checker ability), and
   adding it explicitly would push super_admin from 15 → 16 grants and break the
   `SuperAdminAuthorityTest` exactly-15 precondition. The `SeededPermissionCoverage`
   test will hold it to ≥1 web role.

## Decisions already made — the five guards (each bite-proven red-when-removed)

### D1 — never super_admin. A role sync targeting a super_admin is denied.

The Gate::before subtlety bites here: because super_admin passes every permission
check, the guard that protects super_admin from modification **cannot itself be a
permission the actor could hold** — it is a structural rule on the target
(`target->isSuperAdmin()` → deny), enforced regardless of the actor's grants.
Bite-proof runs with the bypass flag ON, since that is the state that would mask a
permission-shaped guard.

### D2 — `admin` is assignable only by a super_admin.

A non-super-admin actor including `admin` in a target's role set → denied. Admin
manages the ordinary school roles; elevating someone to `admin` is a super-admin
act. (super_admin, of course, is never assignable at all — that is a role the
module cannot grant to anyone; see D1's target rule and the assignable-set below.)

### D3 — no self-modification.

An actor editing its own roles → denied, target-identity rule (`actor->id ===
target->id`). Closes the admin-demotes-self-then-cannot-undo footgun by refusing
the write rather than trying to detect the specific dangerous transition.

### D4 — team-context assignment; the null-team invariant catches any path that misses it.

Every grant writes into the **active School's team** (`setPermissionsTeamId` /
`ActiveSchool`). C1's `assignRole` null-team guard must throw on any path that
assigns without a team — this is the S7 backfill-continuity mechanism
(role assignments now actively populate `model_has_roles`), so it is load-bearing
beyond C5. Pinned: a grant lands in the active team and is absent from another
School's team for the same user.

### D5 — `permission:rbac.manage_school_users` gates the page and the write.

Both the route (middleware) and the write (FormRequest `authorize()`). This is the
first slice to **consume** the permission created in Step 0, so it is the first
real test that the permission is seeded, not merely referenced.

## Two non-obvious scope rules

- **The user list is scoped to the active team**, not a global user dump — the same
  active-School discipline as C4's payload. Read users via the School's membership
  (the `school_user` pivot / `accessibleSchools` inverse), never `User::all()`.
- **One correctly-attributed audit row per sync.** Role sync is an audited write;
  C1 wired role-mutation events to `activity_log`. Confirm a grant through this UI
  produces exactly one row, attributed to the acting admin (causer), in the active
  School — this is the first human-driven role write, so the attribution path
  (not a seeder's `withoutLogs`) runs for real here for the first time.

## The assignable-role set (derived, not free-form)

The module may sync only the **ordinary school roles** a school-admin owns:
`principal, head_of_school, teacher, guardian, boarding_parent, form_teacher,
registrar`. `admin` is assignable **only** when the actor is super_admin (D2).
`super_admin` is never in the assignable set (D1). Any role name outside the
allowed set for the current actor → validation failure, so the guards hold even if
the client posts an arbitrary payload.

## Verification

- Guard-by-guard bite-proofs (D1–D5): remove each guard → its test goes red,
  naming the specific denial.
- The audit row: exactly one, correct causer + active School.
- Floor `bin/quality` 10/10; full suite + ratchet clean; drive the page in the
  running app (super_admin sees it via bypass; admin via grant; a plain teacher
  404/403s).

## Out of scope (carried, declared)

- **C6** (super-admin site-wide role×permission matrix editor) is the next slice —
  C5 is per-School role *assignment*, not permission *definition*.
- **C7** (2FA per-role) unaffected.
- No new human items. None of the three carried items (prod
  `AUTH_GATE_BEFORE_SUPERADMIN`, ADR 0040 limits, #86 policy-timing) block C5.

## Coordination

- New route `GET /setup/users` + its write endpoint, a sidebar item under the admin
  group, and the new `rbac.manage_school_users` permission. No Finance surface
  touched; no ⚑ beyond the standing sidebar note from C4 (Finance composes nav via
  `<Can>`).
