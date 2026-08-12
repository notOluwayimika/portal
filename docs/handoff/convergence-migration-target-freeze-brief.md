# Brief — freeze the convergence migrations' targets

**Branch:** `fix/convergence-migration-target-freeze`
**Base:** `staging`
**Blocks:** `feat/executive-director-role` (rebases onto this; do not push ED until this merges)
**Author:** advisor, 2026-08-04
**Status:** the defect below is mine. I have carried the ticket since before the ED branch and then
briefed a change whose entire shape is "move grants in the map." The six red arms are its bill.

---

## 1. The defect, in one sentence

A convergence migration is a fixed historical act, but all four shipped ones compute their **target**
from `RbacSeeder::grantsMap()` **at run time** while freezing their **governed role set** as a literal
— so the two halves move in opposite directions, and every later map edit silently rewrites what an
already-shipped migration does on replay.

Two failure modes fall out of that, and the ED branch triggers both.

**Failure mode A — the migration changes identity.** `2026_08_05` was authored to *grant*
`finance.access` to `head_of_school`. Replayed against today's map — where HoS has no finance grants —
it *revokes* it. Same filename, same batch row, opposite act. Every replay path hits this:
`migrate:fresh`, `migrate:refresh`, a restored backup, and the release gate's rollback-and-re-up.

**Failure mode B — the migration bricks.** The offender pre-flights abort when a global role *outside*
the frozen governed set holds a governed permission. `executive_director` holds five of them by
design. So on any seeded database, `migrate` now dies — and it dies earlier than anyone has said:

| order | migration | governed set | trips on ED? |
|---|---|---|---|
| 1 | `2026_08_02_100000_realign_finance_governance_grants` | principal, head_of_school (allow-list of 5) | **yes — aborts first** |
| 2 | `2026_08_03_100000_converge_finance_change_grants` | 5 finance roles | yes |
| 3 | `2026_08_04_100000_revoke_internal_auditor_cross_school` | internal_auditor | no (different permission) |
| 4 | `2026_08_05_100000_converge_finance_access_grants` | 6 finance roles | yes |

`2026_08_02` is the first to abort and **it has no test file at all** — no test in `tests/` requires
it. That is why the suite reports six red arms in two files and says nothing about the migration that
actually stops `migrate` first. An unarmed migration is not a passing migration; it is an unwatched
one.

---

## 2. Evidence (run by me, on `feat/executive-director-role` @ `3e43aad`)

The map slices for the governed roles, at each migration's **adding commit** and at branch HEAD:

```
git log --diff-filter=A --format='%H %ad %s' --date=short -- database/migrations/<file>
git show <sha>:database/seeders/RbacSeeder.php \
  | awk "/public static function grantsMap/,/^    \}$/" \
  | grep -E "^            '[a-z_]+' => \[|FINANCE_ACCESS->|FEE_SCHEDULE_CHANGE_|DISCOUNT_POLICY_CHANGE_|ACTIVITY_LOG_VIEW_CROSS_SCHOOL"
```

Adding commits:

| file | added by | date |
|---|---|---|
| `2026_08_02_100000_realign_finance_governance_grants.php` | `f143b40363724a1262420b53c5aadfae1c3b83f1` | 2026-08-01 |
| `2026_08_03_100000_converge_finance_change_grants.php` | `01fdeda876c88f91f8f362a24d475afd0d03de75` | 2026-08-02 |
| `2026_08_04_100000_revoke_internal_auditor_cross_school.php` | `4d4c9c51db7850f9851f8f65319829f2fb07d2b1` | 2026-08-02 |
| `2026_08_05_100000_converge_finance_access_grants.php` | `af9db7ac395bb5891d99e4392e7af0b69092be4f` | 2026-08-03 |

At all four commits the relevant slices are **identical**: `head_of_school` holds `finance.access` plus
the four `*.change.approve/.reject`; `principal` holds `finance.access` only; `accounts_officer` holds
`finance.access` + both `*.change.submit`; `accounts_supervisor` holds `finance.access` +
`fee-schedule.change.submit`; `finance_lead` holds `finance.access` + `discount-policy.change.submit`;
`admin` holds `finance.access`. `internal_auditor` holds `activity_log.view_cross_school` at `f143b40`
and not at `4d4c9c5`.

