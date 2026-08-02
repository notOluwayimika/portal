# Implementation report — `fix/revoke-ia-cross-school`

**This is full-review tier — subagent review attached, recommend a cold session before merge.**
Roles, grants, a fixture oracle and `school_id` isolation.

---

## Headline

Done, with two deviations. `activity_log.view_cross_school` is removed from `internal_auditor` in
`RbacSeeder::grantsMap()` and revoked on already-seeded environments by a new migration; the
forbidden set now has a single named home (`PermissionEnum::ISOLATION_CROSSING`) read by both the
seeded-map pin and a new runtime matrix guard. Branch `fix/revoke-ia-cross-school`, based on
`ac9c7c7` (`docs/stale-payment-gate-claim`) — `ac9c7c7` is **not** on `staging` (`staging` is at
`0672ed8`), so the brief's base was correct and unchanged.

## Follow-up commit (on `4d4c9c5`)

Review came back "ship with fixes". This report now covers two commits; everything below is the
final state. What the follow-up changed, and what it did **not**:

1. **`ARM D`'s window is an id watermark, not an offset.** `offset($countBefore)` with no `ORDER BY`
   has no guaranteed row order in MySQL — it only happened to work under InnoDB primary-key order.
   Now `$maxId = max(id)` before `up()`, then `where('id','>',$maxId)->orderBy('id')`. Assertions
   unchanged.
2. **The `report()` docblock's "covers ALL holders" sentence is replaced** — it was false; see
   "Database observations". The query is untouched. The realign and converge migrations' docblocks
   are accurate for their own queries and were not touched.
3. **The `grantsMap()` guard is watched red on scratch** (Red 3 below), and `ARM E`'s note is
   corrected: the reason it is not a committed test is not "no seam", it is that the mutation is an
   on-disk seeder edit a test cannot carry.
4. **`bin/quality` re-run against `4d4c9c5`**, because the first run's step 2 covered none of the
   change and my reading of why was backwards. Corrected in place, with the real changed-file set.
   The delta table below is re-derived from `git diff --numstat` rather than hand-written.

**Not in this commit, deliberately:** the `finance.access` gap. The review is right that my
"most plausibly a runtime C6 matrix revoke" was wrong — zero `rbac` rows mention `finance.access`
across the log window, so it cannot have been a matrix revoke, and it is the non-destructive-sync
ADD gap, which means it reproduces on production rather than being a local quirk. That correction
belongs to the separate change on `staging` that fixes it, not to this branch; the finding below is
left as first written so the two texts can be read against each other.

## Deviations from the brief

**1. The isolation-crossing list lives on the enum, not in the test file.** Item 16 said "a named
`const` array in the test"; item 17 said derive the Part 3 and Part 4 sets from a single named
source and "say where you put it and why". Those pull apart — a const private to the test cannot be
read by `SyncRolePermissionsRequest`. I put it at
[app/Enums/Permission.php:172-197](app/Enums/Permission.php#L172-L197) (docblock `:173`, the const
itself `:195-197`) as
`Permission::ISOLATION_CROSSING`, a `list<string>` with one member built from the enum case (not a
string literal). Why the enum: it is already the single authoritative registry of permission names
(the `rules()` docblock calls it "the enum is code"), both consumers already import it, and the
migration can read it too — which it does, as its opening guard. The "itself asserted" half item 16
asked for is three tests in `GrantsMapSeparationTest`, including one that fails if the list ever
stops naming a real declared permission (without it every downstream assertion passes vacuously).
I did **not** invent a second member. I considered and rejected `activity_log.view_system`: it
widens a read to school-less rows within the holder's own context, it does not read another
School's rows — noted in the constant's docblock so the next person does not re-open it.

**2. The change is 8 files, not the 7 the brief's shape line implies.** Shape given was "1 migration
+ 1 seeder edit + 1 request rule + 3 test files + 1 fixture + 1 doc line". All of those are present
and unchanged in count; `app/Enums/Permission.php` is the extra, and it exists because of deviation 1.

