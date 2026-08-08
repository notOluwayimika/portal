# Implementation report — `feat/executive-director-role`, pre-merge remediation

Base: `origin/staging` @ `6890edb`. Branch: `feat/executive-director-role`.
Merge commit `81bb0ac`, then `24a8909` (ADR), `6756596` (oracle), `db68a6f` (test fix).
Not pushed. Not merged.

---

## Headline

**Done with one item withdrawn by the project lead mid-task, and one defect the merge surfaced.**
Staging is merged in (45 commits, one conflict, in `docs/adr/0052`); item 1 is ruled **(a) keep the
edit**, with the replay evidence that decides it and an argued carve-out written into ADR 0052's
corollary; item 3 is proved and the ordering is **not** relied on; item 4 re-derived all three
oracles from clean throwaway databases and caught one entry the auto-merge did not carry. Item 2 was
**withdrawn** — see below. The first `bin/quality` run failed the ratchet on two arms of a test that
arrived with the merge (`DutySeparationBaselineTest` ARM 3 and ARM 4) — real, reproducible, and
fixed in `db68a6f`; bite-proving that fix found the arm was additionally passing for the wrong
reason.

**This is full-review tier** — it touches an applied migration, RBAC grants, and two fixture
oracles. Subagent review attached; recommend a cold session before merge.

## Deviations from the brief

**Item 2 was withdrawn by the project lead after I reported its premise was false.** The brief said
staging now has `finance.opening-balance.approve/.reject` and asked me to add them to
`2026_08_06_100000`'s `TARGET` and to the seeder's `executive_director` grants, removing them from
`head_of_school`. None of that is possible or safe on this tree:

- The two permissions do **not** exist on `origin/staging` or on this merged branch. `grep -rn
"opening-balance" app/Enums/Permission.php` returns nothing; a repo-wide grep for the literal
  finds only opening-balance _tables_ and _spec prose_. They exist only on
  `feat/finance-ob-approval-gate` @ `911adc2` (unmerged), at `app/Enums/Permission.php:159-160`,
  where `database/seeders/RbacSeeder.php:240-241` grants them to `head_of_school`.
- So there is nothing here to remove from `head_of_school`, and no enum case to add to
  `executive_director`'s slice — the seeder edit would not compile.
- Adding the two strings to `2026_08_06`'s `TARGET` would make its missing-permission pre-flight
  (`database/migrations/2026_08_06_100000_move_head_of_school_finance_to_executive_director.php:182-187`)
  throw `move-hos-finance-to-ed ABORTED: target permission(s) absent from the permissions table —
run 'php artisan rbac:sync' first` on every environment until 4c merges. `rbac:sync` cannot create
  a permission absent from the enum, so that abort has **no exit**. Under ADR 0052's two-part test
  that is part 1 YES / part 2 NO: _do not convert and do not leave it — escalate._

**The lead's ruling, recorded so a later reader does not re-open the gap:** the fix belongs on the
4c branch, moving `finance.opening-balance.approve/.reject` from `head_of_school` to
`executive_director` in `RbacSeeder`. They are **new** permissions, so convergence-lint exemption 1
applies correctly and `rbac:sync` grants them per `grantsMap()` on every environment. The **map is
the whole mechanism**. No migration is needed, and none is possible while the enum cases live only
on the 4c branch. `2026_08_06` and its `TARGET` were left untouched.

**No other deviations.**

## Contradictions of the premise

Two, both in item 1's framing, and both change the answer rather than decorating it.

**1. The from-zero replay the brief asked for does not reach the walk at all.** On `migrate`
against an empty database, `2026_08_03`'s fresh-install guard
(`database/migrations/2026_08_03_100000_converge_finance_change_grants.php:128-135`, keyed on the
seeder-owned permission substrate) returns before anything else runs — identically on the pre-edit
and post-edit files. A from-zero replay that does not abort is therefore **not evidence the abort
is harmless**, and taken alone it would have produced the wrong ruling. The proof below seeds the
substrate with `rbac:sync` and clears the `migrations` row, which is what makes the walk reachable.

