# The guardian binding's applicability rule is stated and ungated

**Raised:** 2026-09-01 · **From:** the Phase-0 read of `GET /api/guardians/{guardian:uuid}/students` · **Severity:** ticket

## Correction to the first version of this ticket

It was titled *"has an undocumented off switch"*. **That was wrong, and the word doing the work was
"undocumented".** `GuardianService.php:1066-1091` is a 26-line docblock that raises this exact
objection, answers it, and records the deliberate divergence it creates with the bare
`hasRole('guardian')` used in the visibility filters. I read the method body and not the docblock
above it, and asserted an absence I had not checked. The finding survives; its stated cause does not.

## What

`App\Services\GuardianService::isActingAsGuardian()` (`GuardianService.php:1092-1099`):

```php
if (! $user->hasRole('guardian')) {
    return false;
}

return $user->getRoleNames()->diff(['guardian'])->isEmpty();
```

`EnsureGuardianOwnsGuardianRecord::handle()` applies the ownership binding **only** when this returns
true:

```php
if (! $user || ! $this->guardians->isActingAsGuardian($user)) {
    return $next($request);
}
```

So the binding applies only to a user who holds the `guardian` role **and no other role at all**.
Any second role — of any kind — makes the predicate false, the user takes the staff pass-through, and
the ownership binding stops applying to them.

## The invariant it rests on, in its own words

The docblock states the load-bearing claim explicitly:

> "Every other role in `RbacSeeder::grantsMap()` is a staff or oversight seat, so 'holds anything
> besides guardian' is a sound and self-maintaining reading of 'is staff'."

Everything above that sentence is right, and the reasoning for rejecting the bare
`hasRole('guardian')` is right: a teacher who is also a parent must keep their staff reach, and
`GuardianBulkRecordAccessTest:341` pins it.

**The defect is the last four words.** `self-maintaining` is a property of MECHANISMS, not of
docblocks. Nothing fails when a non-staff role is added to `grantsMap()`. The sentence is a wish
about the future written in a comment, and the future does not read comments.

**A rule without a gate is wallpaper** — and this one carries three middlewares, twelve routes and
four consumers. It is the highest-leverage sentence in the file and the only one with nothing behind
it.

Note too that the invariant is stated over `grantsMap()` while the predicate evaluates
`$user->getRoleNames()` — **the roles actually assigned**, which is a superset in principle: a role
row created at runtime through the RBAC matrix, or a school-scoped role, satisfies the predicate
without ever appearing in the map the sentence quantifies over. The gate below closes the map half,
which is where new roles are actually introduced; it does not close that one, and saying so is part
of stating the gate's coverage honestly.

## Blast radius: three middlewares, not one

The same predicate gates every guardian containment control in the codebase:

| Middleware | Line | What stops applying |
| --- | --- | --- |
| `EnsureGuardianOwnsGuardianRecord` | `:79` | a parent may address only their own guardian row |
| `EnsureGuardianOwnsStudent` | `:92` | a parent may address only their own ward's records |
| `DenyGuardianBulkRecords` | `:75` | a parent may not fetch cohort-wide records |

`App\Support\StudentRecordAccessLog:30` also keys the audit trail on it. One predicate, one switch,
four consumers.

## Why it is safe today, and exactly how thin that is

It is safe **only because every second role that exists today is a staff role**, and staff are meant
to pass through. The predicate is standing in for "is this person acting purely as a parent", and it
happens to be right for the current role set.

It is **one future non-staff role away** from silently disabling all three bindings for whoever
holds it. Any `alumni`, `board_member`, `prospective_parent`, `sibling_portal` — anything a parent
might also hold that is not staff — flips the predicate to false and takes that user through the
pass-through with the binding disengaged. Nothing fails. No test reds. The role is added for an
unrelated reason and the containment quietly stops applying.

**And this is the class of defect that spreads silently**, because the violation produces no red at
write time: the person adding the new role has no signal that they have changed anything about
guardian containment. The docblock does not help them — it sits in a file they have no reason to
open, describing a property of the file they are editing.

## What is pinned and what is not

`GuardianBulkRecordAccessTest:341` — *"does not restrict a member of staff who is also a parent at
the same school"* — pins the **intended** dual-role case, deliberately and well: it builds a real
`teacher` + `guardian` user with a real guardian row and a real ward, and asserts they behave exactly
as a plain teacher. Its docblock says the assertions "fail if either guard reimplements it as the
bare role test", which is true and is why the predicate was extracted to one place.

**Nothing pins the unintended case.** There is no arm for `guardian` + a non-staff role, because no
such role exists to write one against. The test suite therefore agrees with the current role set
rather than with the rule, and will keep agreeing right up to the day the rule breaks.

## Ruling

**Decide applicability on a positive fact — the user has a guardian row in the active school — and
decide the staff pass-through on a permission, not on the absence of other roles.**

