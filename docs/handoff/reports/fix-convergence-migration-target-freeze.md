# Report — freeze the convergence migrations' targets

**Branch:** `fix/convergence-migration-target-freeze`, cut from `staging` @ `f299f40`.
**Brief:** `docs/handoff/convergence-migration-target-freeze-brief.md` + `plan_docs/task.md`

```
$ git rev-list --count $(git merge-base staging HEAD)..HEAD
1
```

**`bin/quality` 13/13.** Not pushed. ED not rebased. `2026_08_06` untouched.

---

## STEP 0 — your §1 ordering claim holds

Scratch Pest test on `feat/executive-director-role`, seeding `DatabaseSeeder` then `require`-ing
`2026_08_02_100000_realign_finance_governance_grants.php` and calling `up()`. Raw:

```
>>> STEP 0 RESULT: RuntimeException
>>> MESSAGE: realign-finance-grants ABORTED: unexpected global role(s) grant the governed permissions: executive_director (holders=0). The maker source is not what the realignment assumed — investigate before widening this migration.
```

It aborts, it names `executive_director`, and it is first in filename order. Scratch file deleted, fix
branch cut from `staging`, continued.

## STEP 1 — the four freezes, one verified against git myself

I checked `2026_08_02`'s literal rather than trusting the transcription:

```
$ git show f143b40:database/seeders/RbacSeeder.php | awk "/public static function grantsMap/,/^    \}$/" \
    | grep -E "^            '(principal|head_of_school)' => \[|FEE_SCHEDULE_CHANGE_|DISCOUNT_POLICY_CHANGE_|FINANCE_ACCESS->"
                PermissionEnum::FINANCE_ACCESS->value,
            'head_of_school' => [
                PermissionEnum::FINANCE_ACCESS->value,
                PermissionEnum::FINANCE_FEE_SCHEDULE_CHANGE_APPROVE->value,
                PermissionEnum::FINANCE_FEE_SCHEDULE_CHANGE_REJECT->value,
                PermissionEnum::FINANCE_DISCOUNT_POLICY_CHANGE_APPROVE->value,
                PermissionEnum::FINANCE_DISCOUNT_POLICY_CHANGE_REJECT->value,
            'principal' => [
                PermissionEnum::FINANCE_ACCESS->value,
```

Matches your §4.1 exactly: HoS the four approve/reject inside the two governed namespaces,
`principal` `[]` — its `finance.access` lies outside those namespaces and is not governed there.

All four `private const TARGET`s are plain strings with the adding-commit SHA and date above them.

## STEP 2 — abort became report; census

```
2026_08_02_100000_realign_finance_governance_grants     grantsMap=0 throws=0
2026_08_03_100000_converge_finance_change_grants        grantsMap=0 throws=1
2026_08_04_100000_revoke_internal_auditor_cross_school  grantsMap=0 throws=0
2026_08_05_100000_converge_finance_access_grants        grantsMap=0 throws=0
```

The one surviving throw is `2026_08_03`'s post-write user-scoped duty-separation walk, untouched —
including its rollback. Its docblock paragraph is intact except for one word, see the deviations.

## Deviations, at the top

**1. `2026_08_03`'s protected docblock: I changed four words in it, and the claim got stronger.**
Your STEP 2 says do not reword `:61-76`. That paragraph contained the literal `grantsMap()`, which
the new gate forbids anywhere in `database/migrations/`. The sentence read *"this migration writes a
FIXED target derived from grantsMap()"*; it now reads *"a FIXED target frozen at its adding commit"*.
The argument is identical and the fact it states is now more true than it was. Nothing else in the
paragraph moved.

**2. `2026_08_04` does not report what the live map says.** Your §4.3 asks for "an echo that reports
what the current map says". That requires calling the grants map, which the gate in §5 forbids — the
two sections conflict and I took §5 as the rule the branch installs. The echo instead says the
question belongs to `php artisan rbac:diff-grants`, which is where it was already going to be
answered.

**3. `2026_08_04`'s ISOLATION_CROSSING premise check also became a report.** Your §4.3 named the map
assertion and the two `§4.5` skips but not this one; STEP 2's *"every other abort in the four files
becomes an echo and a continue"* is explicit, so it converted. It is a premise check, not a condition
this migration's own writes could create, so the corollary covers it. The `PermissionEnum::` reference
stays — that is the ticketed remainder, not this branch.

**4. Three conversions had no arm in your list, so I wrote them.** STEP 4 says each abort-to-report
conversion carries its own arm. `2026_08_03`'s missing-permission and missing-role skips and
`2026_08_05`'s missing-role skip had none. Added as `ARM 5`/`ARM 6` and `ARM 7` respectively.

**5. `InternalAuditorCrossSchoolRevocationTest` ARM E's comment was rewritten.** It described the two
aborts this branch deleted, at length, as things it deliberately did not pin. Leaving it would be a
false justification in a comment — the exact failure this arc keeps naming. It now says what changed
and what the assertion still pins.

## STEP 3 — the gate, bite-proved

`tests/Feature/Rbac/MigrationsDoNotReadTheSeederMapTest.php`. Population assertion first, offender
scan second, with the reason in the code. Green, then with a `RbacSeeder::grantsMap()` call re-added
to `2026_08_05`:

```
green  1 passed / 0 red
mutant: grantsMap() re-added to 2026_08_05
RED    0 passed / 1 red
    these migrations read the seeder grants map at run time; freeze their target as a literal instead — see ADR 0052 Failed asserting that two arrays are identical. --- Expected +++ Actual @@ @@ -Array &0 [] +Array &0 [
```

It names the file. Restored, green.

## STEP 4 — arms

**`2026_08_02` has a test file for the first time**, `RealignFinanceGovernanceGrantsTest.php`, eight
arms: bite-proof plant, converges-to-the-frozen-target, idempotent, offender reported, missing role
skipped, missing permission skipped, fresh install quiet, school-scoped rows untouched. The frozen
target is written out **as literals in the test**, not read from `self::TARGET` — two copies is the
point.

**Five arms that asserted a throw are now report/skip arms.** Each asserts the act still completed,
not just that the message appeared — that second half is what a "report" which quietly returns early
would break. On this branch the five were red for exactly that reason before the rewrite:

```
failed 11 passed / 5 failed / 0 errors
  RED: does_not_fall_through_the_fresh_install_guard       Exception "RuntimeException" not thrown.
  RED: ARM_3_—_offender_pre_flight_bites…                  Exception "RuntimeException" not thrown.
  RED: ARM_3_—_role_scoped_pre_flight_bites…               Exception "RuntimeException" not thrown.
  RED: ARM_C_—_the_third_holder_pre_flight_bites…          Exception "RuntimeException" not thrown.
  RED: ARM_F_—_the_missing_role_pre_flight_bites…          Exception "RuntimeException" not thrown.
```

**All eleven conversions mutation-checked** — restore the throw, run only that arm:

```
A02_offender   ARM 3    -> RED    0p/1r
A02_role       ARM 4    -> RED    0p/1r
A02_perm       ARM 5    -> RED    0p/1r
A03_offender   ARM 3    -> RED    0p/1r
A03_perm       ARM 5    -> RED    0p/1r
A03_role       ARM 6    -> RED    0p/1r
A04_holder     ARM C    -> RED    0p/1r
A04_role       ARM F    -> RED    0p/1r
A05_offender   ARM 3    -> RED    0p/1r
A05_perm       ARM 6    -> RED    0p/1r
A05_role       ARM 7    -> RED    0p/1r
```

Restored, full `tests/Feature/Rbac`:

```
{"tool":"pest","result":"passed","tests":299,"passed":299,"assertions":1182,"duration_ms":120691,"risky":2}
```

**A correction to §6.2's premises, because this branch is cut from `staging` and not from ED.** The
six arms you called red are red *on the ED branch*, where `executive_director` exists. Here the seeded
map is still the pre-seat-move one, so `head_of_school` does hold `finance.access` and
`FinanceAccessGrantConvergenceTest` ARM 4 needs no rewrite — the rewrite §6.2 describes belongs to the
ED branch after the rebase, where the map has moved. What was red *here* is the five conversions
above, which is a different set. Both are now handled; the ED-side rewrite is not, and cannot be, on
this branch.

## STEP 5 — ADR

`docs/adr/0052-a-migration-is-a-dated-act.md`, indexed in `docs/adr/README.md`. It records the rule,
the corollary, the STEP 0 bite-proof verbatim, the four files with their frozen commits, the §5
remainder, and the trade — under its own heading, *"The trade, stated rather than buried"*, so it
cannot be skimmed past. All four migrations and the gate test reference it.

## Tickets — raised, not worked

1. **`RbacSeeder::sync` ×6, `::ROLES` ×2, `::SUPER_ADMIN_PLATFORM` ×1, `PermissionEnum::ISOLATION_CROSSING`
   ×2, `PermissionEnum::FINANCE_ACCESS` ×1 in `database/migrations/`** are the same time-dependency
   class. `::sync` is the extreme form — a migration that re-runs the seeder re-shapes itself
   completely. The line is drawn at the grants map because it is the only one whose value is a
   business decision that moves whenever Brookstone changes their mind, and the only one that has
   bitten.
2. **`finance:check-staffing-readiness` returns FAILURE and no `bin/quality` step runs it.** Carried
   from the ED branch; unchanged here and correct — no user holds `executive_director`.
3. **The under-convergence the trade permits has no detector wired to it.** An operator who ignores a
   `SKIPPED:` line gets a migration that ran, a `migrations` row that says so, and grants that are not
   where the map says. `rbac:diff-grants` finds it, but nothing runs `rbac:diff-grants` automatically.

## What I did not do

- `2026_08_06_100000_move_head_of_school_finance_to_executive_director.php` — untouched, on the ED
  branch. The gate will force its freeze after the rebase, which is the intended mechanism.
- No migration deleted, no governed set widened to include `executive_director`, no squash.
- Not pushed. ED not rebased.
- **Nothing driven against the dev database.** The four migrations have already applied there; the
  STEP 0 proof and every arm ran against `portal_testing`.
- **`migrate:fresh` on a database seeded with `executive_director` is not re-proved here**, because
  this branch has no such role. That verification belongs to the ED rebase, and it is the one that
  closes failure mode B end to end.
