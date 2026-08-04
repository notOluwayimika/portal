# 0052 — A migration is a dated act, not a live query

**Status:** Accepted — 2026-08. **Deciders:** owner + advisor. Converts four already-shipped
migrations and adds one repo-wide gate. Changes **no behaviour on any environment that has already
applied them**; changes what they do on **replay**, which is the point.

## Context

Four shipped convergence migrations computed their **target** from `RbacSeeder`'s grants map **at run
time**, while freezing their **governed role set** as a literal in the file. Two of them said so in
their docblocks and called it a design — *"The role SET is written out here (a migration is a fixed
historical act and must not re-shape itself if the map moves later); the GRANTS are derived."*

That sentence is the defect, written down and mistaken for a virtue. The two halves move in opposite
directions: the role set is pinned to the day the migration was written, the grants are pinned to
whatever the seeder map says the next time anyone runs `migrate`. A migration is a record of an act
that happened on a date. Reading a live source inside one means it is not a record of anything.

The 2026-08-04 seat move — every finance checker side moving from `head_of_school` and
`accounts_supervisor` to a new `executive_director` role — triggered both failure modes at once.

**Failure mode A — the migration changes identity.** `2026_08_05_100000_converge_finance_access_grants`
was authored to **grant** `finance.access` to `head_of_school`. Replayed against the post-seat-move
map, where HoS holds no finance grant at all, it **revokes** it. Same filename, same `migrations` row,
opposite act. Every replay path hits this: `migrate:fresh`, `migrate:refresh`, a restored backup, and
the release gate's own rollback-and-re-up.

**Failure mode B — the migration bricks.** Each one carried an "offender" pre-flight that ABORTED when
a global role *outside* its frozen governed set held a governed permission. `executive_director` holds
five of them by design. So on any seeded database `migrate` died — and it died earlier than the test
suite could show. Bite-proved before this change, against a seeded database:

```
>>> STEP 0 RESULT: RuntimeException
>>> MESSAGE: realign-finance-grants ABORTED: unexpected global role(s) grant the governed permissions:
    executive_director (holders=0). The maker source is not what the realignment assumed — investigate
    before widening this migration.
```

`2026_08_02_100000_realign_finance_governance_grants` is FIRST in filename order and **had no test file
at all** — nothing in `tests/` referenced it. The suite reported six red arms in two *other* files and
said nothing about the migration that actually stopped `migrate`. An unarmed migration is not a passing
migration; it is an unwatched one.

## Decision

> **A migration is a dated act, not a live query.** A migration that writes grants carries its target
> as a frozen literal, dated and attributed to the commit that added it. It never reads
> `RbacSeeder`'s grants map.

And the corollary, which is the part that is easy to get wrong:

> **A convergence migration aborts only on a condition its own writes would create. Every other
> surprise it reports and continues past.**

The only condition that qualifies today is `2026_08_03`'s post-write, user-scoped duty-separation
walk: that migration's own grant is what puts a user on both sides of a maker–checker pair, so it
throws and rolls the transaction back. It is untouched by this ADR.

Everything else — a permission row that no longer exists, a governed role that no longer exists, a
non-governed role that now holds the permission — is *the world moving on*. A migration cannot touch a
role it does not govern, so an "offender" is information, never danger. Aborting on it converts a
harmless surprise into a permanent brick on every future `migrate:fresh`.

Targets are frozen as **plain strings**, not `PermissionEnum::` constants. An enum case can be renamed
or deleted; a frozen historical act must not depend on today's enum any more than on today's map.

## The trade, stated rather than buried

This is the honest cost and it is not hypothetical. An environment that genuinely has **not** run
`rbac:sync` will now get a loud `SKIPPED:` line where it used to get a hard stop, and an operator who
does not read it will under-converge — the migration will have run, the `migrations` row will say so,
and some grants will not be where the map says they are. `php artisan rbac:diff-grants` is the thing
that finds that afterwards.

The trade was taken deliberately: **a stop that fires correctly once and incorrectly forever is worse
than a report that has to be read.** The abort fires on a real problem roughly never and on the world
having moved on every single time, and its cost when wrong is that nobody can migrate at all.

## Consequences

Four files converted, each frozen at the commit that added it:

| file | frozen at | date |
| --- | --- | --- |
| `2026_08_02_100000_realign_finance_governance_grants.php` | `f143b40` | 2026-08-01 |
| `2026_08_03_100000_converge_finance_change_grants.php` | `01fdeda` | 2026-08-02 |
| `2026_08_04_100000_revoke_internal_auditor_cross_school.php` | `4d4c9c5` | 2026-08-02 |
| `2026_08_05_100000_converge_finance_access_grants.php` | `af9db7a` | 2026-08-03 |

All four commits agree on the relevant map slices, which is what makes this edit behaviour-preserving
on every environment that has already applied them.

`2026_08_06_100000_move_head_of_school_finance_to_executive_director.php` carries the identical defect
and is converted on its own branch, after this merges. The gate below forces it.

**The gate.** `tests/Feature/Rbac/MigrationsDoNotReadTheSeederMapTest.php` globs every migration and
asserts none contains `grantsMap`. It asserts the glob matched more than 100 files **first**: a scan of
zero files finds no offender and reports green, and that is the one failure mode this test may not
have.

It deliberately does **not** live in `bin/ci-grants-convergence-lint.php` (`bin/quality` step 7). That
gate is diff-based and reads only the files a branch ADDS, by a rule that is correct for what it does —
so a migration already on the base is structurally invisible to it, and files already on the base are
exactly the population this invariant governs.

**Remainder, ticketed not worked.** `grep -rhoE "RbacSeeder::[A-Za-z_]+|PermissionEnum::[A-Za-z_]+"
database/migrations/` also returns `sync`, `ROLES`, `SUPER_ADMIN_PLATFORM`, `ISOLATION_CROSSING` and
`FINANCE_ACCESS`. `RbacSeeder::sync` in a migration is the extreme form of the same defect — a
migration that re-runs the seeder re-shapes itself completely. The rest are milder instances of it.

The line is drawn at the grants map for one reason: it is the only one whose value is a **business
decision that moves every time Brookstone changes their mind**, and the only one that has actually
bitten. Widening the gate to the others is a separate decision with a separate blast radius.