Two independent reasons, and the second is the one that generalises:

1. **A negative over an open set cannot be verified.** "holds no role other than `guardian`"
   quantifies over every role that will ever exist, including ones not yet invented. A positive fact
   about the caller (`forUserInActiveSchool($user) !== null`) is closed, checkable, and already
   computed by the binding one line later.
2. **A role-name test deciding an authorization branch is a second spelling of an authority.** That
   is the defect [`can-export-derives-from-role-names.md`](can-export-derives-from-role-names.md)
   exists to fix, and this is the same shape on a far more sensitive path — the one deciding whether
   a parent reads a stranger's children. The staff arm should key on the permission that means
   "may read other people's guardian records"; see
   [`guardian-view-asserted-on-a-route-its-users-cannot-satisfy.md`](guardian-view-asserted-on-a-route-its-users-cannot-satisfy.md),
   which notes that choosing that permission is itself a grant decision (it would narrow the staff
   arm from `{admin, head_of_school, teacher, principal}`) and must be made deliberately.

The honest ordering: the applicability half (1) is a correctness fix and can be made now. The staff
half (2) carries a grant decision and should not be smuggled in beside it.

## The cheap gate — convert the sentence into a mechanism

`bin/ci-grants-convergence-lint.php` **already parses `grantsMap()`**: it tables the shared fragments
defined between `function grantsMap()` and its `return [`, indexes the roles that consume them in
both forms (`...$fragment,` and `'<role>' => $fragment,`), and builds the same model at BASE as at
HEAD. The parsing work this gate needs is done.

**The sibling assertion:** every role key in `grantsMap()` other than `'guardian'` is a **staff or
oversight** seat. Concretely — a declared classification list in the lint, asserted to cover the map's
key set **exactly**. Add a role to the map and the lint reds until it is classified; classify it as
non-staff and the guardian binding's stated assumption is false, which is precisely the moment
somebody needs to know.

**Why a declared list and not a computed one.** "Is this seat staff?" is not derivable from the map —
it is a human judgment about a role's purpose. That is the point rather than a weakness: the gate's
job is to force the judgment to be made and recorded at the moment a role is introduced, not to make
it automatically.

**Why the diff-shaped precedent applies.** That lint's header states, at length, why its invariant
**cannot be asserted from state**: CI's database is freshly seeded, `$existingRoles` is empty, so
`grantsMap()` always matches by construction and a green proves nothing. The same shape holds here
from the other direction — a fresh database tells you nothing about which roles are *meant* to be
staff, and no runtime state records the intent. **The change is the only place the invariant is
visible**, so the check belongs where that lint already looks.

**State the gate's coverage in three numbers, not two** — roles *examined*, roles *excluded with a
stated reason*, and roles **unrecognised**, with the third asserted to be zero. A key form the parser
cannot classify must red, not vanish into a skipped count; that is how the SIGNAL-length lint read 61
of 117 messages and reported clean.

**And give it the known negative.** A gate that refuses everything is indistinguishable from a strict
gate until someone disables it. Two arms: a map whose keys are all classified passes, and a map with
one unclassified key fails. `bin/db-exclusive`'s first version refused on a free database and a
busy-only bite-proof would have passed it.

**Bite-proof it by mutating `grantsMap()` itself** — add a plausible non-staff role (`alumni`) and
watch the lint red — not by hand-feeding the parser a fixture, so the proof survives the way the real
map is written.

## Not to be changed casually

**This is a live control on the path 37 parents use daily.** The fix needs:

- its **own commit** — not folded into the ability-check removal, and not into a role change;
- its **own bite-proof** — revert the new predicate alone and watch only its arms red;
- `GuardianBulkRecordAccessTest:341` (staff-who-is-also-a-parent) and `:418` (two-school parent reads
  their own record) **green throughout** — these are the two false-negative arms, and either going
  red means the fix has locked out a real person rather than closed a hole;
- a **new arm for the unintended case**: a `guardian` holding a second, non-staff role must still be
  bound. Since no such role exists, the arm creates a throwaway one in its own fixture — which is the
  only way to test a predicate whose defect is defined over roles that do not exist yet.
- and the `:260` / `:286` cross-school and no-row arms green, since the replacement predicate is
  exactly the resolver those two exercise.

## Precondition for the `AUTHZ_ENFORCE` flip

**Every row in `authz_observations` is a 403 the flip will make real.** The flip is blocked until
that table is empty, or until every remaining row is a denial we have read and intend to enforce.
The production census is not a supporting check — **it is the flip's blast radius**.

This ticket is not itself a row-source — the binding is real middleware and refuses now, whatever
`authz.enforce` says. It is recorded here because the flip's safety argument for this route rests on
the binding applying to the caller, and the off switch is the condition under which it does not.
