# Slice C6 — `feat/rbac-superadmin-module` — brief (drafted 2026-07-22)

The super-admin **role × permission matrix**: site-wide editing of what each role
is granted, under `/super-admin` (the one deliberately role-gated area). Three
things, all of which must hold for C6 not to quietly undo what C1–C5 pinned:
the editor itself, the grant-time forbidden-edit invariants, and sync atomicity.

## Step 0 — vendor-read first (the C5 lesson, applied before building)

`HasPermissions` (spatie 7.4.1) mirrors `HasRoles`' non-atomic shape — **zero
transactions in the trait** — but with a sharper wrinkle the vendor-read caught
before it became a false-green bite-proof:

- `syncPermissions` (L442) calls `$this->permissions()->detach()` **raw** — not
  `revokePermissionTo` — so **`PermissionDetachedEvent` never fires during
  sync's detach half** (that event lives only in `revokePermissionTo`, L470,
  post-write). Assuming the event name by analogy with C5 would have produced
  an injection that injects nothing.
- Worse than the test concern: C1's audit listener subscribes to that event, so
  **a `syncPermissions`-based matrix would write NO audit row for removals** —
  revocations of privilege would be untraceable, on the surface whose whole
  point is traceable privilege change.

**Therefore D3 below: the write path is diff-based revoke+give, not sync.**

## Decisions

### D1 — the super_admin row is immutable through the matrix.

Structural target rule (`role.name === 'super_admin'` → deny), same reasoning
as C5's D1: a permission-shaped guard would be bypassed by exactly the actor
class using this module. super_admin's authority is the Gate::before bypass;
its explicit 15 grants are frozen legacy (the authority probe's precondition);
matrix edits to that row could only break invariants, never grant authority.
Proven with the flag ON.

### D2 — no role may end up holding a maker and its matching checker.

Grant-time SoD: an edit is rejected if the RESULTING set contains an ability
with terminal `approve`/`reject` together with its matching maker (same prefix,
terminal `submit`) — derived by the ApprovalAbility convention, not a name
list, so `finance.invoice.submit`/`finance.invoice.approve` is covered the day
Ph3 creates the pair. Validated on the resulting set (not the delta): the
invariant is about the state the edit produces, however it got there. This is
the runtime-editing counterpart of the seeder's SoD test — that test pins the
DEFAULT map; this guard pins every map the matrix can produce.

### D3 — diff-based revoke+give inside `DB::transaction`; never `syncPermissions`.

Three reasons, all from the step-0 vendor-read:
1. **Audited removals** — `revokePermissionTo` fires `PermissionDetachedEvent`,
   so the C1 listener writes the detach row `syncPermissions` would silently
   skip.
2. **Minimal failure window** — unchanged grants never leave the row; sync's
   detach-all strips the entire role between halves.
3. **A provable gap** — the detach event fires post-revoke-write, pre-give, so
   the between-halves failure is injectable and the transaction bite-provable
   (the C5 proof discipline; the un-wrapped failure mode at role scope strips a
   ROLE's permissions for every holder in every School at once).

### D4 — the enum is code: the matrix edits grants, never definitions.

No role or permission is creatable, renamable or deletable at runtime. Payload
permission names must be enum members; the target role must be one of the nine
seeded global roles (and not super_admin, per D1). Unknown names are validation
failures, not creations.

### D5 — runtime edits survive `rbac:sync`.

Already the seeder's non-destructive contract (C1-tested at the seeder level);
C6 pins it end-to-end once through the matrix: a matrix-made grant AND a
matrix-made revoke of a seeded default both survive a subsequent sync.

## Scope deviation from the plan, declared

The plan's C6 row lists a per-role `two_factor_required` toggle. **Not built
here**: the column it toggles is C7's migration, and a toggle shipping ahead of
its column is wallpaper by construction. It moves to C7, next to the thing it
toggles.

## Verification

- Guards D1/D2/D4 bite-proven red-when-removed; D3 bite-proven at the true
  between-halves point (`PermissionDetachedEvent` listener throw → without the
  transaction, revocations persist and additions never run).
- Audit: one matrix edit that removes one grant and adds another produces BOTH
  a detach row and an attach row, causer = the acting super admin.
- Floor 10/10; drive in the running app.

## Coordination

- New routes under the existing `/super-admin` `role:super_admin` group; no
  Finance surface touched. Standing human items unchanged (prod flag, ADR 0040
  limits + #86 timing).
- The vendor facts above are pinned in CLAUDE.md § Testing — paid for twice
  (C5 roles, C6 permissions); a third rediscovery would be negligence.