At HEAD, `head_of_school` holds **nothing** in `finance.` and `executive_director` holds
`finance.access` + the four `*.change.approve/.reject`. That is the whole delta, and it is enough.

Permission string values, verified at `app/Enums/Permission.php:21,84,130-132,139-141`.

Everything above is read from the repo. I have **not** executed any of these migrations — there is no
PHP on the machine I can reach the database from. The abort predictions in §1 are reasoned from code
and are the first thing you must bite-prove (§6, step 0).

---

## 3. The rule this branch installs

> **A migration is a dated act, not a live query.** A migration that writes grants carries its target
> as a frozen literal, dated and attributed to the commit that added it. It never reads
> `RbacSeeder::grantsMap()`.

and its corollary, which is the part that is easy to get wrong:

> **A convergence migration aborts only on a condition its own writes would create. Every other
> surprise it reports and continues past.**

The only condition that qualifies today is `2026_08_03`'s post-write user-scoped duty-separation walk:
that migration's own grant is what puts a user on both sides of a pair, so it must throw and roll
back. Everything else — a permission row that no longer exists, a governed role that no longer exists,
a non-governed role that now holds the permission — is *the world moving on*. The migration cannot
touch a non-governed role, so an "offender" is information, never danger. Aborting on it converts a
harmless surprise into a permanent brick on every future `migrate:fresh`.

The cost of this corollary is real and I am not going to pretend otherwise: an environment that
genuinely has not run `rbac:sync` will now get a loud `SKIPPED:` line instead of a hard stop, and an
operator who ignores it under-converges. That is the trade. A stop that fires correctly once and
incorrectly forever is worse than a report that has to be read.

---

## 4. What changes, file by file

Each migration replaces its `$map = RbacSeeder::grantsMap(); … $target[…] = …` block with a frozen
`private const TARGET` of **plain strings** — not `PermissionEnum::` constants. An enum case can be
renamed or deleted; a frozen historical act must not depend on today's enum any more than on today's
map. Above each const, a comment naming the adding commit SHA and the date, and saying that the values
were transcribed from `git show <sha>:database/seeders/RbacSeeder.php`.

### 4.1 `2026_08_02_100000_realign_finance_governance_grants.php` — frozen at `f143b40`, 2026-08-01

```php
private const TARGET = [
    'principal' => [],
    'head_of_school' => [
        'finance.discount-policy.change.approve',
        'finance.discount-policy.change.reject',
        'finance.fee-schedule.change.approve',
        'finance.fee-schedule.change.reject',
    ],
];
```

Also: pre-flight 1's `$allowed` list (currently five hardcoded role names) becomes a **report**, not an
abort. Keep the holder counts in the message — they are the useful part.

### 4.2 `2026_08_03_100000_converge_finance_change_grants.php` — frozen at `01fdeda`, 2026-08-02

```php
private const TARGET = [
    'principal' => [],
    'head_of_school' => [
        'finance.discount-policy.change.approve',
        'finance.discount-policy.change.reject',
        'finance.fee-schedule.change.approve',
        'finance.fee-schedule.change.reject',
    ],
    'accounts_officer' => [
        'finance.discount-policy.change.submit',
        'finance.fee-schedule.change.submit',
    ],
    'accounts_supervisor' => [
        'finance.fee-schedule.change.submit',
    ],
    'finance_lead' => [
        'finance.discount-policy.change.submit',
    ],
];
```

Offender pre-flight → report. **Keep the post-write user-scoped duty-separation walk exactly as it is,
including the throw and the rollback.** It is the one abort the rule permits, and its docblock
paragraph (`:61-76`) is the best-argued thing in either file. Do not weaken it.

Note the `$sixNames` DB query stays — it derives the *namespace membership* from the permissions
table, which is a fact about the substrate, not a decision. Only `grantsMap()` goes.

### 4.3 `2026_08_04_100000_revoke_internal_auditor_cross_school.php` — frozen at `4d4c9c5`, 2026-08-02

```php
private const TARGET = ['internal_auditor' => []];
```

The `grantsMap()` read here is not a target derivation — it is the assertion at `:90-103` that the map
*no longer* offers the permission, aborting if it does. Delete the abort. Whether today's map re-grants
it is `rbac:diff-grants`'s question, not this migration's. Replace with an echo that reports what the
current map says, so the information survives without the brick.

