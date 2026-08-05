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

The only surviving abort is `2026_08_03`'s post-write, user-scoped duty-separation walk: that
migration's own grant is what puts a user on both sides of a maker–checker pair, so it throws and
rolls the transaction back. It is untouched by this ADR.

**And it is deliberately broader than the corollary, which is worth stating rather than glossing.**
The walk calls `DutySeparation::violations` over the user's *combined* roles and filters the result to
`enforcedPairs()` — the finance checkers — but never to the permissions this migration actually wrote
in this run. So a both-sides state assembled entirely from roles it does not govern would also throw.
That is a known overstatement of the rule above, ticketed rather than fixed: it cannot bite today, and
scoping the walk to this run's writes is a behaviour change that needs its own proof. The concrete
future case is on the `executive_director` branch — a user holding ED plus any `*.change.submit` maker
role is a violation this 2026-08-02 migration did not create and would roll back for.

Everything else — a permission row that no longer exists, a governed role that no longer exists, a
non-governed role that now holds the permission — is *the world moving on*. A migration cannot touch a
role it does not govern, so an "offender" is information, never danger. Aborting on it converts a
harmless surprise into a permanent brick on every future `migrate:fresh`.

Targets are frozen as **plain strings**, not `PermissionEnum::` constants. An enum case can be renamed
or deleted; a frozen historical act must not depend on today's enum any more than on today's map.
### The corollary is a two-part test, not a slogan

The corollary as first written — *"aborts only on a condition its own writes would create"* — could not
decide the next case that came to it, and a rule that cannot decide the next case is not yet a rule.
Before converting an abort to a report, ask two questions.

1. **Would continuing leave a hole this migration's own writes dug?** A migration whose act is a
   TRANSFER — strip one role, grant another — cannot half-apply. Skipping the grant half while the
   strip half runs is not the world moving on; it is the migration digging the hole itself.
2. **Does the abort message name a command that clears the condition and lets the migration pass?**

**Both yes → the abort stands.** It is a precondition, not a brick, and the operator has a one-command
exit.

**(1) no → report and continue, regardless of (2).** A migration cannot touch a role it does not
govern, so an offender is information.

**(1) yes and (2) no → do not convert and do not leave it.** The migration is unsafe to continue AND
unsafe to stop, which is a design problem, not a comment problem. Escalate it.

**Against the four files this branch converted, part 1 is NO for every converted abort.** Each
converges one role toward its own frozen slice, so a missing role or a missing permission costs
coverage, never coherence. That is why they became reports and skips, and why that stays correct.

**Against `2026_08_06_100000_move_head_of_school_finance_to_executive_director` — the next migration to
meet this rule — part 1 is YES.** Its act is a transfer: it strips five finance grants from
`head_of_school` and four from `accounts_supervisor` and grants nine to `executive_director`. Skipping
the grant half while the strip half runs leaves the four `*.change.approve/.reject` and the two
credit-note/void checker pairs held by **nobody** — and combined with the `Gate::before` maker–checker
exclusion (ADR 0040), no seat on the platform, `super_admin` included, could approve anything
financial. Part 2 is YES: `php artisan rbac:sync` creates the missing role row and the migration then
passes. **So it aborts, and its sibling abort on a missing target permission row aborts for the same
reason. Neither converts.**


### The same boundary, applied to `DutySeparation`

Freezing the target did not freeze the guard. The four converted migrations stopped reading the
seeder map for their **target** and went on reading live authority state for their **abort** — and a
2026-08-02 migration would still roll back for a both-sides state assembled entirely from roles it
does not govern. *(Found by the implementing agent after the freeze shipped; the advisor specified the
preservation of that abort and never looked inside it. This section exists because of that finding.)*

The boundary is **dated act vs runtime question**, not which primitive is called. `DutySeparation` has
three populations of caller, and only one of them needed narrowing.

**RUNTIME — 7 call sites. Leave live.** `User::assignRole` (`app/Models/User.php`),
`SyncRolePermissionsRequest`, `RbacOverview`, `SchoolRbacOverview`, `CheckStaffingReadiness`,
`AuditDutySeparation`. Each asks a question about NOW: may this assignment proceed, is this school
staffed, who currently holds both sides. A live answer is the only correct one, and freezing any of
them would be this ADR's defect in reverse.

**DATED ACTS THAT REPORT — 4 call sites. Leave live.** `DutySeparation::holdsViaGrant` in the
`report()` methods of `2026_08_02`, `2026_08_04`, `2026_08_05` and `2026_08_06`. A report of current
state is exactly what they are; a frozen holder count would be a lie about the database in front of
you. Do not freeze these either.

**DATED ACTS THAT DECIDE — 2 call sites. Scoped.** The post-write walks in `2026_08_03` and
`2026_08_06`. These read live state to decide whether to **roll back a dated act**, which is the one
place the distinction bites. Both are now scoped to what their own run WROTE:

- The filter is by PERMISSION, not by user. The walk still visits every user in every school; what
  narrowed is which findings it will roll back for. A pair is the migration's to block on only when at
  least one of its two sides is a permission **this run actually granted** — not the frozen target,
  the grants the diff wrote on this run.
- **Revocations are out of scope in both directions**: a revoke can only CLEAR a both-sides state,
  never create one. For `2026_08_06`, whose act is a transfer, that means the scope is the granted
  side only.
- A second, idempotent run grants nothing and therefore flags nothing — correct, because it wrote
  nothing.
- Violations outside that scope are **reported and continued past**: `user#<id> @ school#<id>`,
  counted, and the count repeated in the `AFTER` report, with the echo naming
  `php artisan finance:audit-duty-separation` as their owner. They are real and they matter; they are
  not a migration's to block on.

This answers part 1 of the two-part test rather than amending it. A violation the run did not create
is not a hole the run's own writes dug.

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

| file | what changed | frozen at | date |
| --- | --- | --- | --- |
| `2026_08_02_100000_realign_finance_governance_grants.php` | target frozen; three aborts → report/skip | `f143b40` | 2026-08-01 |
| `2026_08_03_100000_converge_finance_change_grants.php` | target frozen; three aborts → report/skip (the duty-separation walk keeps its throw) | `01fdeda` | 2026-08-02 |
| `2026_08_04_100000_revoke_internal_auditor_cross_school.php` | **already frozen** — its act is `PERMISSION` + `ROLE`, literals before this branch. Its live-map assertion deleted; three aborts → report/skip | — | 2026-08-02 |
| `2026_08_05_100000_converge_finance_access_grants.php` | target frozen; three aborts → report/skip | `af9db7a` | 2026-08-03 |

`2026_08_04` gets no `TARGET` const, deliberately: it revokes one named permission from one named
role, both already literals, so a const no code reads would assert a wiring that does not exist. An
ADR that overstates its own coverage is the same failure as a green test that scanned zero files.

The three adding commits agree with their frozen literals on the relevant map slices, which is what
makes this edit behaviour-preserving on every environment that has already applied them.

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

**Remainder, ticketed not worked.** Re-derived on this branch, not carried:

```
$ grep -rhoE "RbacSeeder::[A-Za-z_]+|PermissionEnum::[A-Za-z_]+" database/migrations/ | sort | uniq -c | sort -rn
  23 RbacSeeder::GUARD
   5 RbacSeeder::sync
   2 PermissionEnum::ISOLATION_CROSSING
   1 RbacSeeder::syncLogged
   1 RbacSeeder::SUPER_ADMIN_PLATFORM
```

**All five `RbacSeeder::sync` occurrences are PROSE IN DOCBLOCKS**, not calls — `2026_08_02:17`,
`:42`, `2026_08_03:16`, `2026_08_04:48`, `2026_08_05:16`, each explaining why non-destructive sync
made the migration necessary. So does the single `syncLogged` (`2026_08_04:30`). `RbacSeeder::GUARD`
is a guard-name constant, not a decision that moves. There is no `::ROLES` and no
`PermissionEnum::FINANCE_ACCESS` in `database/migrations/` on this branch at all.

An earlier draft of this ADR said *"`RbacSeeder::sync` in a migration is the extreme form of the same
defect — a migration that re-runs the seeder re-shapes itself completely."* That sentence described
**zero lines of executable code**, and it is withdrawn. The claim was asserted from a grep whose hits
were never read, on a different branch, and carried forward — which is the same failure this ADR is
written against, committed inside it.

**The class it described is real, and there is one live instance.**
`database/migrations/2026_05_06_085734_update_terms_and_curricula_tables.php:48`:

```php
Artisan::call('db:seed', ['--class' => 'TermSeeder', '--force' => true]);
```

A migration that re-runs a seeder inside `up()`. It is invisible to the gate below, because it
carries no `RbacSeeder::` token at all — the gate scans for one string and this instance does not
contain it. The file documents itself honestly and at length (`:27-48`): `TermSeeder` computes every
term window from `now()->startOfYear()`, so *"the rows this migration writes DEPEND ON THE DAY IT
RUNS"*, and its `updateOrCreate` re-run *"OVERWRITES the dates of terms that already exist"*. Its own
paragraph records **NOT REPAIRED, DELIBERATELY** — it has already run everywhere, and rewriting it
would change only what a future `migrate:fresh` produces. The same comment records the cost: term
dates are load-bearing for money through the `finance_fee_schedules.term_id` RESTRICT FK.

That decision stands and this branch does not disturb it. It is named here so the class has a real
address instead of a wrong one, and ticketed: *stop seeding from a migration at all*, which the file
itself names as the correct fix and as a separate change with its own data question.

The line for THIS gate is drawn at the grants map for one reason: it is the only source whose value is
a **business decision that moves every time Brookstone changes their mind**, and the only one that has
actually bitten. Widening the gate — to seeder invocation, to `Artisan::call`, to the enum — is a
separate decision with a separate blast radius, and the census above is what it should be sized
against.