**2. The brief's option (b) — "revert the edit on `2026_08_03` and carry the narrowing in
`2026_08_06_100000`, which has never been applied" — is not available.** `2026_08_06` has its own
walk over its own `$grantedThisRun`; narrowing it does nothing to `2026_08_03`'s abort predicate.
No later migration can stop an earlier migration's `up()` from throwing on the next replay. So the
choice was never (a) or (b); it was _edit the applied file, or leave it unreplayable_. That is what
makes the carve-out a carve-out rather than a shortcut, and it is stated as such in the ADR.

## What changed

| file                                                | ±                      | what                                                                                                                                                                                                                            |
| --------------------------------------------------- | ---------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `docs/adr/0052-a-migration-is-a-dated-act.md`       | conflict resolved + 62 | merge kept **both** new sections (the branch's `DutySeparation` boundary and staging's applied-migration corollary); then the argued carve-out for `2026_08_03`, with the replay evidence and the four conditions that scope it |
| `tests/fixtures/route-access-map.json`              | +20                    | re-derived; gains `POST /api/notifications/ses-events`                                                                                                                                                                          |
| `tests/Feature/Rbac/DutySeparationBaselineTest.php` | +30 −9                 | ARM 3 / ARM 4 re-pointed at `executive_director` as the checker seat; ARM 3's baseline derived from the current finding set so it is precise again; ARM 4's counts made to line up                                              |

Nothing else. In particular: **no migration file was edited in this session**, and
`phpstan-baseline.neon` and `tests/ratchet-baseline.txt` were not touched.

```text
$ git show --name-status --format='%s' 81bb0ac | head -5
Merge remote-tracking branch 'origin/staging' into feat/executive-director-role

$ git show --name-status --format='%s' 24a8909
docs(adr): argue the carve-out for editing 2026_08_03 after it had applied
M	docs/adr/0052-a-migration-is-a-dated-act.md

$ git show --name-status --format='%s' 6756596
chore(rbac): re-derive the fixture oracles after the staging merge
M	tests/fixtures/route-access-map.json

$ git show --name-status --format='%s' db68a6f
fix(rbac): re-point the duty-separation baseline arms at the seat that holds the checker side
M	tests/Feature/Rbac/DutySeparationBaselineTest.php
```

### The 2026_08_06 TARGET, unchanged

Item 2 was withdrawn, so the TARGET the brief asked me to edit is exactly as `5236242` froze it, and
`2026_08_03` is exactly as `17da5c3` left it:

```console
$ git diff --quiet 17da5c3..HEAD -- database/migrations/2026_08_06_100000_move_head_of_school_finance_to_executive_director.php \
    && echo "08_06: IDENTICAL to 17da5c3" || echo "08_06: DIFFERS"
08_06: IDENTICAL to 17da5c3

$ git diff --quiet 17da5c3..HEAD -- database/migrations/2026_08_03_100000_converge_finance_change_grants.php \
    && echo "08_03: IDENTICAL to 17da5c3" || echo "08_03: DIFFERS"
08_03: IDENTICAL to 17da5c3
```

Every migration difference between `17da5c3` and `HEAD` is an **addition** carried in by the merge —
seven files, all `A`, none `M`:

```console
$ git diff 17da5c3..HEAD --name-status -- database/migrations/
A	database/migrations/2026_08_05_120000_create_notification_suppressions.php
A	database/migrations/2026_08_06_100000_create_finance_opening_balance_tables.php
A	database/migrations/2026_08_07_100000_add_file_row_count_to_opening_balance_batches.php
A	database/migrations/2026_08_07_110000_add_provenance_to_finance_payments.php
A	database/migrations/2026_08_08_100000_realign_opening_balance_staging_for_per_fee_type_file.php
A	database/migrations/2026_08_08_110000_opening_balance_posting_state_and_guards.php
A	database/migrations/2026_08_08_120000_opening_balance_posted_rows_are_terminal.php
```

