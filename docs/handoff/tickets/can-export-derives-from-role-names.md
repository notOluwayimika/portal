# The activity-log capabilities object derives from role names, not from permissions

**Raised:** 2026-09-01 · **From:** the client-side sweep following `fix/three-authorisation-holes` · **Severity:** ticket

## What

`resources/js/pages/admin/activity-logs/index.tsx:40-46` — **both** capabilities in the object, not
just one:

```ts
const capabilities: ActivityCapabilities = {
    canViewSystem: roles.includes('super_admin'),
    canExport:
        roles.includes('admin') ||
        roles.includes('head_of_school') ||
        roles.includes('super_admin'),
};
```

**THE RULE, STATED ONCE FOR THE WHOLE OBJECT: no capability in `ActivityCapabilities` derives from a
role name.** Stating it per-field is how the second field was missed in the first version of this
ticket, and how a third would be missed when it is added. The object is the unit, because the object
is what the next person copies a line within.

`canViewSystem` gates the include-system control at `activity-filter-bar.tsx:228`, and its canonical
spelling is `activity_log.view_system` — held by `super_admin` alone today
(`RbacSeeder::SUPER_ADMIN_PLATFORM`), which is why `roles.includes('super_admin')` is *currently*
equivalent and why nothing has broken. Equivalent-today is exactly the condition under which a copy
survives long enough to drift: `activity_log.view_system` is a real permission that could be granted
to a second seat, and the day it is, the control does not appear for them.

`canExport` gates the only client-side export control in the codebase — the Export button at
`index.tsx:188-196`, whose `href` is built at `index.tsx:144-152`. There is no other one: the sweep
covered `resources/js/pages/`, `resources/js/components/` and `resources/js/layouts/`;
`ActivityFilterBar` receives `capabilities` but reads only `canViewSystem`
(`activity-filter-bar.tsx:228`), and the timeline and detail drawer carry no export control.
Everything else naming the endpoint is generated wayfinder output under `resources/js/actions/`,
which is a URL builder, not a control.

## Why it is wrong, structurally and already

**Each is a second spelling of an authority whose canonical spelling is a permission.** The server
answers "may this user export?" with `activity_log.export` — the seeder map, the database grant, and
now (since `fix/three-authorisation-holes`) the route middleware. The client answers it with a
hand-maintained list of three role names. **Nothing keeps the two in step**: no lint, no test, no
type. They drift silently, in the direction whichever side is edited.

**And one of them has already drifted.** `internal_auditor` holds `activity_log.export`
(`RbacSeeder::grantsMap`) and is absent from the list. It is not a hypothetical. `canViewSystem` has
not drifted yet — it has a single holder, which is the only reason a role name and a permission still
name the same set there.

This is the same class as the rules this repo has recorded elsewhere — a convention with no
enforcement behind it, and a description asserting a property nothing checks. The role list *reads*
as the authorization; it is a copy of it.

## The fix

Derive **both** from their permissions and delete every role test in the object:

```ts
const capabilities: ActivityCapabilities = {
    canViewSystem: can('activity_log.view_system'),
    canExport: can('activity_log.export'),
};
```

`usePermissions()` is already the established hook for exactly this — `app-sidebar.tsx:359` uses
`can()` for the same kind of decision, and its docblock records the one case where roles are correct
(persona menus, which are identity presentation, not authorization). An export button is
authorization, so it takes the permission.

## The test

One arm per capability, asserting the flag follows the **permission**, not the role name — a seat
that holds `activity_log.export` without any of the three listed roles sees the export control, and a
seat holding a listed role without the permission does not. `canViewSystem` gets the same pair
against `activity_log.view_system`; an arm for only the field that was already wrong would leave the
one that is merely equivalent-today untested, which is the field most likely to drift quietly.

**Build the negative fixture on a role that does not hold the permission — never on `admin`.**
`admin` holds effectively everything, so a negative arm built on it can pass for a reason other than
the flag under test and is structurally incapable of failing on its own axis. That is the
degenerate-fixture trap recorded in `CLAUDE.md`, and it is exactly what a role-vs-permission test is
vulnerable to.

## Related

The `internal_auditor` half of this is not just a missing entry in the list — that seat cannot reach
the page at all. See
[`audit-seat-has-the-ability-and-no-way-to-reach-it.md`](audit-seat-has-the-ability-and-no-way-to-reach-it.md);
fixing this ticket alone does not give the auditor an export button, because it never renders the
page that holds it.
