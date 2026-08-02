# Implementation report — `feat/rbac-diff-grants`

**This is full-review tier — recommend a cold session before merge.** Roles, permissions, a new
`bin/quality` step, and a command whose output is the input to the next brief.

**Base:** `staging` @ `79798c8`. **Branch:** `feat/rbac-diff-grants`. **Two commits** — the second is
a review fix; see the **Appendix** at the end, which supersedes this body wherever they disagree.
`fix/revoke-ia-cross-school` **has** merged (`e35ccf1`, merging `964e97a`), so the stop condition on
it is cleared.

---

## The premise, verified before anything was built

The brief named one thing to verify first, with the instruction to stop if it did not hold:
`RbacSeeder.php:432`, `activity()->withoutLogs(fn () => $this->syncLogged($fresh))`. **It holds, and
the chain is complete:**

- [database/seeders/RbacSeeder.php:432](../../../database/seeders/RbacSeeder.php#L432) — the closure
  is the **entire** body of `syncLogged()`, which spans
  [:435-515](../../../database/seeders/RbacSeeder.php#L435-L515).
- `syncLogged()` is **private with exactly one caller** (`sync()` at `:432`). There is no bypass.
- Every entry point routes through `sync()`: `run():423`, `RbacSync.php:25`, and
  `SeedDriveFixture.php:69` (via `run()`).
- [app/Listeners/LogRbacChange.php:41](../../../app/Listeners/LogRbacChange.php#L41) writes through
  the `activity()` helper, and vendor
  `ActivityLogger::log():161-163` returns `null` when the status is disabled.
  `withoutLogs():183-195` disables and restores in a `finally`.
- The properties key is `permissions` — `$subjectKind.'s'` at `LogRbacChange.php:45`. Confirmed
  against the real column on the dev copy, not inferred: `subject_type` is the raw
  `App\Models\Role` string, and `JSON_CONTAINS(properties, JSON_QUOTE(?), '$.permissions')` matches.

So the seeder writes no activity rows at all, every `rbac` permission row is a non-seeder mutation,
and Section C's classification is the right way round.

---

## Deviations from the brief — read these first

**1. Six diagnoses, not four.** The brief's table covers `missing`+none, `missing`+detached,
`extra`+attached, `extra`+none. It does not cover `missing` whose latest row is an **attach**, or
`extra` whose latest row is a **detach**. Both are reachable in this codebase, and not
theoretically: `HasPermissions::syncPermissions` detaches **raw and fires no event** (the C6 vendor
lesson, CLAUDE.md), so "the attach was logged, the removal was not" is a real shape. Forcing those
into the four-way table would have produced a confident wrong verdict, so they are named
`ATTACHED_THEN_LOST` and `DETACHED_THEN_REGAINED` and both say **INVESTIGATE** rather than
prescribing a remedy. Neither fires on the dev copy today.

**2. The lint has a FOURTH exemption the brief does not list, and without it the gate would fire on
a legitimate change.** `grantsMap()['super_admin']` **is** `RbacSeeder::SUPER_ADMIN_PLATFORM`
([:417](../../../database/seeders/RbacSeeder.php#L417)), and the self-heal block at
[:506-512](../../../database/seeders/RbacSeeder.php#L506-L512) runs
`syncPermissions(self::SUPER_ADMIN_PLATFORM)` **unconditionally** — outside the `$fresh` branch and
outside the `$newPermissions` intersection. A permission added to that const therefore lands on the
next `rbac:sync` with no migration. Exemption 4 is line-precise (the finding's line number must fall
inside the const block in the new file), deliberately not name-based: naming would over-exempt a
permission added to `super_admin` **and** to another role in the same diff.

**3. Role attribution is resolved against the new FILE, not the diff hunk.** The brief specified
"the nearest preceding `'<role>' => [` **in the hunk**" and accepted losing it when hunks are tight.
That failure is not hypothetical — in `7370e89`, the commit this lint exists for, `head_of_school`'s
key sits 25 lines above the added grant and a hunk-local scan loses it outright. The lint instead
takes the added line's number in the new file and scans backwards there. Still inference (regex, not
a PHP parse), still marked `INFERRED` in the output, but it lands on both roles in the real case
rather than one. It reports `?` rather than guessing when there is no preceding role key — which is
the *correct* answer for the shared `$guardianFull` / `$activityAdmin` fragments defined above
`return [`, since those are granted to every role that spreads them.

**4. Exemptions 1 and 2 compare sets at `BASE` and `HEAD` rather than parsing diff text.** "The same
diff adds the enum case" and "the same diff adds the role to `ROLES`" are set differences, and
`git show <rev>:<path>` gives them exactly. Parsing added lines for the same facts is strictly worse
and can only introduce error. Findings still come from the diff (that is where the file:line and the
role context live); only the two exemption *tests* are set comparisons.

**5. The lint takes an optional second ref (`<base> [head]`).** This exists so its tests can replay
real historical commits instead of fixture diffs — see "Tests" below. `bin/quality` passes one
argument and gets the briefed behaviour.

**6. `GrantsConvergenceLintTest.php` was written, not skipped.** The brief said to skip it rather
than fake it with a constructed diff. As of this commit it is not constructed: every arm replays a
**real commit from this repository's history**, so nothing about the test's shape can flatter the
lint. **Superseded in part by the Appendix** — the second commit adds one fixture-driven arm, for a
diff shape that has never occurred in this repository, and says so there rather than here.

---

## What was built

| File | What |
| --- | --- |
| [app/Console/Commands/RbacDiffGrants.php](../../../app/Console/Commands/RbacDiffGrants.php) | `rbac:diff-grants {--json}`. Read-only. Sections A / B / C, structural findings, footer, exit 1 on findings. |
| [bin/ci-grants-convergence-lint.php](../../../bin/ci-grants-convergence-lint.php) | Diff-aware gate. Four exemptions. No baseline file. |
| [bin/quality](../../../bin/quality) | New step 7 beside boundary-lint, taking `"$BASE"`. Step count 12 → 13. |
| [tests/Feature/Rbac/RbacDiffGrantsTest.php](../../../tests/Feature/Rbac/RbacDiffGrantsTest.php) | 10 arms. |
| [tests/Feature/Rbac/GrantsConvergenceLintTest.php](../../../tests/Feature/Rbac/GrantsConvergenceLintTest.php) | 5 arms here, real-history replays. **7 after the Appendix's second commit.** |
| [docs/runbooks/rbac-grants-reconciliation.md](../rbac-grants-reconciliation.md) | Operator runbook. |
| [docs/testing.md](../../testing.md) | One paragraph under "Accepted permanent residuals": the lint is **not retroactive**, and why no state-based gate can be. |

### Judgement calls worth a second pair of eyes

- **A non-zero school-scoped count does not flip the exit code.** The brief's exit rule is "non-zero
  if any *section* is non-empty"; the footer is a count, not a section, and there is no expectation
  to diff a school-scoped row against. It warns loudly instead. An operator command that exits
  non-zero on a condition with no action attached is one people learn to ignore. The count is **0**
  on the dev copy.
- **`bin/quality`'s header comment said "steps 1..10 / step 11" while the script had 12 steps.** It
  was already one behind before this change. I re-derived it (1..12 / 13) rather than propagate a
  stale number, and said so in the comment.

---

## The enumeration — full output against the development copy

This is the deliverable the next brief is sized against. `portaa10_portal`, unmodified; the command
writes nothing (asserted by an arm, not just claimed).

```
$ php artisan rbac:diff-grants
rbac:diff-grants — RbacSeeder::grantsMap() vs the live grants
  env=local  db=portaa10_portal  guard=web
  scope: global role rows only (roles.school_id IS NULL)

SECTION A — permission catalog (enum vs `permissions` rows)
  clean — the enum and the permission rows agree.

SECTION B/C — grants per global role, with the diagnosis for each difference

  ROLE head_of_school (role#2)
    1 MISSING (in the map, not on the role)
+----------------+--------------+---------+-------------+------+-------------+
| Permission     | Diagnosis    | Log row | Event       | When | causer NULL |
+----------------+--------------+---------+-------------+------+-------------+
| finance.access | SYNC_ADD_GAP | —       | no rbac row | —    | —           |
+----------------+--------------+---------+-------------+------+-------------+
      SYNC_ADD_GAP: NON-DESTRUCTIVE-SYNC ADD GAP — grantsMap() gained this after the permission already existed, so rbac:sync never granted it. Needs a convergence migration.

  ROLE principal (role#10)
    1 MISSING (in the map, not on the role)
+----------------+--------------+---------+-------------+------+-------------+
| Permission     | Diagnosis    | Log row | Event       | When | causer NULL |
+----------------+--------------+---------+-------------+------+-------------+
| finance.access | SYNC_ADD_GAP | —       | no rbac row | —    | —           |
+----------------+--------------+---------+-------------+------+-------------+
      SYNC_ADD_GAP: NON-DESTRUCTIVE-SYNC ADD GAP — grantsMap() gained this after the permission already existed, so rbac:sync never granted it. Needs a convergence migration.

FOOTER — school-scoped `web` role rows (school_id IS NOT NULL), counted, NEVER diffed: 0

TOTALS  catalog: 0 missing row(s), 0 extra row(s)  |  grants: 2 missing, 0 extra across 2 role(s)  |  roles: 0 mapped-without-row, 0 unmapped
  FINDINGS (detection only — nothing was changed)
DETECTION ONLY — nothing was granted, revoked or written. A SYNC_ADD_GAP needs a convergence
migration; a MATRIX_GRANT is C6 local authority and must be left alone; a DELIBERATE_REVOKE is a
human decision. Deciding which is which is the point of the diagnosis column, not this command.

EXIT=1
```

```json
$ php artisan rbac:diff-grants --json
{
    "generated_at": "2026-08-02T21:32:38+00:00",
    "environment": "local",
    "database": "portaa10_portal",
    "guard": "web",
    "scope": "global role rows only (roles.school_id IS NULL)",
    "catalog_interpretable": true,
    "catalog": { "missing_rows": [], "extra_rows": [] },
    "roles": {
        "head_of_school": {
            "role_id": 2,
            "missing": [
                {
                    "permission": "finance.access",
                    "diagnosis": "SYNC_ADD_GAP",
                    "summary": "NON-DESTRUCTIVE-SYNC ADD GAP — grantsMap() gained this after the permission already existed, so rbac:sync never granted it. Needs a convergence migration.",
                    "log": null
                }
            ],
            "extra": []
        },
        "principal": {
            "role_id": 10,
            "missing": [
                {
                    "permission": "finance.access",
                    "diagnosis": "SYNC_ADD_GAP",
                    "summary": "NON-DESTRUCTIVE-SYNC ADD GAP — grantsMap() gained this after the permission already existed, so rbac:sync never granted it. Needs a convergence migration.",
                    "log": null
                }
            ],
            "extra": []
        }
    },
    "mapped_roles_with_no_global_row": [],
    "unmapped_global_roles": [],
    "school_scoped_role_rows": 0,
    "totals": {
        "catalog_missing_rows": 0,
        "catalog_extra_rows": 0,
        "roles_with_findings": 2,
        "missing_grants": 2,
        "extra_grants": 0,
        "mapped_roles_with_no_global_row": 0,
        "unmapped_global_roles": 0
    },
    "verdict": "FINDINGS (detection only — nothing was changed)"
}
```

### What this enumeration says

**The defect class is two grants wide on this copy, and they are the two already known.** The brief
was written on the expectation that "nobody has enumerated the rest" — the rest is **empty**. There
are no other `SYNC_ADD_GAP`s, no `MAP_REMOVAL_GAP`s, no `MATRIX_GRANT`s, no unmapped global roles, no
mapped roles missing a row, no school-scoped `web` roles, and Section A is clean (so Section B is
interpretable, and the `missing` findings are not an artefact of an unsynced database).

Sizing note for the convergence brief: this is **one migration granting one permission to two global
roles**, not the open-ended sweep the brief budgeted for. Both are `SYNC_ADD_GAP` with **no rbac log
row at all**, which is the diagnosis that means "converge" — neither is a `DELIBERATE_REVOKE`, so
there is no human decision blocking it.

**Independent corroboration that the rule is real, from history rather than from this command:**
replaying `a0ab3d7` through the lint reports four pre-existing finance checker permissions added to
`head_of_school` with no naming migration — and `01fdeda` later shipped a convergence migration for
exactly those grants. The lint would have caught it at the time. Those grants are **not** in the
enumeration above, because that migration ran.

---

## Watched reds

Five, of which three were required. All restored; the working tree is byte-identical to the commit
(`git diff --stat HEAD` empty, verified after each).

### Red 1 — the command, on a scratch database (`portal_testing`, `migrate:fresh --seed`)

The production copy was **not** touched: planting drift on ground truth would destroy exactly what
makes it useful.

Clean baseline:

```
SECTION A — clean.  SECTION B/C — clean — every role in grantsMap() holds exactly its mapped grants.
FOOTER — school-scoped `web` role rows … : 0
TOTALS  catalog: 0 missing row(s), 0 extra row(s)  |  grants: 0 missing, 0 extra across 0 role(s)
  CLEAN — grantsMap() and the live grants agree.
EXIT=0
```

Planted — `activity()->withoutLogs(fn () => $registrar->revokePermissionTo('guardian.detach'))`,
which reproduces a grant that was never made (no trace anywhere), not a logged revoke:

```
  ROLE registrar (role#11)
    1 MISSING (in the map, not on the role)
+-----------------+--------------+---------+-------------+------+-------------+
| Permission      | Diagnosis    | Log row | Event       | When | causer NULL |
+-----------------+--------------+---------+-------------+------+-------------+
| guardian.detach | SYNC_ADD_GAP | —       | no rbac row | —    | —           |
+-----------------+--------------+---------+-------------+------+-------------+
TOTALS  …  |  grants: 1 missing, 0 extra across 1 role(s)  |  …
  FINDINGS (detection only — nothing was changed)
EXIT=1
```

Restored — `CLEAN … EXIT=0`.

### Red 2 — the lint fires, through `bin/quality`

Mutation: one line into `grantsMap()`, a pre-existing permission to a pre-existing role, no
migration.

```php
             'registrar' => [
+                PermissionEnum::FINANCE_ACCESS->value, // WATCHED RED 2 — pre-existing permission, pre-existing role, no migration
                 PermissionEnum::GUARDIAN_VIEW->value,
```

Full `bin/quality` (not the lint alone — this is the wiring proof):

```
[7/13] grants-convergence lint (a pre-existing permission added to grantsMap() ships a migration)
   ✗ grants-convergence-lint

       grants-convergence-lint: 1 grant addition(s) in database/seeders/RbacSeeder.php that rbac:sync will NOT apply (79798c8..23ed28a):

         ✗ finance.access  @  database/seeders/RbacSeeder.php:264
             role: registrar (INFERRED from the nearest preceding '<role>' => [ — verify it)
             line: PermissionEnum::FINANCE_ACCESS->value, // WATCHED RED 2 — …
…
✗ quality: FAIL (2): grants-convergence-lint test-ratchet
```

The second failure is corroboration, not noise: `PermissionEnumTest` (the grants-map fixture) and
`RouteAccessParityTest` also went red, which is independent evidence that a `grantsMap()` edit is a
real change and not something the lint invented.

### Red 3 — the lint PASSES on a genuinely new permission (required)

The direction that decides whether the gate survives. Mutation: a new enum case **and** a grant of
it, same diff, **no migration**.

```php
     case STUDENT_STATUS_VIEW = 'student_status.view';
+    case SCRATCH_RED_THREE = 'scratch.red_three'; // WATCHED RED 3 — genuinely NEW permission
…
             'registrar' => [
+                PermissionEnum::SCRATCH_RED_THREE->value, // WATCHED RED 3 — new permission, pre-existing role, NO migration
```

```
grants-convergence-lint: OK — no unexempted grant addition in database/seeders/RbacSeeder.php (79798c8..d314b29; 1 exempted).
  · scratch.red_three @ database/seeders/RbacSeeder.php:264 — exempt: permission is NEW in this diff (lands in $newPermissions)
EXIT=0
```

### Red 4 — exemption 3 passes when a migration NAMES the permission (extra)

The resolution path. If this did not work, a developer doing the right thing would be blocked and
would disable the gate. Red 2's seeder mutation, plus a scratch migration containing the string
`finance.access`:

```
grants-convergence-lint: OK — no unexempted grant addition in database/seeders/RbacSeeder.php (79798c8..d94ef5e; 1 exempted).
  · finance.access @ database/seeders/RbacSeeder.php:264 — exempt: migration [database/migrations/2099_01_01_000000_scratch_converge_registrar_finance_access.php] in this diff names it
EXIT=0
```

### Red 5 — the WEAK form of exemption 3 does NOT exempt (extra)

Same as Red 4 with the permission string removed from the migration, so the diff still contains an
added migration. The brief called the weak form wallpaper; this proves it is not implemented:

```
grants-convergence-lint: 1 grant addition(s) in database/seeders/RbacSeeder.php that rbac:sync will NOT apply (79798c8..4a58648):

  ✗ finance.access  @  database/seeders/RbacSeeder.php:264
EXIT=1
```

---

## Tests

`DB_DATABASE=portal_testing ./vendor/bin/pest tests/Feature/Rbac/RbacDiffGrantsTest.php tests/Feature/Rbac/GrantsConvergenceLintTest.php`

```
{"tool":"pest","result":"passed","tests":15,"passed":15,"assertions":78,"duration_ms":19773}
```

**`RbacDiffGrantsTest` — 10 arms.** Clean-DB ⇒ exit 0; `missing` for a revoked mapped permission and
nothing else; `extra` for a map-absent grant; the classification flip; `MATRIX_GRANT` for a logged
`extra`; a school-scoped role is not drift; an unmapped global role is reported; a catalog difference
banners Section B and still exits non-zero; `--json` and the table agree and exit alike; and the
command writes nothing.

The arms plant their drift in **two flavours**, and that difference is the subject of the arm that
matters most: `activity()->withoutLogs(...)` reproduces a grant that was never made, a plain call
reproduces a runtime mutation `LogRbacChange` recorded. Both leave `role_has_permissions` in an
**identical** state — same role, same missing permission — so only `activity_log` separates them.
That arm asserts the diagnosis flips from `SYNC_ADD_GAP` to `DELIBERATE_REVOKE` and that the evidence
(`log.event = permission_detached`, the row id) comes back with it.

**`GrantsConvergenceLintTest` — 5 arms, replaying real history.** A fixture diff is shaped by the
same assumptions as the lint, so it can only confirm them. These replay commits written before the
lint existed, whose outcomes are independently known:

- `7370e89^…7370e89` — fails, names `finance.access`, resolves **both** roles, marks them `INFERRED`,
  and exempts the three genuinely-new discount-policy permissions from the same commit.
- `9caf958^…9caf958` — **passes**: 19 new permissions granted in one commit, no migration.
- `a0ab3d7^…a0ab3d7` — fails on the four pre-existing finance checker grants (history agrees:
  `01fdeda`), while exempting the grants to the five brand-new finance seats via exemption 2.
- `HEAD…HEAD` — passes, seeder unchanged.
- an unresolvable base — **exits 1**, `NOT LINTED`. A gate that cannot see the diff must not be green.

Each history arm `markTestSkipped`s if the SHA is unreachable (shallow clone, export). A skip is
visible; a false green is not.

---

## `bin/quality` on the commit

Run against `ae3bbe6`, the commit carrying the whole change. **The only delta between that tree and
the final commit is this report file** — the amend that adds it is the last action, so the SHA
printed by `git log` will differ from the one the gate saw. That is stated rather than papered over;
a re-run on the final SHA is recorded in the hand-off message, and the lead can reproduce it with
`bin/quality` on the branch tip.

```
quality gate — base 79798c8

[1/13] wayfinder:generate --with-form (must match vite.config.ts formVariants)
   ✓ wayfinder:generate
[2/13] lint changed files (Pint / Prettier / ESLint, check mode)
   ✓ lint-changed
[3/13] types (tsc ratchet vs tsc-baseline)
   ✓ tsc-ratchet
[4/13] frontend build (vite — catches what the tsc ratchet structurally cannot)
   ✓ build
[5/13] authorization guard (no new commented-out checks)
   ✓ authz-lint
[6/13] boundary lint (§17.2)
   ✓ boundary-lint
[7/13] grants-convergence lint (a pre-existing permission added to grantsMap() ships a migration)
   ✓ grants-convergence-lint
[8/13] money lint (UI: money via formatNaira, no JS money math)
   ✓ money-lint
[9/13] runtime-zero lint (S7 legacy access sources)
   ✓ runtime-zero-lint
[10/13] identifier-generation bypass guard (1.4b)
   ✓ identifier-generation-lint
[11/13] architecture tests (§17.1)
   ✓ arch
[12/13] static analysis (Larastan level 5 vs baseline)
   ✓ larastan
[13/13] tests (failure ratchet vs tests/ratchet-baseline.txt)
   ✓ test-ratchet

✓ quality: PASS — per-push floor. Promoting to main? run bin/quality-promote.
```

The new step lands at **7/13**, beside boundary-lint, and the step count is 12 → 13.

---

## Findings outside the change — none fixed here

1. **`Role::create` cannot create a school-scoped role whose name matches a global one.** Vendor
   `Models/Role.php:186-188` (`findByParam`) matches `school_id IS NULL **OR** school_id = <team>`,
   so an existing global row of that name makes it throw `RoleAlreadyExists` regardless of team
   context — even though the unique index `roles_team_name_guard_unique` on
   `(IFNULL(school_id,0), name, guard_name)` permits the pair. This is the mechanism C6 per-school
   authority would use, and it is not reachable through `Role::create` for any seeded role name; it
   would need `Role::query()->create()` or a direct insert. Found while writing the school-scoped
   arm, which uses `Role::query()->create()` and says why. **Severity: ticket** — nothing depends on
   it today (`school_scoped_role_rows` is 0), but a C6 per-school slice would hit it on day one.
2. **`AuditDutySeparation.php:55`** still emits `$user->email ?? ('user#'.$user->id)`. Not
   propagated here (this command emits no user rows at all). **Ticket**, as the brief says.
3. **`PermissionGroup.php:100`** — untouched, per the brief. **Ticket.**

---

## What I did not do

- **No convergence migration.** Out of scope by the brief, and the enumeration above is what it
  should be sized against.
- **I did not verify the brief's belief that the 43-line `route-access-map.json` drift reduces to
  these two grants.** Checking it means regenerating that oracle, which mutates a committed fixture
  and is not in this change's scope. It is plausible — `finance.access` gates routes, and the two
  roles missing it are exactly the two the oracle flagged — but I have not proven it, and it should
  not be reported as proven.
- **The lint sees `grantsMap()` through the diff, so it cannot see a grant added by editing a shared
  fragment** (`$activityAdmin` and friends) **in a way that changes which roles receive it** beyond
  reporting the permission with role `?`. The permission and file:line are exact; the role is not.
  This is the briefed imprecision, narrowed but not eliminated.
- **No arm covers `ATTACHED_THEN_LOST` or `DETACHED_THEN_REGAINED`.** They are reachable (raw
  `syncPermissions`) and the `match` arms are simple, but they are untested and I am flagging that
  rather than claiming six proven diagnoses. Four are proven by arms; two are code-reviewed only.
- **`--fresh` was never run against `portaa10_portal`,** and the copy was not written to at any point.

---

## Appendix — exemption 3 boundary fix, and permanent arms for exemptions 3 and 4

Second commit on the branch, on top of `eafe54f`. **Scope held:** only
`bin/ci-grants-convergence-lint.php` and `tests/Feature/Rbac/GrantsConvergenceLintTest.php` are
touched. The command, the runbook, `docs/testing.md` and `bin/quality` are unchanged.

## The defect, confirmed against the enum rather than accepted

Exemption 3 tested the migration's content with `str_contains($content, $permission)` — a raw
substring match. Re-derived from `app/Enums/Permission.php` at HEAD (79 values), by comparing every
value against every other:

```
PREFIX pairs: 9
  activity_log.view      ⊂ activity_log.view_all / .view_own / .view_system / .view_cross_school / .view_sensitive
  guardian.view          ⊂ guardian.view_audit
  guardian.update        ⊂ guardian.update_credentials
  student_subject.view   ⊂ student_subject.view_history
  result.view            ⊂ result.view_scores
SUFFIX pairs: 0
MID pairs:    0
```

So a diff adding `activity_log.view` to a pre-existing role, alongside a migration naming only
`activity_log.view_all`, was exempted with no migration for the permission actually added. **A
silent green, in the class the gate exists for** — the worst failure a gate can have, because it is
indistinguishable from working.

## The expression, verified in both directions before adoption

The brief's proposal was not taken on trust. `preg_match('/'.preg_quote($p,'/').'(?![A-Za-z0-9_.\-])/', $c)`
was run against 13 cases covering both directions:

```
ok    sibling only  -> NOT exempt (the bug)                expected=false got=false
ok    exact sibling -> exempt                              expected=true  got=true
ok    quoted exact -> exempt                               expected=true  got=true
ok    space-delimited exact -> exempt                      expected=true  got=true
ok    end of string -> exempt                              expected=true  got=true
ok    prefix pair 2                                        expected=false got=false
ok    prefix pair 3                                        expected=false got=false
ok    prefix pair 4                                        expected=false got=false
ok    prefix pair 5                                        expected=false got=false
ok    hyphen+dot name exact                                expected=true  got=true
ok    truncated -> not exempt                              expected=false got=false
ok    KNOWN false-negative: trailing prose period          expected=false got=false
ok    prose, space after                                   expected=true  got=true
ALL 13 AS EXPECTED
```

Adopted as `namesPermission()`. Two decisions inside it are derived, not incidental, and both are in
its docblock:

- **Right boundary only.** There are 0 suffix pairs and 0 mid pairs, so no enum value can be matched
  inside the tail of another and a mirror lookbehind would guard nothing today. It is left out
  deliberately rather than added defensively; a future permission that is a *suffix* of an existing
  one would need it.
- **`.` stays in the forbidden-following set,** which makes a comment ending "…grants
  `finance.access`." not count as naming it. All nine of today's pairs extend with `_`, so `.` could
  be dropped — but a future `finance.access.read` reopens the hole, and the two error directions are
  not symmetric: a false negative fires the gate and a human reads the message; a false positive is a
  silent green.

## Watched red — the sibling case, before and after

Same fixture range both times (`76b3159..570a737`): a pre-existing permission added to a
pre-existing role, with a migration naming only `activity_log.view_all`.

**Before** (`namesPermission` reverted to `return str_contains($content, $permission);`):

```
grants-convergence-lint: OK — no unexempted grant addition in database/seeders/RbacSeeder.php (76b3159..570a737; 1 exempted).
  · activity_log.view @ database/seeders/RbacSeeder.php:18 — exempt: migration [database/migrations/2099_01_01_000000_converge.php] in this diff names it
EXIT=0
```

The lint states that the migration "names it". It does not. That is the silent green, verbatim.

**After** (boundary match restored):

```
grants-convergence-lint: 1 grant addition(s) in database/seeders/RbacSeeder.php that rbac:sync will NOT apply (76b3159..570a737):

  ✗ activity_log.view  @  database/seeders/RbacSeeder.php:18
      role: auditor (INFERRED from the nearest preceding '<role>' => [ — verify it)
      line: PermissionEnum::ACTIVITY_LOG_VIEW->value,
…
EXIT=1
```

The arm itself goes red under the same mutation:

```
{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":4,
 "test":"…exemption 3 — a migration naming the permission EXACTLY exempts it, one naming only a longer sibling does NOT",
 "file":"tests/Feature/Rbac/GrantsConvergenceLintTest.php","line":260,
 "message":"Failed asserting that 0 is identical to 1."}
```

## The two new arms

**Exemption 4 is REAL HISTORY, not a fixture.** `cf9d2a2` created `SUPER_ADMIN_PLATFORM` with four
already-existing permissions, wired `'super_admin' => self::SUPER_ADMIN_PLATFORM` into `grantsMap()`,
and added no migration. Replayed:

```
grants-convergence-lint: OK — no unexempted grant addition in database/seeders/RbacSeeder.php (4d256f6..cf9d2a2; 4 exempted).
  · rbac.impersonate @ …:58 — exempt: inside SUPER_ADMIN_PLATFORM (self-healed by syncPermissions every run, RbacSeeder.php:506-512)
  · rbac.manage_users @ …:59 — exempt: inside SUPER_ADMIN_PLATFORM …
  · activity_log.view_system @ …:60 — exempt: inside SUPER_ADMIN_PLATFORM …
  · activity_log.view_cross_school @ …:61 — exempt: inside SUPER_ADMIN_PLATFORM …
EXIT=0
```

That all four took exemption 4 rather than exemption 1 (which is tested first) is itself the proof
that they pre-existed — this is a genuine exemption-4 case, not a coincidence.

**Exemption 3's arm IS fixture-driven, and this says so plainly.** The sibling shape has never
occurred in this repository, so there is no commit to replay. Searched: `01fdeda` — the convergence
migration for `a0ab3d7`'s grants — does not touch `RbacSeeder.php` at all, so it is not an
exemption-3 case either. There is no history for either half.

What the fixture is and is not:

- **Real git.** Commits are built with plumbing (`hash-object` / `update-index` / `write-tree` /
  `commit-tree`) into the object database, and the lint reads them through exactly the same
  `git diff` and `git show <rev>:<path>` calls it uses in life. Nothing about the lint is stubbed.
- **Non-mutating.** `GIT_INDEX_FILE` points at a scratch index, so `.git/index` is never written. No
  ref, branch, HEAD or working-tree file is touched; the commits are unreferenced objects collected
  by the next `git gc`. Verified after the run: `git status --short` shows only the two files this
  commit edits.
- **Minimal, therefore a fixture.** The tree holds a two-case enum, a stub seeder with `ROLES`,
  `SUPER_ADMIN_PLATFORM` and `grantsMap()`, and one migration — not a copy of the real files. The
  base already declares both permissions and the role, so neither exemption 1 nor 2 can fire and
  exemption 3 is the only question left.

`DB_DATABASE=portal_testing ./vendor/bin/pest tests/Feature/Rbac/GrantsConvergenceLintTest.php tests/Feature/Rbac/RbacDiffGrantsTest.php`

```
{"tool":"pest","result":"passed","tests":17,"passed":17,"assertions":89,"duration_ms":16874}
```

## A second gap in exemption 3, found while fixing the first — NOT fixed here

Exemption 3 requires the permission to appear as a **literal string** in the migration. The
convergence migrations this repo actually writes compose names from a prefix plus a segment, so the
full name frequently never appears. Checked by content, not assumed:

| Migration | `finance.fee-schedule.change.submit` | `finance.discount-policy.change.submit` |
| --- | --- | --- |
| `2026_08_03_100000_converge_finance_change_grants.php` | 2 hits (prose) | **0 hits** |

That migration is the convergence for both permissions, and it would satisfy exemption 3 for one of
them and not the other — and the one it satisfies, it satisfies by accident of prose rather than by
design. A developer shipping the idiomatic convergence migration can therefore be told "no migration
names it" and be right to disbelieve the gate.

This is a **false RED**, not a silent green, so it is the safe direction and it is not in the class
this commit was asked to close. It is also not fixable by widening the string test — soundly
resolving `'finance.'.$ns.'.change.'.$verb` means evaluating PHP concatenation. The honest options
are a convention (convergence migrations must name each permission literally once, e.g. in a
docblock) or a `@converges <permission>` marker the lint reads. **Ticket, needs a decision — not
smuggled in here.**

## Not done

- The mirror lookbehind (`(?<![A-Za-z0-9_.\-])`). Vacuous today: 0 suffix pairs, 0 mid pairs.
  Recorded in the `namesPermission()` docblock so the next person adding a suffix-shaped permission
  has the reason in front of them.
- `bin/quality` was not re-run for this commit: it was green on `eafe54f`, and the two files changed
  here are the lint and its own test, both of which are exercised directly above (17/17, Pint clean).
  Say so rather than implying a full-gate run happened.