## Item 1 — the ruling

**(a) Keep the edit, and add the carve-out to ADR 0052's corollary.** Written at
`docs/adr/0052-a-migration-is-a-dated-act.md`, section _"The carve-out: `2026_08_03`, edited after
it had already applied"_.

### The diff the brief asked me to verify

```text
$ mb=$(git merge-base origin/staging feat/executive-director-role)   # 806f8f7
$ f=database/migrations/2026_08_03_100000_converge_finance_change_grants.php
$ diff <(git show $mb:$f | sed -n '/^return new class/,$p') <(sed -n '/^return new class/,$p' $f)
138c138
<         $this->report('BEFORE', $sixNames, $skipped);
---
>         $this->report('BEFORE', $sixNames, $skipped, 0);
149c149,155
<         DB::transaction(function () use ($roles, $target, $inNs) {
---
>         $outOfScope = 0;
>
>         DB::transaction(function () use ($roles, $target, $inNs, &$outOfScope) {
[...]
189,190c211,212
<                         if ($isFinance) {
<                             $bothSidesUsers[] = "user#{$user->id} @ school#{$school->id} ...";
---
>                         if (! $isFinance) {
>                             continue;
[...]
>                         $thisRunWroteASide = in_array($pair['maker'], $grantedThisRun, true)
>                             || in_array($pair['checker'], $grantedThisRun, true);
[...]
209c254
<         $this->report('AFTER', $sixNames, $skipped);
---
>         $this->report('AFTER', $sixNames, $skipped, $outOfScope);
```

Confirmed: the brief is right that `17da5c3` edits the **executing** half of an applied migration —
the walk's scope, an `$outOfScope` counter, and `report()`'s new argument. This is not the
freeze ADR 0052 sanctioned.

### The counter-argument, tested

**Setup, on a from-zero throwaway database (`portal_replay_test`), for both file versions:**

1. drop/create the database, `migrate --force` from zero;
2. `rbac:sync` — seeds the permission substrate at today's map;
3. plant `school#1` and `user#2`, holding `executive_director` **and** a bespoke role granting only
   `finance.credit-note.submit` (both inserted raw into `model_has_roles`: grant-time enforcement
   refuses the pairing through the spatie API, which is exactly why a migration is the only thing
   that can meet it already in place);
4. delete `2026_08_03`'s `migrations` row;
5. `migrate --force` — the migration replays for real.

Neither `finance.credit-note.submit` nor `finance.credit-note.approve/.reject` is in either
namespace this migration governs, and neither of the two roles is one it can touch.

**PRE-EDIT file (merge-base `806f8f7`) — ABORTS. `migrate` exit 1.**

```text
 INFO Running migrations.

 2026_08_03_100000_converge_finance_change_grants   converge-finance-change-grants REPORT: global role(s) outside this migration's scope also grant the governed permissions: executive_director (holders=1). Not an error — this migration governs principal, head_of_school, accounts_officer, accounts_supervisor, finance_lead only and cannot touch them.
  converge-finance-change-grants: school-scoped role rows carrying any of the six (UNTOUCHED): 0
  converge-finance-change-grants [BEFORE] holders per school per governed permission (skipped=0):
    school#1  finance.discount-policy.change.approve  holders=1
    school#1  finance.discount-policy.change.reject  holders=1
    school#1  finance.discount-policy.change.submit  holders=0
    school#1  finance.fee-schedule.change.approve  holders=1
    school#1  finance.fee-schedule.change.reject  holders=1
    school#1  finance.fee-schedule.change.submit  holders=0
.. 155.33ms FAIL

   RuntimeException

  converge-finance-change-grants ABORTED (rolled back): 2 user(s) would hold both sides of a finance maker-checker pair after convergence — user#2 @ school#1 finance.credit-note.submit<>finance.credit-note.approve; user#2 @ school#1 finance.credit-note.submit<>finance.credit-note.reject. Reassign one of the two roles for each listed user, then re-run the migration.

  at database/migrations/2026_08_03_100000_converge_finance_change_grants.php:276
```

