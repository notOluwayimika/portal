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

---

# Addendum — fix pass (reviewer findings 1–6)

`bin/quality` 13/13 again. Not pushed, not rebased. All four fixes were comments, one const, one
docblock and one ADR section; **none needed a code change**, so nothing was stopped for.

## FIX 1 — the census, and the claim under it

**The correction is yours to own and I am recording it as you asked.** The census came from brief §5,
taken on the ED branch, and the `::sync` sentence was asserted from a grep whose hits were never read.
I transcribed both into the ADR and my report without re-running either. Filing it as a transcription
slip would be wrong: the source was a bad assertion, and my failure was accepting it — the same "never
carry a number" rule I have been enforcing all arc, broken inside the ADR written against it.

Re-derived on this branch, now pasted into `docs/adr/0052:107-116` next to the numbers:

```
$ grep -rhoE "RbacSeeder::[A-Za-z_]+|PermissionEnum::[A-Za-z_]+" database/migrations/ | sort | uniq -c | sort -rn
  23 RbacSeeder::GUARD
   5 RbacSeeder::sync
   2 PermissionEnum::ISOLATION_CROSSING
   1 RbacSeeder::syncLogged
   1 RbacSeeder::SUPER_ADMIN_PLATFORM
```

No `::ROLES`, no `PermissionEnum::FINANCE_ACCESS` on this branch at all. And every hit is prose:

```
$ grep -rn "RbacSeeder::sync" database/migrations/
2026_08_05_100000_converge_finance_access_grants.php:16: * `RbacSeeder::sync()` is non-destructive for GRANTS in both directions: for a role that already
2026_08_04_100000_revoke_internal_auditor_cross_school.php:30: * Why a migration at all: `rbac:sync` is non-destructive (RbacSeeder::syncLogged) — for a role that
2026_08_04_100000_revoke_internal_auditor_cross_school.php:48: * records it in activity_log (NOT wrapped in withoutLogs, unlike RbacSeeder::sync). Diff-based
2026_08_03_100000_converge_finance_change_grants.php:16: * `rbac:sync` is non-destructive in BOTH directions (RbacSeeder::sync, ~L462): for a role that
2026_08_02_100000_realign_finance_governance_grants.php:17: * in that same run and revokes NOTHING (RbacSeeder::sync, ~L462). So a grant REMOVED from
2026_08_02_100000_realign_finance_governance_grants.php:42: * records them in activity_log (NOT wrapped in withoutLogs, unlike RbacSeeder::sync). Diff-based
```

The "extreme form of the same defect" sentence is **withdrawn in the ADR by name**, not quietly
edited — it described zero lines of executable code, and the withdrawal says why it was there.

**The real instance is now named**: `2026_05_06_085734_update_terms_and_curricula_tables.php:48`,
`Artisan::call('db:seed', ['--class' => 'TermSeeder', '--force' => true])` inside `up()`. Invisible to
the new gate because it carries no `RbacSeeder::` token — the gate scans for one string and this
instance does not contain it. The ADR cites the file's own paragraph, which is more honest than
anything I would have written for it:

```
// ⚠ HAZARD — NON-DETERMINISTIC MIGRATION. DO NOT COPY THIS PATTERN.
// TermSeeder computes every term window from `now()->startOfYear()`, so the rows this
// migration writes DEPEND ON THE DAY IT RUNS…
// NOT REPAIRED, DELIBERATELY: it has already run on every environment…
// WHAT THIS COSTS TODAY: term dates are now load-bearing for money —
// `finance_fee_schedules.term_id` is a RESTRICT FK…
```

Not repaired here. Ticketed as the file itself frames it: *stop seeding from a migration at all*, a
separate change with its own data question. Report ticket 1 below is corrected to these numbers.

## FIX 2 — stale abort comments, swept across all four

Priority first. `2026_08_05`'s fresh-install guard comment was the only record of why the guard is
keyed on `finance.%`, and its stated reason was the abort. Rewritten so the reason survives the
mechanism — a reader who narrows it to `finance.access` now learns what they break:

```php
// the whole `finance.` namespace rather than on `finance.access` alone, AND THAT MATTERS MORE
// THAN IT LOOKS. `finance.access` missing while the rest of the namespace exists is NOT a
// fresh install — it is a broken substrate, and it must reach the target logic below, which
// names the absent permission in a `SKIPPED:` line and converges everything else.
//
// Narrow this to `finance.access` alone — a one-word edit that reads like a tightening — and
// that database takes THIS branch instead: a silent green return, no `SKIPPED:` line, nothing
// converged and nothing said. The check below used to abort, and does not any more (ADR 0052);
// the reason for the wide key survived that change unaltered, because it was never about the
// abort. It is about which of these two paths a broken substrate reaches.
```

Then the sweep, not just the two you named — five sites in three files:

| site | was | now |
| --- | --- | --- |
| `2026_08_04:45` | "aborts only on a condition its own writes would create" | states plainly that **nothing** in the file aborts, and that `grep -c "throw new"` is 0 by intent |
| `2026_08_04:115-117` | "abort naming it rather than quietly narrowing to IA" | reports with holder counts; says the abort turned an unaccounted grant into a permanent brick |
| `2026_08_04:100-101` | "Pre-flight 1: the governed role **must** exist" | still an anomaly, now reported and skipped — a role that does not exist cannot hold a grant that needs revoking |
| `2026_08_02:38` | same "aborts only on a condition…" sentence | same correction: this file creates no both-sides state, so nothing aborts |
| `2026_08_05:121` | above | above |

`2026_08_03` swept and clean: its only remaining `abort` mentions are `:141`, which correctly
describes the conversion, and `:277`, the surviving walk's own message. No stale `Pre-flight N` labels
remain in any of the four.

