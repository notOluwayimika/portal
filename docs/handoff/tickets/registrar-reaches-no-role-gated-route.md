# `registrar` reaches no role-gated route at all — and rollover is waiting on the answer

**Status:** open · **Severity:** fix (a role-wide gap; rollover is only the surface that found it)
**Found:** coining `academics.rollover` in M4 slice 2.

## Two things, in order. The second is blocked on the first.

### 1. Diagnose the role, not the symptom

`registrar`'s grant block in `RbacSeeder::grantsMap()` holds four guardian permissions and carries the
comment *"No route access: registrar appeared in no pre-swap `role:` group, so it reaches no
role-gated route — unchanged."*

So a registrar today can be granted guardian abilities and reach **no gated route to use them on**.
Either:

- **the role is under-wired** — an artefact of the role-to-permission swap, where a role that had no
  `role:` group before the migration ended up with no route access after it, and nobody noticed
  because nothing failed loudly; or
- **it was never meant to be gated** — the role exists for grant-shaping only and is always paired
  with another seat.

**That question is role-wide and worth answering on its own.** It is not rollover's to settle, and
rollover must not become the incidental place it gets decided — a permission granted to make one
screen work would answer a question about the whole role by accident.

Start from the `role:` groups the swap replaced, and from whether any live user holds `registrar`
alone. `bin/ci-authz-lint.php` and `tests/fixtures/route-access-map.json` are the artefacts that
describe what reaches what.

### 2. Then grant rollover, if the answer permits

M4 shipped `academics.rollover` **granted to `admin` only**, deliberately: it is the most
destructive action in the system, so it went out with the smallest grant that can actually exercise
it. The milestone's own trigger, though, was *"a registrar is expected to run one themselves"* — and
that is not true yet.

Granting `academics.rollover` to `registrar` **before** step 1 would produce a permission the role
cannot exercise, which is worse than not granting it: it reads as shipped in the RBAC console while
the screen stays unreachable.

## Why this is filed rather than carried

Without it, M4 reads as "a registrar can roll the year over" because that was the stated trigger,
when what shipped is "an admin can". The gap is invisible from the code — the permission exists, the
routes exist, the tests pass — and only shows up when a registrar tries.

M4 is honestly **"admin can roll over"** until this closes.

## Not in scope here

Whether a registrar *should* hold rollover at all is a school-policy question, and a real one: the
answer may be no, in which case step 2 closes as "won't do" and M4's grant is already correct.