Post-state: `head_of_school` finance-change grants **0** (rolled back), `migrations` row for
`2026_08_03` **absent**. The ADD-side gap the migration exists to close stays open, and no
`migrate` command can close it.

**POST-EDIT file (branch `17da5c3`) — REPORTS and COMMITS. `migrate` exit 0.**

```text
 2026_08_03_100000_converge_finance_change_grants   converge-finance-change-grants REPORT: global role(s) outside this migration's scope also grant the governed permissions: executive_director (holders=1). Not an error — this migration governs principal, head_of_school, accounts_officer, accounts_supervisor, finance_lead only and cannot touch them.
  converge-finance-change-grants: school-scoped role rows carrying any of the six (UNTOUCHED): 0
  converge-finance-change-grants [BEFORE] holders per school per governed permission (skipped=0, out-of-scope both-sides findings=0):
    school#1  finance.discount-policy.change.approve  holders=1
    school#1  finance.discount-policy.change.reject  holders=1
    school#1  finance.discount-policy.change.submit  holders=0
    school#1  finance.fee-schedule.change.approve  holders=1
    school#1  finance.fee-schedule.change.reject  holders=1
    school#1  finance.fee-schedule.change.submit  holders=0
  converge-finance-change-grants REPORT: 2 both-sides finding(s) this run did NOT create — not blocked on:
    user#2 @ school#1 finance.credit-note.submit<>finance.credit-note.approve
    user#2 @ school#1 finance.credit-note.submit<>finance.credit-note.reject
    These are real and they matter. They belong to `php artisan finance:audit-duty-separation`, not to a migration.
  converge-finance-change-grants [AFTER] holders per school per governed permission (skipped=0, out-of-scope both-sides findings=2):
    school#1  finance.discount-policy.change.approve  holders=1
    school#1  finance.discount-policy.change.reject  holders=1
    school#1  finance.discount-policy.change.submit  holders=0
    school#1  finance.fee-schedule.change.approve  holders=1
    school#1  finance.fee-schedule.change.reject  holders=1
    school#1  finance.fee-schedule.change.submit  holders=0
.. 171.93ms DONE
```

Post-state: `head_of_school` finance-change grants **4** (the frozen target, granted), `migrations`
row **present**.

**And the pair replayed together, in filename order** — `migrations` rows for both `2026_08_03` and
`2026_08_06` cleared, then `migrate --force`:

```text
exit=0
  move-hos-finance-to-ed REPORT: 2 both-sides finding(s) this run did NOT create — not blocked on:
HoS finance.* after replaying BOTH: 0
AO fee-schedule.change.submit: yes
```

The dated act, reproduced: `head_of_school` ends with zero `finance.*` grants and
`accounts_officer` keeps the maker side `2026_08_03` exists to give it. `2026_08_03`'s intermediate
re-grant to `head_of_school` is stripped again by `2026_08_06`, which sorts after it.

### The from-zero path, for completeness — it decides nothing

```text
$ env DB_DATABASE=portal_replay_test php artisan migrate --force
 2026_08_03_100000_converge_finance_change_grants   converge-finance-change-grants: finance RBAC substrate unseeded (no finance-change permissions) — nothing to converge.
```

Identical on both file versions. The walk is unreachable; the guard returns first.

### Why the edit stands

Four conditions, all of which hold here and all of which are written into the ADR as the scope of
the carve-out:

1. **`down()` is a documented no-op** (`:339-342`), so the `up()`/`down()` shape divergence the
   corollary was written from cannot arise, and "roll it back first" is a tautology.
