# Brief — `finance.access` grant convergence

Write one migration and one test. `finance.access` grant convergence — the same class of bug as
`2026_08_03_100000_converge_finance_change_grants`, different permission.

## Step 0 — re-derive before writing anything. Do not trust these numbers.

Run `php artisan rbac:diff-grants` and `php artisan rbac:diff-grants --json` against the local DB and
paste both. I need, from the DB not from me:

- which of the six `grantsMap()` holders of `finance.access` are actually missing the global
  (`school_id IS NULL`) pivot row
- whether any global role OUTSIDE those six holds `finance.access` (specifically check `super_admin`
  and `internal_auditor`)
- the school-scoped row count for `finance.access`
- whether any `activity_log` row records the loss

If the gap is empty, stop and say so — there is nothing to converge and the migration should not be
written.

## The bug

`rbac:sync` is non-destructive for grants in both directions (`RbacSeeder.php:478`, `:494-496`): for a
role that already exists it applies only permissions CREATED in that same run. `finance.access` and
all six role rows pre-date every environment, so any role added to `grantsMap()['<role>']` after
seeding never receives it. Same shape as #186 and `2026_08_03`.

## Governed set

`admin`, `head_of_school`, `principal`, `accounts_officer`, `accounts_supervisor`, `finance_lead` —
all six holders per `grantsMap()` (`RbacSeeder.php:199, :230, :286, :336, :359, :373`), not just the
drifted ones, so drift in either direction cannot hide.

`internal_auditor` is deliberately NOT governed and must NOT receive `finance.access` —
`RbacSeeder.php:377-391` records the grant as DECIDED and UNIMPLEMENTED (Phase 2, per-school, not
cross-school). If it currently holds the row, the pre-flight below should abort rather than the
migration silently revoking it.

## File

`database/migrations/2026_08_05_100000_converge_finance_access_grants.php`

### Follow `2026_08_03_100000_converge_finance_change_grants.php` exactly for

The fresh-install guard keyed on the permission substrate; the `grantsMap()`-derived target; the
pre-flight that the target permission exists (else abort telling the operator to run `rbac:sync`
first); the pre-flight that no other global role grants it, reporting each offender with its holder
count; the pre-flight that every governed role exists as a global row; the school-scoped footprint
echo (counted, never written); the idempotency check that short-circuits before writing any activity
row; diff-based `revokePermissionTo` + `givePermissionTo` inside one transaction so `LogRbacChange`
audits every delta (NOT `syncPermissions`, which detaches raw with no event);
`forgetCachedPermissions()` after; BEFORE/AFTER holder counts per school as
`school#<id>  finance.access  holders=<n>`; and a `down()` that is a documented no-op.

### Deliberately deviate on one thing

Do NOT copy the post-write user-scoped `DutySeparation::violations` walk. `DutySeparation::pairs()`
emits a pair only when `ApprovalAbility::matchingMakerFor($ability)` resolves for a checker action;
`finance.access` is neither a checker action nor a maker, so it appears in no pair and
`enforcedPairs()` cannot contain it. Converging it can neither create nor clear a both-sides
violation. Say this in the docblock so the next reader knows the omission is reasoned, not forgotten.

### Privacy

Counts, school ids and permission names only. No user names, no emails. `user#<id>` / `school#<id>`
if you must name anything.

## Test

`tests/Feature/Rbac/FinanceAccessGrantConvergenceTest.php`, modelled on
`FinanceChangeGrantConvergenceTest.php`. Four arms:

1. Plant the real drift (revoke `finance.access` from the roles the diff showed missing), run `up()`,
   assert those roles gain it and the other governed roles are byte-identical before/after.
2. Idempotent — a second `up()` changes no grant and writes no `activity_log` row. Assert `MAX(id)`
   unmoved, not just the count.
3. The offender pre-flight bites — grant `finance.access` to a global role outside the six, assert
   `up()` throws and NO grant changed.
4. Bite-proof arm 1 is not vacuous — assert that with the drift planted and the migration NOT run,
   the missing roles genuinely lack the grant. An arm that passes on an already-converged DB proves
   nothing.

## Do not expect a grants-convergence lint red

`grantsMap()` is not changing — this fixes historical drift against an already-correct map. The lint
fires on map changes only. If it does go red, stop and tell me: that means the map moved and I have
the wrong model of the bug.

## Second, unrelated, one line

`bin/quality-promote:94` prints `git merge --no-ff staging`. `.githooks/pre-push:48` compares
`.quality-promote-ok` to `$local_sha`; a `--no-ff` merge mints a new SHA, so following the script's
own printed instruction always blocks the push. `.githooks/pre-push:52-61` already prints `--ff-only`
and explains why. Change `:94` to `--ff-only`.

## Report

Run `bin/quality` before you report. Report as: the diff-grants output from step 0, the before/after
holder counts, which arms are red-before/green-after, and anything in this brief you think is wrong.