## FIX 3 — `2026_08_04`'s unread const deleted, ADR table corrected

`private const TARGET = ['internal_auditor' => []];` is gone, along with the `{@see self::TARGET}`
that pointed at it. The file says instead why it needs none: its act is already a pair of literals,
`self::PERMISSION` and `self::ROLE`, both there before this branch, and a const no code reads asserts
a wiring that does not exist.

The ADR's Consequences table now carries a **what changed** column rather than implying four identical
freezes:

| file | what changed | frozen at |
| --- | --- | --- |
| `2026_08_02…` | target frozen; three aborts → report/skip | `f143b40` |
| `2026_08_03…` | target frozen; three aborts → report/skip (walk keeps its throw) | `01fdeda` |
| `2026_08_04…` | **already frozen** — `PERMISSION` + `ROLE`, literals before this branch. Live-map assertion deleted; three aborts → report/skip | — |
| `2026_08_05…` | target frozen; three aborts → report/skip | `af9db7a` |

"All four commits agree" became "the three adding commits agree", since one row no longer has one.

## FIX 4 — orphaned docblock

`2026_08_03:86`, the `@var list<string>` left over from the deleted `$governed` property, sitting
above a const whose real type is `array<string, list<string>>`. Deleted.

## One deviation

**I also qualified ADR `:56-58`, which you ticketed rather than asked me to fix.** The sentence said
the surviving walk aborts only on a condition its own writes create; reviewer 4 is right that it does
not. Leaving a sentence I know to be false inside the ADR I had just corrected *for containing a
sentence I did not check* was not defensible. The fix is documentation only — the walk's code is
untouched — and it states the overstatement, says it is ticketed rather than fixed, says why it cannot
bite today, and names the ED case. If you wanted the ADR left alone until the ticket is worked, revert
that paragraph; nothing else depends on it.

## Tickets

1. **`Artisan::call('db:seed')` inside a migration** —
   `2026_05_06_085734_update_terms_and_curricula_tables.php:48`. The one live instance of "a migration
   that re-runs a seeder". Invisible to the new gate (no `RbacSeeder::` token). Deliberately not
   repaired, per the file's own reasoning; the fix it names is to stop seeding from migrations at all.
   Census above is the sizing.
2. **`RbacSeeder::GUARD` ×23, `::sync` ×5, `::syncLogged` ×1, `::SUPER_ADMIN_PLATFORM` ×1,
   `PermissionEnum::ISOLATION_CROSSING` ×2** in `database/migrations/`. All prose except `GUARD` and
   `ISOLATION_CROSSING`, which are constants that do not encode a moving business decision. Widening
   the gate to any of them is a separate decision with a separate blast radius.
3. **`finance:check-staffing-readiness` returns FAILURE and no `bin/quality` step runs it.** Carried
   from the ED branch, correct there and here.
4. **The under-convergence the trade permits has no detector wired to it.** `rbac:diff-grants` finds
   it; nothing runs `rbac:diff-grants` automatically.
5. **`2026_08_03`'s surviving walk is broader than ADR `:56-58`'s rule** (reviewer 4). It filters to
   `enforcedPairs()` but never to what this migration wrote, so a both-sides state built from roles it
   does not govern would throw and roll back. Cannot bite today: no user holds `executive_director`,
   and `assertAssignmentAllowed` refuses the pairing at assignment time. **Record it as an ED-branch
   hazard**: after the rebase, a user holding ED plus any `*.change.submit` maker role is a violation
   this 2026-08-02 migration did not create and would roll back for.

   **And note it as a pattern, not a line.** This is the second time this branch has found a
   2026-08-02 file reaching forward to a 2026-08-04 decision. The first was the frozen-target defect
   itself; this is the same shape in the one abort the freeze deliberately left standing. Freezing the
   *target* did not freeze the *guard*, and a guard that reads live state is a live query wearing a
   different hat.

---

# ED rebase debt

One place to work from. **Re-derive every number here at rebase time; none of them carry.**

1. **Freeze `2026_08_06_100000_move_head_of_school_finance_to_executive_director.php`.** The new gate
   forces it — it carries `RbacSeeder::grantsMap`, and its docblock advertises the defect as a design.
   Frozen at ED's own commit, its target is `head_of_school => []`,
   `accounts_supervisor => ['finance.access', 'finance.fee-schedule.change.submit']`, and
   `executive_director =>` its nine. Its aborts get the same ADR 0052 treatment, except any that
   qualify under the corollary — check the ED-role-missing abort against the rule rather than assuming
   it converts: it guards a condition that would leave HoS stripped with no seat able to approve, and
   that may be the second legitimate abort on this project.
2. **The six arms in `FinanceChangeGrantConvergenceTest` and `FinanceAccessGrantConvergenceTest`** —
   brief §6.2, correct as written and wrong as scheduled. They are red on ED, not here, so the rewrite
   is rebase work. §6.2's prescriptions still apply: assert the frozen literal for every governed
   role, and rewrite `FinanceAccessGrantConvergenceTest` ARM 4 to prove both drift shapes at once.
3. **`RealignFinanceGovernanceGrantsTest` ARM 0** (reviewer 6). Its first block asserts the fresh
   seed's absolute content equals the frozen literal — a live-map read wearing a test's clothes, and
   it goes red on ED where `head_of_school` holds no finance change grants. Rewrite it
   map-independently: capture the slice, plant, assert it CHANGED and now equals the planted shape.
   That is what bite-proofing needs and it survives every future map edit. This branch added that
   debt; it is mine, not inherited.
4. **Reviewer 4's ED hazard**, ticket 5 above: ED + any `*.change.submit` holder trips `2026_08_03`'s
   walk. Decide at rebase time whether to scope the walk or accept it, with the pattern note in mind.