2. **The edit is behaviour-identical on the state the original run met.** The out-of-scope set was
   empty on 2026-08-02 — it had to be, or that run would have aborted instead of committing. With
   it empty the two versions are the same program: same diff, same writes, same activity rows,
   differing only by an `out-of-scope=0` field in one echo. Same property that made the four target
   freezes behaviour-preserving.
3. **The file is otherwise unreplayable** — proved above, raw.
4. **No new dated migration could carry the change** — nothing a later file writes stops
   `2026_08_03::up()` throwing.

`2026_08_06` is edited on the same branch by the same commit and needs no carve-out: it has never
merged, so it has applied nowhere except possibly a local database — and its `down()` is likewise a
documented no-op, so the same four conditions cover it. **If it has been applied to a local database
on this branch, that is worth checking before merge**; I could not check the lead's machine.

## Item 3 — the timestamp collision

```text
$ env DB_DATABASE=portal_replay_test php artisan migrate --force
 2026_08_05_120000_create_notification_suppressions .. 40.60ms DONE
 2026_08_06_100000_create_finance_opening_balance_tables .. 264.00ms DONE
 2026_08_06_100000_move_head_of_school_finance_to_executive_director   move-hos-finance-to-ed: finance RBAC substrate unseeded (no finance.* permissions) — nothing to converge.
 0.93ms DONE
 2026_08_07_100000_add_file_row_count_to_opening_balance_batches  13.33ms DONE
```

`create_finance_opening_balance_tables` runs first. It is not luck and it is not chance: Laravel
keys migrations by basename and `sortBy`s on that key
(`vendor/laravel/framework/src/Illuminate/Database/Migrations/Migrator.php:578-586`), so a shared
timestamp prefix falls through to a **deterministic** string comparison of the descriptive suffix —
`create_…` < `move_…`. What nobody chose is that the resulting order is the convenient one.

**Am I relying on the ordering? No.** The two migrations are independent:
`create_finance_opening_balance_tables` creates tables and touches no role, permission or grant;
`move_head_of_school_finance_to_executive_director` reads and writes `roles`,
`role_has_permissions`, `permissions`, `model_has_roles`, `schools` and `users` and creates no
table. Neither reads anything the other writes, so there is no unsafe order for them to fall into.
The ordering is deterministic **and** irrelevant — I am recording it rather than depending on it.

## Item 4 — how I re-derived the oracles

The 45-commit merge produced **no conflict in any fixture**, which is worse than a conflict:
nothing asked anyone to look. So I re-derived all three from scratch rather than trusting the
auto-merge, in the documented order, and diffed re-derived against auto-merged.

Two throwaway databases, neither of them the dev copy and neither of them `portal_testing`:

```bash
# A — route oracles.  portal_oracle_test
php artisan tinker --execute="DB::statement('DROP DATABASE IF EXISTS `portal_oracle_test`');
                              DB::statement('CREATE DATABASE `portal_oracle_test`
                                             CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');"
env DB_DATABASE=portal_oracle_test php artisan migrate --force
env DB_DATABASE=portal_oracle_test php artisan rbac:sync
env DB_DATABASE=portal_oracle_test php artisan rbac:derive-access   # route-access-map.json (360 routes)
env DB_DATABASE=portal_oracle_test php artisan rbac:derive-map      # route-middleware-baseline.json (360 routes)

# B — grants baseline.  portal_grants_test.  No command exists; produced with the EXACT expression
#     PermissionEnumTest.php:30-41 asserts against, so the fixture cannot drift from its own oracle.
env DB_DATABASE=portal_grants_test php artisan migrate --force
env DB_DATABASE=portal_grants_test php artisan db:seed --class=Database\\Seeders\\DatabaseSeeder --force
env DB_DATABASE=portal_grants_test php artisan tinker --execute="
\$webRoles = App\Models\Role::with('permissions')->where('guard_name','web')->get();
if (\$webRoles->pluck('name')->duplicates()->isNotEmpty()) { throw new RuntimeException('duplicate web-guard role names'); }
\$actual = \$webRoles->mapWithKeys(fn (\$r) => [\$r->name => \$r->permissions->pluck('name')->sort()->values()->all()])->sortKeys()->all();
file_put_contents(base_path('tests/fixtures/rbac-grants-baseline.json'),
    json_encode(\$actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);"
```

