# Report — `finance.access` grant convergence

**Brief:** `docs/handoff/finance-access-convergence-brief.md` (untracked at the time of writing)
**Base:** `staging` @ `0a47974`
**Branch:** `fix/finance-access-grant-convergence`
**Shape:** one migration + one test + one shell one-liner. Two commits — `527b8dc` (convergence),
`fb55219` (quality-promote).
**Tier:** full-review — this touches RBAC grants, a migration, and a fixture-adjacent gate. Subagent
review attached; recommend a cold session before merge.

## Deviations from the brief

Two, both small, both visible here rather than in a footnote.

1. **`bin/quality-promote:94` is `:93`.** The `printf` carrying the merge instruction is line 93, not
   94 (94 is the trailing blank). Fixed the right line; the brief's premise was otherwise exact.
2. **The fresh-install guard and the target pre-flight cannot key on the same fact here.** In
   `2026_08_03_100000` the substrate key (`no permission in the two namespaces`) and the target set
   were different sets, so the two checks were distinguishable. With a single governed permission they
   would collapse: `finance.access` absent would mean both "fresh install, return quietly" and "run
   `rbac:sync` first, abort". I keyed the fresh-install guard on the whole `finance.%` namespace
   instead. So: no `finance.*` rows at all ⇒ fresh install, quiet return; `finance.*` present but
   `finance.access` missing ⇒ broken substrate, abort. That preserves both behaviours and refuses to
   return a quiet green on a broken substrate. Stated in the code comment at the guard.

Everything else in the brief is implemented as written.

## Step 0 — re-derived from the DB before writing anything

`php artisan rbac:diff-grants`, run against `portaa10_portal` **before** any change:

```
rbac:diff-grants — RbacSeeder::grantsMap() vs the live grants
 env=local db=portaa10_portal guard=web
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

TOTALS catalog: 0 missing row(s), 0 extra row(s) | grants: 2 missing, 0 extra across 2 role(s) | roles: 0 mapped-without-row, 0 unmapped
 FINDINGS (detection only — nothing was changed)
```

`--json`, same run (elided to the load-bearing keys; full output was inspected):

```json
{
  "environment": "local",
  "database": "portaa10_portal",
  "guard": "web",
  "catalog": { "missing_rows": [], "extra_rows": [] },
  "roles": {
    "head_of_school": { "role_id": 2, "missing": [{ "permission": "finance.access", "diagnosis": "SYNC_ADD_GAP", "log": null }], "extra": [] },
    "principal":      { "role_id": 10, "missing": [{ "permission": "finance.access", "diagnosis": "SYNC_ADD_GAP", "log": null }], "extra": [] }
  },
  "mapped_roles_with_no_global_row": [],
  "unmapped_global_roles": [],
  "school_scoped_role_rows": 0,
  "totals": { "catalog_missing_rows": 0, "catalog_extra_rows": 0, "roles_with_findings": 2,
              "missing_grants": 2, "extra_grants": 0, "mapped_roles_with_no_global_row": 0,
              "unmapped_global_roles": 0 },
  "verdict": "FINDINGS (detection only — nothing was changed)"
}
```

The four things the brief asked for from the DB rather than from it, each derived by direct query
(`role_has_permissions` ⋈ `roles` ⋈ `permissions`, `guard_name = 'web'`), **before** the migration:

- **Which of the six holders are missing the global pivot row:** `head_of_school` (role#2) and
  `principal` (role#10). The other four — `admin` (role#1), `accounts_officer` (role#12),
  `accounts_supervisor` (role#13), `finance_lead` (role#15) — hold it.
- **Any global role OUTSIDE the six holding it:** none. Explicitly checked: `super_admin` (role#5)
  `finance.access=no`, `internal_auditor` (role#16) `finance.access=no`. So the brief's abort case
  did not fire, and nothing was silently revoked.
- **School-scoped row count for `finance.access`:** `0` (`roles.school_id IS NOT NULL`). Also `0` rows
  in `model_has_permissions` for it — no direct user grants.
- **Any `activity_log` row recording the loss:** `0` rows whose properties mention `finance.access`.
  Consistent with SYNC_ADD_GAP: nothing was ever revoked, the grant simply never landed.

Permission row: `permissions.id=56`, `name=finance.access`, `guard_name=web`, `created_at`
`2026-07-23 23:01:00` — i.e. it pre-dates the map edit that added the two roles, which is the
precondition for the defect.

`grantsMap()` holders re-derived from the seeder, not from the brief: `RbacSeeder.php` lines 199
(`admin`), 230 (`head_of_school`), 286 (`principal`), 336 (`accounts_officer`), 359
(`accounts_supervisor`), 373 (`finance_lead`). Six, matching the brief.

## What was built

`database/migrations/2026_08_05_100000_converge_finance_access_grants.php`. Governs all six holders.
Pre-flights in this order, all before any write: target permission exists (else abort naming
`rbac:sync`); no global role outside the six holds it, each offender reported as
`<name> (holders=<n>)`; every governed role exists as a global row. School-scoped footprint echoed and
never written. Idempotency check short-circuits **before** the transaction, so a second run writes no
activity row. Writes are diff-based `revokePermissionTo` + `givePermissionTo` inside one
`DB::transaction`, so both Spatie events reach `LogRbacChange` (not `syncPermissions`, which detaches
raw with no event). `forgetCachedPermissions()` after. BEFORE/AFTER holder counts per school as
`school#<id>  finance.access  holders=<n>`. `down()` is an empty, documented no-op.

**The one deliberate omission — no post-write `DutySeparation::violations` walk.** Verified against the
code rather than assumed: `DutySeparation::pairs()` (`app/Support/DutySeparation.php:58-73`) emits a
pair only when `ApprovalAbility::isExcludedFromSuperAdminBypass($ability)` is true, which is
`terminalSegment($ability) ∈ ['approve','reject']` (`app/Support/ApprovalAbility.php:45-48`, `:40`).
`finance.access` terminates in `access`, so it is never a checker. The maker side of any pair is
`matchingMakerFor()` = prefix + `submit` (`:72-83`), so a maker is always a `*.submit` — `finance.access`
is not one. It therefore appears in no `pairs()` entry, hence in no `enforcedPairs()` entry, and
granting or revoking it can neither create nor clear a both-sides violation. Written into the migration
docblock so the omission reads as reasoned.

`tests/Feature/Rbac/FinanceAccessGrantConvergenceTest.php`, four arms (arm 4 is ordered first in the
file so the non-vacuity proof runs before the arm it protects).

`bin/quality-promote:93` now prints `git merge --ff-only staging`, with the reasoning inline.

## Proof

### Suite — all four arms green

```
DB_DATABASE=portal_testing ./vendor/bin/pest tests/Feature/Rbac/FinanceAccessGrantConvergenceTest.php
{"tool":"pest","result":"passed","tests":4,"passed":4,"assertions":32,"duration_ms":10959}
```

### The watched reds — four mutations, each planted, run, restored

**1. `givePermissionTo` disabled** in the migration's transaction (`if ($grant !== []) { }`):

```
{"result":"failed","tests":4,"passed":2,"failed":2,"failures":[
 {"test":"ARM 1 — converges the drift…","line":109,"message":"Failed asserting that false is true."},
 {"test":"ARM 2 — idempotent…","line":137,"message":"Failed asserting that false is true."}]}
```

Arm 1 and arm 2 both depend on the grant actually landing.

**2. Offender pre-flight disabled** (`if (false && $offenders !== [])`):

```
{"result":"failed","tests":4,"passed":3,"failed":1,"failures":[
 {"test":"ARM 3 — offender pre-flight bites…","line":150,"message":"Exception \"RuntimeException\" not thrown."}]}
```

**3. Idempotency short-circuit disabled AND the diff replaced by a blind revoke-then-give**
(`$revoke = $current; $grant = $target[$roleName];`) — i.e. the naive implementation arm 2 exists to
reject:

```
{"result":"failed","tests":4,"passed":3,"failed":1,"failures":[
 {"test":"ARM 2 — idempotent…","line":134,"message":"Failed asserting that 28 is identical to 16."}]}
```

The second `up()` wrote 12 extra `rbac` activity rows. Note for the reviewer: I ran the two mutations
together, because disabling the short-circuit alone would **not** have gone red — the diff is a second
line of defence and would have produced an empty revoke/grant set. So arm 2 pins "no second batch of
audit rows", which is the property that matters, rather than pinning the short-circuit specifically.

**4. The drift-planting disabled** in the test helper (the arm-4 non-vacuity proof, mutated in the
test rather than the migration):

```
{"result":"failed","tests":4,"passed":1,"failed":3,"failures":[
 {"test":"ARM 4 (bite-proof, runs first)…","line":81,"message":"Failed asserting that true is false."},
 {"test":"ARM 1 — converges the drift…","line":101,"message":"Failed asserting that true is false."},
 {"test":"ARM 3 — offender pre-flight bites…","line":154,"message":"Failed asserting that true is false."}]}
```

Three arms collapse when the drift is not planted. None of them can pass on an already-converged
database.

Both files were restored from a pre-mutation copy and re-verified: `grep -c "BITE-PROOF"` returns 0 in
each, and the four-arm green above was re-run from the restored files.

### Applied to the local production copy

Two unrelated migrations from another stream were pending locally
(`2026_08_01_120000_add_show_behaviour_comment_on_result_to_schools`,
`2026_08_02_100000_create_notification_tables`). I ran **only** this one, by `--path`, rather than
running theirs as a side effect.

```
php artisan migrate --path=database/migrations/2026_08_05_100000_converge_finance_access_grants.php --force

 2026_08_05_100000_converge_finance_access_grants
  converge-finance-access-grants: school-scoped role rows carrying [finance.access] (UNTOUCHED): 0
  converge-finance-access-grants [BEFORE] holders per school:
    school#1  finance.access  holders=4
    school#2  finance.access  holders=4
  converge-finance-access-grants [AFTER] holders per school:
    school#1  finance.access  holders=9
    school#2  finance.access  holders=5
.. 5s DONE
```

Post-state, re-derived:

- `rbac:diff-grants` → `CLEAN — grantsMap() and the live grants agree.` (0 missing, 0 extra, 0 roles
  with findings; catalog still clean; school-scoped rows still 0).
- Global holders of `finance.access` now: role#1 `admin`, role#2 `head_of_school`, role#10
  `principal`, role#12 `accounts_officer`, role#13 `accounts_supervisor`, role#15 `finance_lead`.
  `super_admin` and `internal_auditor` still do not hold it.
- Exactly **two** `activity_log` rows written (ids 179464, 179465; `log_name=rbac`,
  `permission_attached: finance.access`, `subject_type=App\Models\Role`, `subject_id` 2 and 10,
  `causer_id` null). One per converged role — the audit trail the brief required.

### Gates

`bin/quality` — 13/13 green, base `0a47974`:

```
[1/13] wayfinder ✓  [2/13] lint-changed ✓  [3/13] tsc-ratchet ✓  [4/13] build ✓
[5/13] authz-lint ✓ [6/13] boundary-lint ✓ [7/13] grants-convergence-lint ✓ [8/13] money-lint ✓
[9/13] runtime-zero-lint ✓ [10/13] identifier-generation-lint ✓ [11/13] arch ✓
[12/13] larastan ✓ [13/13] test-ratchet ✓
✓ quality: PASS
```

**The grants-convergence lint did not go red**, as the brief predicted it should not: `grantsMap()` is
unchanged in this diff, and the lint fires on map additions only. Had it gone red I would have stopped.

## What I did not do

- **Did not re-run the migration a second time against the live copy** to observe the idempotent path
  in the real environment. `migrate` will not re-run it, and forcing a second `up()` there is a write
  attempt against ground truth for a property arm 2 already pins under a watched red. Assumption
  stated: the short-circuit behaves the same on production data as on a seeded test database. It reads
  only `role_has_permissions` and `grantsMap()`, so I believe that holds, but I did not observe it.
- **Did not drive the UI.** `finance.access` gates the finance page shells and six GET reads in
  `routes/endpoints/finance.php`; I verified the grant landed at the pivot and through
  `hasPermissionTo`, not by logging in as a `head_of_school` holder and loading a finance page. That is
  the one gap between "the grant exists" and "the seat can reach the surface".
- **Did not touch the two pending migrations** from the other stream, and did not run
  `bin/quality-promote` (it is a release gate for `staging → main`, not for this branch) — so the
  `--ff-only` line is verified by reading `.githooks/pre-push:48` and `:52-61`, not by executing a
  promote.
- **Did not push.** Two commits sit on `fix/finance-access-grant-convergence`.

## Anything in the brief I think is wrong

Only the line number (`:94` → `:93`) and the fresh-install/pre-flight collapse noted at the top. The
diagnosis, the governed set, the `internal_auditor` exclusion, the DutySeparation reasoning and the
"expect no lint red" prediction all held against the repo and the database.
