# Slice C4 — `feat/rbac-inertia` — brief (drafted 2026-07-22)

Share the authenticated user's **effective** permission set to Inertia; give the
frontend `usePermissions()` + `<Can>`; migrate the one live role-name authorization
site (the students pages) to permission gating; drop the unused `rolesFull`.

This is the frontend half of the model C1–C3 built the backend for. Until now the
React side can only gate on role *names*, so it cannot express "this button is
allowed" without re-deriving authorization the backend already owns — and it gets
super_admin wrong (below).

## Decisions

### D1 — the shared set means EFFECTIVE authority, computed via `can()`. (load-bearing)

Two payloads are possible and only one matches reality:

- **Granted** — the literal `model_has_permissions`/role grants. super_admin holds
  its 15 explicit grants, so a granted payload renders super_admin as *unable* to
  do the ~45 things it actually can (via `Gate::before`). Buttons hidden that the
  backend would allow — "why can't super_admin see the button."
- **Effective** — `$user->can($ability)` for each ability the app defines. This
  runs through `Gate::before`, so super_admin's bypass shows as full authority,
  **and** ADR 0040's checker exclusion shows too: `can('result.approve')` is
  `false` for super_admin, so the approve button correctly hides for the one role
  the backend will actually deny. This is Mode A (the authority probe) resurfacing
  at the presentation layer — the UI must reflect what the gate will *do*, not
  what a grant table *says*.

**C4 ships the effective set**, computed by iterating `App\Enums\Permission` and
calling `can()` — which folds in the bypass and the exclusion by construction.
Pinned in a test: super_admin's shared set is everything **except** the checker
abilities; an ordinary role's shared set equals its granted set.

### D2 — `<Can>` gates ACTIONS; the sidebar's persona menu-selection stays role-driven.

The sidebar chooses which persona menu to render (guardian portal, boarding,
form-teacher, admin, …). That is **presentation**, not authorization, and it does
not survive an effective-`can()` migration:

- `admin`'s grants are a **superset** of `principal`/`head_of_school`, so gating
  `principalNavGroups` on `student_directory.view` would newly surface the
  principal menu to every admin — a regression.
- super_admin's effective set is *everything*, so `can()`-gating every persona
  group floods super_admin's sidebar with every persona menu it is not.

So menu-selection stays role-driven (identity presentation). The **one** place the
sidebar makes an authorization statement — "show the admin working area" — moves
to `can('admin_area.access')`, which collapses the existing
`isSuperAdmin`-vs-`roles.includes('admin')` special-case into a single
authorization check with no visibility change (super_admin and admin both hold it;
principal does not). The `/super-admin` management area stays gated on
`isSuperAdmin`: it is the one deliberately role-gated surface (`role:super_admin`,
kept by C2), with no permission behind it.

The real `<Can>` migration target is **action buttons**, where effective-vs-granted
actually bites: `students/index.tsx:48` and `students/show.tsx` gate import / bulk /
export / add / edit on `auth.roles.includes('admin')` — which hides them from
super_admin today. They move to `can('admin_area.access')`, matching the permission
the write routes actually carry (C2), and fixing the super_admin case.

### D3 — the payload is the current user's set in the ACTIVE school's team context.

Sharing permissions ships a list to the client every request. It must be the
authenticated user's effective set resolved in the **active School's team**
(spatie resolves per team), never a global or cross-school dump. This holds by
construction — `SetSchoolContext` sets the team before `HandleInertiaRequests`
runs — and is pinned by a test that grants a permission in School A only and
asserts it appears under A's context and not under B's. Exposure of one's own
permissions is benign; wrong-school scoping would be a real leak.

## Build

1. `App\Support\EffectivePermissions::for(User): list<string>` — iterate the enum,
   keep abilities `can()` returns true for. One place, testable.
2. `HandleInertiaRequests::share()` — add `permissions`; **remove `rolesFull`**.
3. `resources/js/types/auth.ts` — `permissions: string[]`; delete `rolesFull`.
4. `usePermissions()` hook + `<Can permission=…>` component.
5. Sidebar: admin working-area branch → `can('admin_area.access')`; personas stay
   role-driven; `/super-admin` stays `isSuperAdmin`.
6. students index/show: `isAdmin` → `can('admin_area.access')`.

## Bite-proofs (red-when-removed, don't inherit the assertion)

- **`rolesFull` drop:** grep proves no `.tsx` reads it; the drop is proven by tsc
  staying green with the type gone (a loose reader would not type-error, so the
  grep is the claim and the removed-and-green build is the proof).
- **Effective ≠ granted:** revert the payload to the granted set → the super_admin
  test goes red (approve-excluded still holds, but the ~45 bypass abilities vanish).
- **Exclusion surfaces:** remove ADR 0040's exclusion → super_admin's shared set
  gains `result.approve`/`reject` → red.
- **Scoping:** compute the set outside team context → the School-A permission
  leaks into School-B's payload → red.

## Out of scope (carried, declared)

- **C5/C6** (admin + super-admin RBAC modules) consume these primitives — not here.
- **Finance nav additions** compose via `<Can>` after this lands — **I7 announce**,
  not gate.
- Other pages that branch on `auth.roles` for **presentation** (result views,
  curriculum cards) are not authorization sites and are left as-is; converting
  them would be D2's mistake at page level.

## Coordination

- **I7 (app-sidebar.tsx, HandleInertiaRequests):** announce to the Finance owner —
  their nav items add via `<Can>` after this merges, so they compose rather than
  collide.
- Standing human items (unchanged by C4): production `AUTH_GATE_BEFORE_SUPERADMIN`
  (blocks the C2/C3 deploy); ADR 0040 "configured limits" design before Ph3.