Separate databases on purpose: `rbac:sync` and `DatabaseSeeder` are different entry points into the
same map, and running one after the other on a shared database would make it impossible to say
which produced the fixture.

**Result — re-derived vs auto-merged:**

| fixture                          | diff lines    | verdict                            |
| -------------------------------- | ------------- | ---------------------------------- |
| `rbac-grants-baseline.json`      | 0             | auto-merge was correct             |
| `route-middleware-baseline.json` | 0             | auto-merge was correct             |
| `route-access-map.json`          | 20 (+1 entry) | auto-merge was **short one route** |

The missing entry:

```diff
     "POST /api/notifications/seen": {
         "auth": true,
+        "roles": [ ...15 roles... ]
+    },
+    "POST /api/notifications/ses-events": {
+        "auth": false,
         "roles": [ ...15 roles... ]
```

`POST /api/notifications/ses-events` is staging's SES webhook
(`routes/endpoints/notifications.php:72-74`, `SesEventController`). It is absent from **staging's
own committed fixture** — 350 entries there against 360 routes live post-merge. No gate would have
demanded it: `tests/Feature/Rbac/RouteAccessParityTest.php:17-22` documents the asymmetry — only
fixture routes are asserted, so a new route is never blocked there. That is deliberate design, not
a defect; the consequence is that the oracle only ever gains entries when someone regenerates it,
which is what this commit does.

## Database observations

Under the privacy rule — ids, counts, structure only.

Seeded finance grants after the merge (`portal_grants_test`, `DatabaseSeeder`, global rows):

| role                  | `finance.*` grants                                         |
| --------------------- | ---------------------------------------------------------- |
| `head_of_school`      | 0                                                          |
| `executive_director`  | 9                                                          |
| `accounts_supervisor` | 2 (`finance.access`, `finance.fee-schedule.change.submit`) |
| `principal`           | 1 (`finance.access`)                                       |

The merge preserved the branch's intent exactly: HoS holds no finance, ED holds all four finance
checker pairs plus `finance.access`, and `principal` keeps `finance.access` as the 2026-08-04
decision requires.

Replay database (`portal_replay_test`): 1 school, 2 users, 1 bespoke role. No production or dev
database was written to at any point — the dev copy `portaa10_portal` was never the target of a
`migrate`, a `rbac:sync` or a seeder in this session.

## Proof — `bin/quality`

Run against base `6890edb` (`origin/staging`). **Run 1 failed** at step 14 — that output is in the
`DutySeparationBaselineTest` section above. **Run 2, after `db68a6f`, raw:**

```text
quality gate — base 6890edb

[1/14] dependency integrity (composer.lock vs composer.json vs vendor/)
   ✓ dependency-integrity-lint
[2/14] wayfinder:generate --with-form (must match vite.config.ts formVariants)
   ✓ wayfinder:generate
[3/14] lint changed files (Pint / Prettier / ESLint, check mode)
   ✓ lint-changed
[4/14] types (tsc ratchet vs tsc-baseline)
   ✓ tsc-ratchet
[5/14] frontend build (vite — catches what the tsc ratchet structurally cannot)
   ✓ build
[6/14] authorization guard (no new commented-out checks)
   ✓ authz-lint
[7/14] boundary lint (§17.2)
   ✓ boundary-lint
[8/14] grants-convergence lint (a pre-existing permission added to grantsMap() ships a migration)
   ✓ grants-convergence-lint
[9/14] money lint (UI: money via formatNaira, no JS money math)
   ✓ money-lint
[10/14] runtime-zero lint (S7 legacy access sources)
   ✓ runtime-zero-lint
[11/14] identifier-generation bypass guard (1.4b)
   ✓ identifier-generation-lint
[12/14] architecture tests (§17.1)
   ✓ arch
[13/14] static analysis (Larastan level 5 vs baseline)
   ✓ larastan
[14/14] tests (failure ratchet vs tests/ratchet-baseline.txt)
   ✓ test-ratchet

✓ quality: PASS — per-push floor. Promoting to main? run bin/quality-promote.
QUALITY EXIT=0
```