Its two abort arms move with it: `ARM C` (third-holder pre-flight) and `ARM F` (missing-role
pre-flight) in `tests/Feature/Rbac/InternalAuditorCrossSchoolRevocationTest.php` currently assert a
throw. They become report arms — assert the echo names the offender / the missing role, and assert
**no grant changed and no activity row was written**, which is the property that actually matters.

### 4.4 `2026_08_05_100000_converge_finance_access_grants.php` — frozen at `af9db7a`, 2026-08-03

```php
private const TARGET = [
    'admin' => ['finance.access'],
    'head_of_school' => ['finance.access'],
    'principal' => ['finance.access'],
    'accounts_officer' => ['finance.access'],
    'accounts_supervisor' => ['finance.access'],
    'finance_lead' => ['finance.access'],
];
```

Offender pre-flight → report.

### 4.5 All four — the two remaining aborts become skips

- **target permission row absent** → skip that permission for that role, `echo` a `SKIPPED:` line
  naming it, continue. Count the skips and repeat the count in the `AFTER` report.
- **governed role row absent** → skip that role entirely, `echo` a `SKIPPED:` line, continue. A role
  that does not exist cannot hold a grant that needs converging. (This also closes the carried ticket
  *"`2026_08_05`'s governed-role-missing abort left unarmed"* — it is now an armed skip.)

Delete the docblock sentences that advertise the old behaviour as a virtue. Every one of the four says
some version of *"the target is DERIVED from `grantsMap()` and never hardcoded"*; `2026_08_05:31-33`
and `2026_08_03:31` state the split explicitly — *"The role SET is written out here … the GRANTS are
derived"* — which is exactly the defect, written down and mistaken for a design. Replace it with the
rule from §3 and a pointer to the ADR.

---

## 5. The gate

A rule without a gate is wallpaper. Add
`tests/Feature/Rbac/MigrationsDoNotReadTheSeederMapTest.php`:

- glob `database/migrations/*.php`; assert **no** file contains `grantsMap`;
- assert the glob matched **more than 100 files** first — a scan of zero files is green and blind, and
  that is the failure mode this test must not have;
- name the four files in the test's header comment as the ones this branch converted, so a reader
  knows the rule is retroactive and not just forward-looking.

Repo-wide and unconditional, so it runs under `bin/quality`'s suite step on every push (step 13 when
this was written; the suite is the LAST step, whatever its number). It deliberately does
**not** go into `bin/ci-grants-convergence-lint.php` (the grants-convergence lint step): that gate is diff-based and reads only
files the branch *adds*, so a migration already on the base would be invisible to it. This invariant is
about files that already exist.

**Acknowledged remainder, ticketed not worked.** `grep -rhoE "RbacSeeder::[A-Za-z_]+|PermissionEnum::[A-Za-z_]+" database/migrations/`
returns `GUARD`×27, `grantsMap`×7, `sync`×6, `ROLES`×2, `ISOLATION_CROSSING`×2,
`SUPER_ADMIN_PLATFORM`×1, `FINANCE_ACCESS`×1. `::sync` in a migration is the extreme form of the same
defect — a migration that re-runs the seeder re-shapes itself completely. `::ROLES`,
`::SUPER_ADMIN_PLATFORM` and the two `PermissionEnum` references are the same class, milder. The line
is drawn at `grantsMap` for one reason: it is the only one whose value is a *business decision* that
changes every time Brookstone changes their mind, and it is the only one that has actually bitten.
Raise the rest as tickets in your report; do not widen this branch.

---

## 6. Arms — what must be red before and green after

**Step 0, before you change anything.** Bite-prove §1's failure mode B, because it is my prediction and
not yet a fact. On `feat/executive-director-role`, in a scratch test that seeds `DatabaseSeeder` and
then `require`s `2026_08_02_100000_realign_finance_governance_grants.php` and calls `up()`, capture the
abort. Paste it raw. If it does **not** abort, stop and tell me — my §1 ordering claim is wrong and the
rest of this brief needs re-reading before you spend a day on it.

Then, on the fix branch:

1. **`2026_08_02` gets a test file at last.** It has none. Minimum arms, in the shape of its siblings:
   converges the drift; idempotent; the offender case now *reports and continues*; a missing governed
   role is skipped, reported, and changes nothing; and a bite-proof arm that the planted drift is real.

2. **The six currently-red arms go green because the contract they assert changed, not because they
   were pinned.** Concretely:
   - `FinanceChangeGrantConvergenceTest` ARM 1 currently asserts *"leaves principal/head_of_school
     untouched"*, which was a claim about the live map. Under the freeze the honest assertion is
     stronger: after `up()`, **every** governed role's namespace slice equals the frozen literal —
     `principal` `[]`, `head_of_school` the four approve/reject, and so on. Write those four permission
     names out **in the test as literals**. If the test reads the migration's own const, it proves
     nothing; two copies of the literal is the point.
   - ARM 2 (idempotency) then follows from ARM 1's end state.
   - ARM 4 (user-scoped walk) keeps its throw — that abort survives §3. It goes green once the frozen
     target restores `head_of_school`'s checker side, which is what makes the both-sides state
     reachable again.
   - `FinanceAccessGrantConvergenceTest` ARM 4 currently asserts a fresh seed leaves `head_of_school`
     holding `finance.access`. It does not, and will not again. Rewrite it to prove **both** drift
     shapes at once: `principal` still holds it after a seed, so revoke it and prove the planting is
     real; `head_of_school` does **not** hold it after a seed, so it is a live map-divergence needing
     no planting. Then ARM 1 converges both. This arm gets stronger, not weaker.
   - ARM 3 in both files is currently green **by accident** — it asserts `toThrow(…, 'rogue_finance')`
     and the message happens to name `executive_director` too. Under §3 it must not throw at all.
     Rewrite it as a report arm and assert the counts are unmoved.

3. **Each abort-to-report conversion carries its own arm**, and each is mutation-checked: put the
   `throw` back and the arm must go red. An arm that stays green under the revert is wallpaper — see
   what happened to `MoveHosFinanceToEdConvergenceTest`'s first ARM 3.

4. **The gate is mutation-checked.** Re-add a `RbacSeeder::grantsMap()` call to one migration; the new
   test must go red naming that file. Paste both runs.

5. **Nothing in the `AFTER` reports may name a person or a school.** `user#<id>`, `school#<id>`,
   permission names and counts only. Same rule as everything else on this project.

---

## 7. ADR

Add `docs/adr/0052-a-migration-is-a-dated-act.md` (0051 is the highest today). It records §3's rule and
its corollary, the four files converted, the trade named at the end of §3, and the remainder from §5.
Reference it from the four migrations' docblocks and from the new gate test's header.

---

## 8. What NOT to do

- **Do not touch `2026_08_06_100000_move_head_of_school_finance_to_executive_director.php`.** It lives
  on the ED branch, not on this one. It carries the identical defect and a docblock that advertises it
  (`:78` — *"target DERIVED from `grantsMap()` and never hardcoded"*). It gets the same treatment
  **after** the rebase, as part of the ED branch, and the new gate in §5 will force it: ED cannot go
  green until it is frozen too. Frozen at ED's own commit, its target is `head_of_school => []`,
  `accounts_supervisor => ['finance.access', 'finance.fee-schedule.change.submit']`,
  `executive_director =>` its nine.
- **Do not delete the two migrations.** They have applied on the live copy and on `staging`; deleting
  them is a no-op there and a silent hole on any environment that has not run them.
- **Do not "fix" this by widening the governed sets to include `executive_director`.** That makes a
  2026-08-01 migration reach forward to a 2026-08-04 decision, which is the defect wearing a different
  hat.
- **Do not squash the four migrations into one.** Applied history is not editable.
- **Do not push.** Report first.

---

## 9. Sequencing

1. This branch → `bin/quality` 13/13 → report → my review → merge to `staging`.
2. Rebase `feat/executive-director-role` onto it.
3. On ED, freeze `2026_08_06` (§8) — the gate will demand it.
4. ED → `bin/quality` 13/13 → push.

`finance:check-staffing-readiness` stays red with 16 GAPs throughout. That is correct and expected: no
user holds `executive_director` yet. It is not a step in `bin/quality` and must not be made one on this
branch.