## Contradictions of the premise

**None in the brief's finding.** Every load-bearing claim reproduced:

- `RbacSeeder.php:398` carried `PermissionEnum::ACTIVITY_LOG_VIEW_CROSS_SCHOOL->value` under
  `'internal_auditor'`. Confirmed before editing.
- `v10:375` carries the exact quoted sentence ("is read-shaped, is in scope, and must not be
  granted"). Grepped, line number exact.
- [ActivityLogQueryService.php:42-52](app/Services/ActivityLog/ActivityLogQueryService.php#L42-L52)
  drops the school predicate entirely for a holder; `:55-57` is the `view_all` self-caused
  restriction that bounds it today. Both read.
- `super_admin` holds it via `SUPER_ADMIN_PLATFORM`
  ([RbacSeeder.php:57-62](database/seeders/RbacSeeder.php#L57-L62)), self-healed at `:503-512`.
  Both correction 1 and correction 2 in the brief are accurate; `SchoolAwareJobsTest.php:172` was
  not touched.
- The grant is **live on the local production copy**: a pre-migration diff of live grants against
  `grantsMap()` returned `internal_auditor missing=[] extra=["activity_log.view_cross_school"]`.

**One thing the brief did not anticipate, and it changed how Part 6 was run.** See "The
`route-access-map.json` STOP" below — the pinned expectation was met, but only after establishing
which database the oracles are generated from.

## What changed

Re-derived with `git diff --numstat ac9c7c7` (modified files) and `wc -l` (new files) against the
final tree, **after** the follow-up commit. The first version of this table was hand-written and four
of its nine rows were wrong; these are machine-derived.

| File | Δ | What |
| --- | --- | --- |
| [app/Enums/Permission.php](app/Enums/Permission.php) | +27 −0 | `ISOLATION_CROSSING` — the single named source, with the reasoning and the `view_system` non-membership recorded. |
| [database/seeders/RbacSeeder.php](database/seeders/RbacSeeder.php) | +16 −1 | Removes the grant from the `internal_auditor` entry; extends the existing block comment (the `finance.access` paragraph is untouched). |
| [database/migrations/2026_08_04_100000_revoke_internal_auditor_cross_school.php](database/migrations/2026_08_04_100000_revoke_internal_auditor_cross_school.php) | 227 lines, new | The revocation. Fresh guard, three pre-flights, idempotency, diff-based `revokePermissionTo` in a transaction, BEFORE/AFTER report, no-op `down()`. |
| [app/Http/Requests/SyncRolePermissionsRequest.php](app/Http/Requests/SyncRolePermissionsRequest.php) | +29 −3 | Runtime C6 matrix guard rejecting any `ISOLATION_CROSSING` member. (The −3 is hoisting the existing `$requested` read above both rules — no behaviour change.) |
| [tests/Feature/Rbac/GrantsMapSeparationTest.php](tests/Feature/Rbac/GrantsMapSeparationTest.php) | +55 −0 | Three assertions: the list names real permissions; no role but `super_admin` grants a member; `super_admin`'s holding comes through `SUPER_ADMIN_PLATFORM`. |
| [tests/Feature/Rbac/SuperAdminMatrixTest.php](tests/Feature/Rbac/SuperAdminMatrixTest.php) | +56 −0 | Three arms on the runtime guard, incl. the confirmation that `super_admin` is unreachable through the matrix. |
| [tests/Feature/Rbac/InternalAuditorCrossSchoolRevocationTest.php](tests/Feature/Rbac/InternalAuditorCrossSchoolRevocationTest.php) | 160 lines, new | Migration arms A–E. |
| [tests/fixtures/rbac-grants-baseline.json](tests/fixtures/rbac-grants-baseline.json) | +1 −2 | One grant leaves the `internal_auditor` array. |
| [docs/rbac/finance-seat-realignment.md](docs/rbac/finance-seat-realignment.md) | +12 −2 | `:45` rewritten as granted-then-revoked, naming `a0ab3d7`, `v10:375` and the migration. |

## Proof

### Part 4 item 18 — `super_admin` is unreachable through the matrix

Read, not assumed.
[SyncRolePermissionsRequest.php:33](app/Http/Requests/SyncRolePermissionsRequest.php#L33):

```php
return $this->route('roleName') !== 'super_admin';
```

`authorize()` returns false before the validator runs, so the new rule cannot strand the one
sanctioned holder. Pinned by a new test arm (`super_admin is not stranded by the isolation rule`),
which asserts its grants survive the attempt **and** still contain the crossing permission.

### Part 6 — the oracles, in order

Run in the brief's order: `rbac:sync` → `rbac:derive-access` → the grants baseline →
`rbac:derive-map`.

#### The `route-access-map.json` STOP, and why the pinned expectation still holds

First attempt, against the **dev copy** (`portaa10_portal`), tripped the brief's STOP:

```
tests/fixtures/route-access-map.json | 43 ++++++------------------------------
 1 file changed, 7 insertions(+), 36 deletions(-)
```

Classified per the brief. **Zero (a) lines** — not one changed line mentions `internal_auditor` or
`activity_log.view_cross_school`. **All 43 are (b)**, and they share one root cause: `head_of_school`
and `principal` losing `finance.access` on the dev copy. Every (b) line, in full:

```
  @@ -1148,8 +1148,6 @@            -"head_of_school", -"principal"      (GET /api/v1/finance/discount-policies)
  @@ -1166,22 +1164,16 @@           -"head_of_school", -"principal"
                                     "GET /api/v1/finance/discount-policy-changes/pending": roles ["head_of_school"] -> []
                                     "GET /api/v1/finance/fee-schedule-changes/pending":    roles ["head_of_school"] -> []
  @@ -1190,8 +1182,6 @@            -"head_of_school", -"principal"
  @@ -1202,8 +1192,6 @@            -"head_of_school", -"principal"
  @@ -1214,8 +1202,6 @@            -"head_of_school", -"principal"
  @@ -1226,8 +1212,6 @@            -"head_of_school", -"principal"
  @@ -1293,16 +1277,13 @@           -"head_of_school", -"principal"
                                     "GET /finance/approvals": ["accounts_supervisor","head_of_school"] -> ["accounts_supervisor"]
  @@ -1312,8 +1293,6 @@            -"head_of_school", -"principal"
  @@ -2745,15 +2724,11 @@           "POST .../discount-policy-changes/{change}/approve": ["head_of_school"] -> []
                                     "POST .../discount-policy-changes/{change}/reject":  ["head_of_school"] -> []
  @@ -2765,15 +2740,11 @@           "POST .../fee-schedule-changes/{change}/approve":    ["head_of_school"] -> []
                                     "POST .../fee-schedule-changes/{change}/reject":     ["head_of_school"] -> []
```

I reverted that regeneration (`git checkout tests/fixtures/route-access-map.json`) and derived the
cause rather than committing it. Live grants vs `grantsMap()` on the dev copy:

```
head_of_school missing=["finance.access"] extra=[]
principal missing=["finance.access"] extra=[]
accounts_officer missing=[] extra=[]
accounts_supervisor missing=[] extra=[]
finance_lead missing=[] extra=[]
internal_auditor missing=[] extra=["activity_log.view_cross_school"]
```

`migrate:status` confirms both `2026_08_02_100000_realign_finance_governance_grants` and
`2026_08_03_100000_converge_finance_change_grants` have **Ran** on the dev copy, so this is not an
unrun migration. It is local drift on a production copy — most likely a runtime matrix revoke, which
is C6 local authority and explicitly not mine to "fix". Raised as a finding below; **untouched**.

The conclusion that matters for this change: the committed oracles are generated from a **freshly
seeded** database, not the dev copy. Regenerated against `portal_testing` after
`migrate:fresh --seed`, all three commands in the brief's order:

```
=== 22. rbac:sync ===
rbac:sync — roles/permissions synced; existing grants preserved (non-destructive).
=== 23. rbac:derive-access ===
route-access-map.json written (350 routes).
=== 25. rbac:derive-map ===
route-middleware-baseline.json written (350 routes).
=== fixture diff ===
(empty)
```

**`route-access-map.json`: no diff. `route-middleware-baseline.json`: no diff.** The pinned
expectation is met exactly.

The `migrate:fresh` also exercised the fresh-install guard (item 6) for free:

```
2026_08_04_100000_revoke_internal_auditor_cross_school   revoke-ia-cross-school: RBAC substrate unseeded [activity_log.view_cross_school] absent — nothing to revoke.
.. 0.90ms DONE
```

#### Item 24 — the grants baseline

No command exists, so it was produced with the exact expression
[PermissionEnumTest.php:30-41](tests/Feature/Rbac/PermissionEnumTest.php#L30-L41) asserts against
(web guard → sorted permission names → `sortKeys()`). The command, verbatim:

```bash
DB_DATABASE=portal_testing php artisan tinker --execute='
$webRoles = App\Models\Role::with("permissions")->where("guard_name", "web")->get();
$actual = $webRoles
    ->mapWithKeys(fn ($r) => [$r->name => $r->permissions->pluck("name")->sort()->values()->all()])
    ->sortKeys()
    ->all();
file_put_contents(
    base_path("tests/fixtures/rbac-grants-baseline.json"),
    json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n"
);
echo "roles=".count($actual)."\n";
'
```

Output `roles=14`. Diff:

```
tests/fixtures/rbac-grants-baseline.json | 3 +--
 1 file changed, 1 insertion(+), 2 deletions(-)

  @@ -168,8 +168,7 @@
  -        "activity_log.view",
  -        "activity_log.view_cross_school"
  +        "activity_log.view"
       ],
       "key_stage_coordinator": [
```

**Exactly one grant leaves, from the `internal_auditor` array.** (The hunk shows −2/+1 because the
preceding line's trailing comma changes; the semantic change is the one removed grant.) Post-write
state of both arrays:

```
169:    "internal_auditor": [
170-        "activity_log.export",
171-        "activity_log.view"
172-    ],
195:    "super_admin": [
196-        "activity_log.view_cross_school",
197-        "activity_log.view_system",
198-        "rbac.impersonate",
199-        "rbac.manage_users"
200-    ],
```

`super_admin` **unchanged**, and it is the only remaining occurrence of the permission in the file.
Note the array moved from `:172`/`:197` (brief) to `:169-172`/`:195-200` — the brief's line numbers
were for the pre-change file.

### Item 28 — filtered suites, raw

```
=== --filter=Rbac ===
{"tool":"pest","result":"passed","tests":246,"passed":246,"assertions":904,"duration_ms":64095,"risky":2}

=== --filter=ActivityLog ===
{"tool":"pest","result":"failed","tests":34,"passed":30,"assertions":86,"duration_ms":18510,"failed":4,
 "failures":[
  "ActivityLogApiTest::it blocks users without activity_log.view — Expected response status code [403] but received 200.",
  "ActivityLogApiTest::it returns a paginated scoped feed — Failed asserting that 4 is identical to 2.",
  "ActivityLogApiTest::it does not leak activity across schools — Failed asserting that 3 is identical to 1.",
  "ActivityLogApiTest::it hides sensitive entries without view_sensitive — Failed asserting that 3 is identical to 1."]}
```

All four are **pre-existing and frozen** — `tests/ratchet-baseline.txt` lines 1–4 name exactly these
four tests. Not regressions, and not fixed here (the brief bars fixing an unrelated red inside this
change). Verify with `grep -n ActivityLogApi tests/ratchet-baseline.txt`.

### Item 29 — `bin/quality`

All 12 steps green, run on the branch tip. Raw tail:

```
quality gate — base 0672ed8

[1/12] wayfinder:generate --with-form (must match vite.config.ts formVariants)
   ✓ wayfinder:generate
[2/12] lint changed files (Pint / Prettier / ESLint, check mode)
   ✓ lint-changed
[3/12] types (tsc ratchet vs tsc-baseline)
   ✓ tsc-ratchet
[4/12] frontend build (vite — catches what the tsc ratchet structurally cannot)
   ✓ build
[5/12] authorization guard (no new commented-out checks)
   ✓ authz-lint
[6/12] boundary lint (§17.2)
   ✓ boundary-lint
[7/12] money lint (UI: money via formatNaira, no JS money math)
   ✓ money-lint
[8/12] runtime-zero lint (S7 legacy access sources)
   ✓ runtime-zero-lint
[9/12] identifier-generation bypass guard (1.4b)
   ✓ identifier-generation-lint
[10/12] architecture tests (§17.1)
   ✓ arch
[11/12] static analysis (Larastan level 5 vs baseline)
   ✓ larastan
[12/12] tests (failure ratchet vs tests/ratchet-baseline.txt)
   ✓ test-ratchet

✓ quality: PASS — per-push floor. Promoting to main? run bin/quality-promote.
```

**Correction — that run's step 2 covered none of this change, and my first reading of it was
backwards.** I wrote that a gate base of `0672ed8` made the changed-file lint "wider than my diff,
and wider is not weaker". It was **narrower**. `bin/lint-changed.sh:50` diffs `"$BASE"...HEAD`, and
at that moment `HEAD` was still `ac9c7c7` — the whole change was uncommitted, most of it untracked —
so the set was three files (`RbacSeeder.php` plus two docs) and Pint never saw `Permission.php`,
`SyncRolePermissionsRequest.php`, the migration, or any of the three test files. Steps 3–12 scan the
working tree and were unaffected, so the suite, lints, arch and Larastan results stand; step 2's
green did not cover the change.

**Re-run against the commit.** With `4d4c9c5` on the branch, the same command's changed-file set is
the real one:

```
app/Enums/Permission.php
app/Http/Requests/SyncRolePermissionsRequest.php
database/migrations/2026_08_04_100000_revoke_internal_auditor_cross_school.php
database/seeders/RbacSeeder.php
docs/handoff/ia-cross-school-revocation-brief.md
docs/handoff/reports/docs-stale-payment-gate-claim.md
docs/handoff/reports/fix-revoke-ia-cross-school.md
docs/rbac/finance-seat-realignment.md
tests/Feature/Rbac/GrantsMapSeparationTest.php
tests/Feature/Rbac/InternalAuditorCrossSchoolRevocationTest.php
tests/Feature/Rbac/SuperAdminMatrixTest.php
tests/fixtures/rbac-grants-baseline.json
```

Raw tail of the re-run:

```
quality gate — base 0672ed8

[1/12] wayfinder:generate --with-form (must match vite.config.ts formVariants)
   ✓ wayfinder:generate
[2/12] lint changed files (Pint / Prettier / ESLint, check mode)
   ✓ lint-changed
[3/12] types (tsc ratchet vs tsc-baseline)
   ✓ tsc-ratchet
[4/12] frontend build (vite — catches what the tsc ratchet structurally cannot)
   ✓ build
[5/12] authorization guard (no new commented-out checks)
   ✓ authz-lint
[6/12] boundary lint (§17.2)
   ✓ boundary-lint
[7/12] money lint (UI: money via formatNaira, no JS money math)
   ✓ money-lint
[8/12] runtime-zero lint (S7 legacy access sources)
   ✓ runtime-zero-lint
[9/12] identifier-generation bypass guard (1.4b)
   ✓ identifier-generation-lint
[10/12] architecture tests (§17.1)
   ✓ arch
[11/12] static analysis (Larastan level 5 vs baseline)
   ✓ larastan
[12/12] tests (failure ratchet vs tests/ratchet-baseline.txt)
   ✓ test-ratchet

✓ quality: PASS — per-push floor. Promoting to main? run bin/quality-promote.
```

The base line still reads `0672ed8` — that is `git merge-base HEAD staging` and is correct; what
changed is that `HEAD` is now `4d4c9c5` rather than the base commit, so the `"$BASE"...HEAD` set is
the twelve files listed above and step 2's green covers the change.

**Honest limit on this run:** it was taken against `4d4c9c5`, before the follow-up commit existed.
The follow-up touches three files — the migration docblock (a comment), `ARM D`'s query, and this
report — and I ran `pint --test` over the two PHP files (`{"tool":"pint","result":"passed"}`) and the
full revocation test file (5 passed, 18 assertions) after those edits. A third full-gate run on the
follow-up commit has not been done.

Step 12 is the failure ratchet, so it passing means the four `ActivityLogApiTest` failures above are
within the frozen baseline — the ratchet is the gate, not a bare Pest exit code.

## The watched red

Both required reds produced, both messages checked, both restored.

### Red 1 — Arm (a): comment out the `revokePermissionTo` call

Mutation, in the migration's transaction:

```php
-            $role->revokePermissionTo(self::PERMISSION);
+            // WATCHED RED: $role->revokePermissionTo(self::PERMISSION);
```

```
{"tool":"pest","result":"failed","tests":5,"passed":3,"assertions":12,"failed":2,"failures":[
 {"test":"...ARM_A — revokes the grant from internal_auditor and touches nothing else",
  "file":"tests/Feature/Rbac/InternalAuditorCrossSchoolRevocationTest.php","line":78,
  "message":"Expecting […] not to contain 'activity_log.view_cross_school'."},
 {"test":"...ARM_D — the revocation is AUDITED: exactly one rbac row, a permission_detached naming it",
  "file":"tests/Feature/Rbac/InternalAuditorCrossSchoolRevocationTest.php","line":135,
  "message":"Failed asserting that actual size 0 matches expected size 1."}]}
```

The failure message **names the permission**, not "false is not true". Arm D went red alongside,
which is the intended coupling: no revoke, no audit row.

Restored (`git diff --stat` on the migration returns empty — it is an untracked new file, verified
by re-reading the line), green:

```
{"tool":"pest","result":"passed","tests":5,"passed":5,"assertions":18,"duration_ms":8221}
```

### Red 2 — Part 4: disable the new validator rule

Mutation, in `withValidator()`:

```php
-                if (! is_string($ability) || ! in_array($ability, PermissionEnum::ISOLATION_CROSSING, true)) {
+                if (true) { // WATCHED RED: isolation rule disabled
```

**First attempt named the wrong thing, and I changed the test rather than accept it.** With the
assertions in their original order the red read:

```
"message":"Session is missing expected key [errors].\nFailed asserting that false is true."
```

True but useless — it does not say *what* was wrongly granted. I reordered both arms to assert the
outcome (`the role must not now hold the permission`) **before** the response assertions. Re-run,
same mutation:

```
{"tool":"pest","result":"failed","tests":15,"passed":13,"assertions":45,"failed":2,"failures":[
 {"test":"...it refuses an edit whose resulting set contains an isolation-crossing permission (ADR 0036)",
  "file":"tests/Feature/Rbac/SuperAdminMatrixTest.php","line":156,
  "message":"Expecting […] not to contain 'activity_log.view_cross_school'."},
 {"test":"...the isolation rule is about the permission, not the role — any matrix-reachable role is refused",
  "file":"tests/Feature/Rbac/SuperAdminMatrixTest.php","line":172,
  "message":"Expecting […] not to contain 'activity_log.view_cross_school'."}]}
```

Now the permission is the subject of the failure. Restored, green:

```
{"tool":"pest","result":"passed","tests":15,"passed":15,"assertions":54,"duration_ms":11482}
```

### Red 3 — the `grantsMap()` guard, proven on scratch

Added in the follow-up commit. The first version of this report called this guard unproven and gave
the reason as "`grantsMap()` is a static method with no seam". **That reason was wrong** — the
mutation is an on-disk edit to a source file, exactly like reds 1 and 2, and nothing about a static
method prevented it. The real constraint is only that it cannot be a *committed* test: an arm would
have to rewrite `RbacSeeder.php` mid-run and undo the very map edit this change ships. So it is
watched on scratch and recorded here.

Mutation — restore the map line at
[database/seeders/RbacSeeder.php:398](database/seeders/RbacSeeder.php#L398):

```php
             'internal_auditor' => [
                 PermissionEnum::ACTIVITY_LOG_VIEW->value,
                 PermissionEnum::ACTIVITY_LOG_EXPORT->value,
+                PermissionEnum::ACTIVITY_LOG_VIEW_CROSS_SCHOOL->value, // SCRATCH WATCHED RED
             ],
```

`ARM A` red:

```
{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":2,"errors":1,"error_details":[
 {"test":"...ARM A — revokes the grant from internal_auditor and touches nothing else",
  "file":"database/migrations/2026_08_04_100000_revoke_internal_auditor_cross_school.php","line":98,
  "message":"revoke-ia-cross-school ABORTED: RbacSeeder::grantsMap() still grants [activity_log.view_cross_school] to [internal_auditor] — the seeder edit is missing, so a revocation here would be undone by the next fresh sync."}]}
```

The guard fires, at `:98`, naming both `grantsMap()` and the permission. Restored — `git diff HEAD
--stat -- database/seeders/RbacSeeder.php` returns empty, i.e. byte-identical to `4d4c9c5` — and
green:

```
{"tool":"pest","result":"passed","tests":5,"passed":5,"assertions":18,"duration_ms":8673}
```

All three of the migration's abort paths are now watched: this one, the third-holder pre-flight
(`ARM C`, a committed test), and the revoke itself (red 1).

## Database observations

Privacy rule applied throughout: `school#<id>`, counts only.

**Item 30 — `internal_auditor` holders per school, on the dev copy:**

```
internal_auditor: NO holders in any school
schools total=2
school-scoped role rows carrying the permission=0
```

**Zero holders in every school.** Stating the consequence plainly, because it cuts both ways: no
human currently reads another School's activity log through this grant, so the production impact of
the grant *today* is nil — and that is exactly why it was easy to miss. It is not a reason to skip
anything: the grant was on the role, and the Phase 2 derivation that adds `view_all` would have
armed it for whoever is seated next.

**The migration, run against the dev copy** (`--path=` scoped to this migration alone, so the one
unrelated pending migration was not dragged in):

```
 2026_08_04_100000_revoke_internal_auditor_cross_school   revoke-ia-cross-school: school-scoped role rows carrying [activity_log.view_cross_school] (UNTOUCHED): 0
  revoke-ia-cross-school [BEFORE] holders of [activity_log.view_cross_school] per school:
    school#1  activity_log.view_cross_school  holders=0
    school#2  activity_log.view_cross_school  holders=0
  revoke-ia-cross-school [AFTER] holders of [activity_log.view_cross_school] per school:
    school#1  activity_log.view_cross_school  holders=0
    school#2  activity_log.view_cross_school  holders=0
.. 3s DONE
```

BEFORE and AFTER are identical at zero, and that is expected rather than a broken report:
`holdsViaGrant` resolves under a per-school team id, `internal_auditor` has no holders anywhere, and
`super_admin` is the team-less role — so no user resolves the permission within either school's team
context either way. The report proves the migration's *observation* path runs; the grant change
itself is proven by the role rows below.

The migration's `report()` docblock originally claimed it "covers ALL holders, so `super_admin`'s
unchanged holding is visible either side". **That was false** and is corrected in the follow-up
commit. `super_admin` is excluded twice over and could never appear in either block, in any
environment: the `model_has_roles` query filters on the school's `school_id`, and `holdsViaGrant`
sets the team id before reading `roles()`, which Spatie constrains with
`wherePivot($teamsKey, getPermissionsTeamId())`
([vendor/spatie/laravel-permission/src/Traits/HasRoles.php:74](vendor/spatie/laravel-permission/src/Traits/HasRoles.php#L74),
read to confirm) — and `super_admin` is assigned at team NULL. The query is unchanged; it is correct
for the per-school question it asks. `super_admin`'s holding is evidenced by `ARM A` asserting the
role's grants directly, not by this report.

**Post-migration state on the dev copy:**

```
internal_auditor => ["activity_log.export","activity_log.view"]
super_admin      => ["activity_log.view_cross_school","activity_log.view_system","rbac.impersonate","rbac.manage_users"]

latest rbac detach row: id=179462 event=permission_detached
  props={"permissions": ["activity_log.view_cross_school"], "team_school_id": null, "active_school_id": null}
  causer_id=NULL subject_type=App\Models\Role subject_id=16
```

The grant is gone, `super_admin` is intact, and the removal wrote exactly one audited
`permission_detached` row against the role. `causer_id` is NULL because a migration has no
authenticated actor — provenance is the migration, and the properties name the permission.

## Not done

- **The migration's second guard has no committed test, by necessity — but it is watched red.** See
  "Red 3" above. An arm cannot carry it: the mutation is an on-disk seeder edit, and the thing it
  would have to undo is the map change this branch ships. `ARM E` records that limit in a comment
  and pins the constant the three consumers share.
- **`down()` is a deliberate no-op** (item 13), so there is no rollback audit to run. The four-path
  `--step=N` discipline in CLAUDE.md does not apply: there is nothing to assert reverted.
- **Item 21 grep — what I found and left.** Other prose mentioning the permission:
  `docs/handoff/0045-a-findings.md:50` (about **super_admin's** platform audit reads — correct,
  untouched); `docs/handoff/reports/docs-stale-payment-gate-claim.md:49,116,328` (a historical
  report — not rewritten, per the brief); `docs/Finance Module — Implementation Master Plan -
  v10.md:373,375` (the rule that forbids the grant — nothing to change);
  `plan_docs/phase2-seat-definition-brief.md:265` (expects IA to be *blocked* cross-school — this
  change makes that true); `tests/Feature/Isolation/SchoolAwareJobsTest.php:172` (super_admin —
  not touched, per correction 2). No prose anywhere still asserts IA holds it.
- **Not driven in the running app.** The change has no UI surface of its own; the matrix guard is
  proven through the real HTTP route in `SuperAdminMatrixTest` rather than by hand.

## Findings raised, not fixed

- **`head_of_school` and `principal` are missing `finance.access` on the dev copy**
  (`portaa10_portal`), while `RbacSeeder::grantsMap()` grants it to both. Both grant migrations have
  Ran, so this is not an unrun migration — most plausibly a runtime C6 matrix revoke, i.e.
  legitimate local authority. It matters because it is invisible: nothing reconciles live grants
  against `grantsMap()`, and the only reason it surfaced is that regenerating an oracle from the dev
  copy produced a 43-line diff. If the same drift exists on production, `head_of_school` cannot
  reach the finance approvals surface at all. **Severity: fix** — needs a decision (intended local
  edit, or drift needing a convergence migration), not a silent re-grant. `database/seeders/RbacSeeder.php`
  (map) vs live `role_has_permissions`.
- **Oracle regeneration has no documented source-of-truth database.** `rbac:derive-access` and
  `rbac:derive-map` read whatever `DB_DATABASE` points at, and running them against the dev copy
  silently bakes local drift into a committed fixture. The brief's "no diff" pin is what caught it
  here; nothing in the repo would have. **Severity: ticket** — a line in `docs/testing.md` (or a
  guard in the commands) naming the freshly-seeded DB as the only valid source.
  `app/Console/Commands/` + `tests/fixtures/`.
- **`2026_08_01_120000_add_show_behaviour_comment_on_result_to_schools` is Pending on the dev copy**
  and unrelated to this change; I scoped my `migrate` with `--path=` rather than run it.
  **Severity: ticket** — the dev copy is behind the branch by one additive column.