Two caveats on what that green covers, neither of them new to this branch. Step 14 is the failure
**ratchet**, not a clean suite — pre-existing failures in `tests/ratchet-baseline.txt` are frozen and
I did not touch that file. And `bin/quality-clean-db` (throwaway DB, migrate-from-zero against data,
rollback/re-up) is part of `bin/quality-promote`, not of the per-push floor; it was **not** run here.
The from-zero migrations in this report were run by hand, not through that script.

## The defect the merge surfaced — `DutySeparationBaselineTest`

The first `bin/quality` run failed at step 14:

```text
[14/14] tests (failure ratchet vs tests/ratchet-baseline.txt)
   ✗ test-ratchet

       ratchet: 2 NEW test failure(s) not in the baseline (regression):
         ✗ tests/Feature/Rbac/DutySeparationBaselineTest.php::it ARM 3 — a FINANCE finding fails even when it IS in the baseline
         ✗ tests/Feature/Rbac/DutySeparationBaselineTest.php::it ARM 4 — the resolved-one-appeared-one case a COUNT ratchet would pass
```

Reproducible in isolation, so not the known cross-test-pollution flake:

```json
{
    "tool": "pest",
    "result": "failed",
    "tests": 8,
    "passed": 6,
    "failed": 2,
    "failures": [
        {
            "test": "...ARM_3...",
            "line": 125,
            "message": "Failed asserting that 0 is identical to 1."
        },
        {
            "test": "...ARM_4...",
            "line": 152,
            "message": "Failed asserting that 0 is identical to 1."
        }
    ]
}
```

**Cause.** The file arrived with the merge (staging's scheduled-detector work) and was authored
against the pre-2026-08-04 map. Both arms plant a finance both-sides holder as `accounts_officer` +
`accounts_supervisor`. The seat move took every finance checker side off `accounts_supervisor`,
which now holds `finance.access` and `finance.fee-schedule.change.submit` only — a maker-only seat.
So the plant produced no both-sides finding, `finance:audit-duty-separation` short-circuited to
SUCCESS, and both arms asserted `1` against `0`. Re-pointed to `accounts_officer` +
`executive_director`, which is where the four finance checker pairs now live.

## The watched red

**Two, both on the test fix. Nothing was planted for items 1, 3 or 4 — see the end of this section.**

### Red 1 — the arms were red before the fix, green after

Pasted above: the ratchet output naming both arms, and the isolated run reproducing it. After
`db68a6f`, on the same command:

```json
{
    "tool": "pest",
    "result": "passed",
    "tests": 8,
    "passed": 8,
    "assertions": 21,
    "duration_ms": 10570
}
```

### Red 2 — the mutation, which found the fix was not yet enough

Re-pointing the plant made both arms green, and **that green was wrong**. Mutation planted in
`app/Console/Commands/AuditDutySeparation.php:140-144`, deleting the hard-coded finance refusal
ARM 3 exists to pin:

```diff
         $financeFindings = collect($findings)
-            ->filter(fn (array $f): bool => str_starts_with($f['checker'], self::NEVER_BASELINEABLE)
-                || str_starts_with($f['maker'], self::NEVER_BASELINEABLE))
+            ->filter(fn (array $f): bool => false) // BITE-PROOF MUTANT
```

**First attempt — ARM 3 stayed GREEN under the mutant:**

```json
{
    "tool": "pest",
    "result": "passed",
    "tests": 2,
    "passed": 2,
    "assertions": 2,
    "duration_ms": 8869
}
```

With the checker sides on `executive_director` the plant produces findings across **all four**
finance pairs, not the two `accounts_supervisor` used to give. ARM 3's carried-over four-line
baseline therefore left four findings unaccepted, and the arm exited 1 through the **ordinary
unaccepted-findings path** — passing while proving nothing about the hard-code. Fixed by deriving
ARM 3's baseline from the current finding set, so every finding is accepted and the refusal is the
only thing left that can fail the run.

**Second attempt — ARM 3 goes RED under the same mutant:**

```json
{
    "tool": "pest",
    "result": "failed",
    "tests": 1,
    "passed": 0,
    "assertions": 3,
    "failures": [
        {
            "test": "...ARM_3_—_a_FINANCE_finding_fails_even_when_it_IS_in_the_baseline",
            "line": 143,
            "message": "Failed asserting that 0 is identical to 1."
        }
    ]
}
```

The message names the right thing: exit 0 where 1 was required, i.e. the baseline silenced a finance
finding. **Restored** — `git status --short app/Console/Commands/AuditDutySeparation.php` empty, and
8/8 green afterwards.

ARM 4 stays green under this mutant (`tests":1,"passed":1`). That is correct and its own MEASURED
note says so: the tuple test refuses it independently, which is the arm's point — two guards, neither
load-bearing alone.

### Item 1's substitute for a planted red

Items 1, 3 and 4 add no guard, so there was none to bite-prove. What stands in for it on item 1 is
stronger than a mutation, because the two states came from two **real files** rather than an edit I
invented: the pre-edit file was checked out over the post-edit one and `migrate` was run for real on
a from-zero database. Exit 1, rolled back; restored, exit 0, committed. Both raw outputs above; the
file was restored byte-for-byte (`git status` clean on that path afterwards).

The arms that pin the narrowing itself — `FinanceChangeGrantConvergenceTest` ARM 7 and
`MoveHosFinanceToEdConvergenceTest` ARM 8, both added by `17da5c3` — were written by the previous
session and I did **not** independently bite-prove them. They pass in the suite run below. Their red
state is unwitnessed by me, and after what Red 2 turned up in a neighbouring arm that is worth a
reviewer's time.

## Not done

- **Item 2**: withdrawn by the project lead. The `finance.opening-balance` checker side still sits
  on `head_of_school` on `feat/finance-ob-approval-gate`. That branch is where it gets fixed, and it
  is not fixed yet. **This is a live open item, not a closed one.**
- **`2026_08_06` applied locally?** If the lead has run `migrate` on this branch against a local
  database, `2026_08_06` has applied there and `5236242` + `17da5c3` edited it afterwards. The
  carve-out's four conditions cover it, but the _state_ of that database was not checked — only the
  lead can check it.
- **Branch not pushed, not merged**, per the brief.
- Throwaway databases `portal_replay_test`, `portal_oracle_test` and `portal_grants_test` are left
  on the local server for inspection. Drop them when done.

## Findings raised, not fixed

- `tests/fixtures/route-access-map.json` on `origin/staging` is short by at least one route
  (`POST /api/notifications/ses-events`). Fixed here by re-derivation, but the _class_ recurs every
  time a route ships without regeneration, and `RouteAccessParityTest` is documented not to catch
  it. **ticket** — the asymmetry is deliberate, so the fix is a separate "every live route has a
  fixture entry" arm, not a change to this one.
- `bin/ci-grants-convergence-lint.php` exemption 1 waives a migration for NEW permissions, which is
  correct — but it means a new checker-side permission landing on the wrong seat is invisible to
  every gate. That is exactly the shape of the withdrawn item 2. **ticket** — a "no role holds both
  sides of an enforced pair in `grantsMap()`" arm exists
  (`tests/Feature/Rbac/GrantsMapSeparationTest.php`); a "no non-`executive_director` global role
  holds a `finance.*` checker" arm does not.
