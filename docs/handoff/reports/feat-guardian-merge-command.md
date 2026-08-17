# Implementation report — `feat/guardian-merge-command`

**This is full-review tier** — it writes to an append-only audit trail, it moves
rows across a `school_id` boundary, it routes through a documented invariant, and
it is the engine slice 3's data migration will run against production data.
Subagent review attached; recommend a cold session before merge.

---

## Headline

Done, with two interpretation calls named below. `GuardianService::merge()` plus
`guardians:merge` and `guardians:find-duplicates`, with 18 arms in
`tests/Feature/Guardian/GuardianMergeTest.php`. Branch
`feat/guardian-merge-command`, cut from `staging` @ `e484a46` (`staging` and
`main` are the same commit today — re-derived, not carried). One commit — the
branch tip; re-derive it with `git rev-parse --short feat/guardian-merge-command`
rather than trusting a sha written here, since this file is inside the commit it
would be naming. Not pushed; no PR.

The one thing that should change a reader's mind before anything else is in
**Database observations**: on the local production copy the CERTAIN duplicate
grouping — same `(user_id, school_id)` — returns **zero groups**, and no
guardian name appears three times in any school. The code defect the brief
describes is real and I read it; the *data* the brief's opening paragraph
describes is not visible in this copy. That does not block this slice, but it
does bear on slice 3's sequencing.

## Deviations from the brief

**1. `assertLoginRequiresDeliverableEmail` was not widened at all.** The brief
said to widen its visibility "only as far as needed". Needed turned out to be
nothing: `merge()` lives in `GuardianService` itself, so the `private` method is
already reachable. It is untouched at `app/Services/GuardianService.php:317`.

**2. The `can_login` guard fires on INTRODUCTION, not on every write that leaves
the flag true.** The brief says "before writing any pivot with `can_login = true`,
run the existing invariant". Read at its most literal that also aborts a merge
where the *keeper's own* row already carried `can_login = true` against an
undeliverable address and the merge only OR-merges `is_primary` into it. I
narrowed it: the invariant runs when the merge *introduces* the flag on the
keeper — a moved pivot carrying it, or a collision raising it false→true
(`GuardianService.php:940-942`, `:970-972`).

The rule I am asserting, stated so it can be attacked: *a keeper row that already
holds `can_login` against an undeliverable address is a pre-existing violation
that `guardians:audit-login-invariant` already reports; the merge did not create
it, and refusing to merge duplicates for that population would disarm this
command against part of exactly the set it exists to clean.* If the project lead
disagrees, the change is one condition — delete the `&& ! $before['can_login']`
at `:970` and the move-side `if ($canLogin)` becomes unconditional.

Both watched reds still go red under the narrowed guard (arm 4 plants the
introduction case), so the narrowing is not what makes the test pass.

**3. Two arms beyond the eight the brief listed**, both because the eight left
the primary case unproven: `collapses the same-user-same-school duplicate without
orphaning that user` (the CERTAIN duplicate — the shape slice 3's index will
reject, and the one where keeper and absorbed share a `user_id`, which the orphan
check has to get right), and `writes nothing on a dry run and returns the same
plan the apply executes` (the command's central promise; without it "dry run"
is a claim, not a behaviour).

## Contradictions of the premise

**None in the code.** Every line the brief cites is where it says it is, and says
what the brief says it says. Re-read and confirmed:

- `createGuardianWithUser` at `app/Services/GuardianService.php:225-287`; the
  unconditional `Guardian::create()` at `:274`; `$userEmail = $email ?: null` at
  `:252`; `User::where('email', $userEmail)->first()` at `:257`.
- `forUserInActiveSchool`'s docblock recording the missing unique key at
  `:745-751`, and its `withoutGlobalScopes()` + pinned predicates at `:761-766`.
- `Guardian::applySchoolScope`'s `school_id = active OR user_id has access` at
  `app/Models/Guardian.php:88-94`; `$fillable` at `:49-71`.
- `guardians.user_id` `NOT NULL` + `cascadeOnDelete` at
  `database/migrations/2026_05_13_132246_create_guardians_table.php:18`; no unique
  key on the table beyond `uuid`.
- `unique(['guardian_id','student_id'])` at
  `database/migrations/2026_05_13_140000_create_guardian_student_table.php:20`.
- The same-school triggers signalling `45000` at
  `database/migrations/2026_07_16_000003_add_guardian_student_same_school_constraint.php:17-30`.
- `attachToStudent`'s credential re-issue on the false→true transition at
  `:385-387`, and its code-only single-primary enforcement at `:390-396`.
- `assertLoginRequiresDeliverableEmail` at `:317-331`; `logPivotEvent` at
  `:769-780`.

**One qualification on the data half of the premise**, not on the code: see
Database observations. The brief's finding paragraph is a correct reading of the
code and an unverified reading of the data.

## What changed

Four files, one commit.

| File | Δ | What |
| --- | --- | --- |
| `app/Services/GuardianService.php` | +401 | `merge()` and five private helpers: refusals, the plan simulation, the login-invariant re-raise, the blank back-fill, the orphan report, and the apply. One import added (`Illuminate\Support\Collection`). Nothing else in the file is touched. |
| `app/Console/Commands/MergeGuardians.php` | 186 (new) | `guardians:merge --keep= --absorb=* [--apply]`. Dry run by default; context from the keeper's own `school_id` via `ActiveSchool::runFor`; ids-only output; non-zero on refusal. |
| `app/Console/Commands/FindDuplicateGuardians.php` | 229 (new) | `guardians:find-duplicates [--school=]`. Certain groups (same `user_id`+`school_id`) and likely groups (shared normalised phone, connected components). Non-zero while any certain group exists. |
| `tests/Feature/Guardian/GuardianMergeTest.php` | 472 (new) | 18 arms. |

Design notes a reviewer should attack directly:

- **The plan is a simulation, and it is sequential on purpose.** Two absorbed
  guardians linked to the same student make the first a move and the second a
  collision. Classifying both against the *original* keeper rows would call both
  moves and the apply would then hit the unique index. `buildMergePlan` carries a
  running `$state` per student for exactly this (`:884-1010`).
- **Dry run and apply share one code path.** `merge()` opens a transaction,
  asserts, builds the plan, and applies only when `$apply` — so the array an
  operator inspects is the array the write is driven from, not a second
  description of it.
- **The apply writes the SIMULATED FINAL pivot state**, not per-step values, so a
  student touched by two absorbed rows lands on one value rather than the last
  one written.
- **No hard delete anywhere, and no `users` write anywhere.** Absorbed guardians
  are `$guardian->delete()` (soft). Orphaned users are counted and printed.
- **Every Guardian query drops the global scopes** and pins `school_id` /
  `deleted_at`; every pivot query is `DB::table('guardian_student')`, which the
  scope never touched.

## Proof

Raw output pasted. **A note the reader needs:** this harness intercepts Pest's
stdout and replaces it with a JSON summary line — the pretty per-test output is
not available to me, in any invocation I tried (`--colors=never`, `php
vendor/bin/pest`, redirect to a file, `| cat`). What is below is verbatim what
the command returned.

### Targeted file

```
$ DB_DATABASE=portal_testing php vendor/bin/pest tests/Feature/Guardian/GuardianMergeTest.php --colors=never
{"tool":"pest","result":"passed","tests":18,"passed":18,"assertions":66,"duration_ms":20803}
```

Expected 18 arms green; observed 18 green.

### Full suite + ratchet

Run twice. The first run (17 arms — before I added the same-user-same-school arm)
and the second over the final tree. The second is the one that counts; both
ratcheted clean.

```
$ DB_DATABASE=portal_testing php vendor/bin/pest --log-junit junit.xml; echo "PEST_EXIT=$?"; php bin/ci-test-ratchet.php junit.xml; echo "RATCHET_EXIT=$?"
PEST_EXIT=2

ratchet: OK — no new failures beyond the baseline (7 known-failing).
RATCHET_EXIT=0
```

`PEST_EXIT=2` is the 7 baselined failures, not a regression — which is the whole
reason the ratchet exists. Counts read back out of the JUnit report, because the
harness swallowed the summary line on this invocation:

```
{'tests': '1687', 'assertions': '7018', 'failures': '6', 'errors': '1', 'skipped': '10', 'time': '1133.027348'}
1 tests/Feature/ActivityLog/ActivityLogApiTest.php::it blocks users without activity_log.view
1 tests/Feature/ActivityLog/ActivityLogApiTest.php::it returns a paginated scoped feed
1 tests/Feature/ActivityLog/ActivityLogApiTest.php::it does not leak activity across schools
1 tests/Feature/ActivityLog/ActivityLogApiTest.php::it hides sensitive entries without view_sensitive
1 tests/Feature/Auth/AuthenticationTest.php::users are rate limited
1 tests/Feature/GuardianProfileTest.php::it sends a password reset notification to the guardian email
1 tests/Feature/GuardianProfileTest.php::it returns empty activity list when no events exist
guardian merge cases: 18
```

Those 7 are `tests/ratchet-baseline.txt` exactly — same seven lines, no more, no
fewer. Two of them (`GuardianProfileTest`) are guardian-adjacent and therefore
worth naming rather than glossing: both were already in the baseline before this
branch, and neither changed state.

The 18 `GuardianMergeTest` cases are present in this run's JUnit report, so the
suite number and the file-level number are the same tree.

The first run, for completeness:

```
{"tool":"pest","result":"failed","tests":1686,"passed":1669,"assertions":7015,"duration_ms":1890531,"failed":6,...,"errors":1,...,"skipped":10,...,"risky":3}
PEST_EXIT=0

ratchet: OK — no new failures beyond the baseline (7 known-failing).
RATCHET_EXIT=0
```

### Gates

```
$ ./vendor/bin/pint app/Services/GuardianService.php app/Console/Commands/FindDuplicateGuardians.php app/Console/Commands/MergeGuardians.php tests/Feature/Guardian/GuardianMergeTest.php
{"tool":"pint","result":"fixed","files":[{"path":"app\/Console\/Commands\/FindDuplicateGuardians.php","fixers":["fully_qualified_strict_types"]}]}

$ php bin/ci-authz-lint.php
authz-lint: OK — no new commented-out authorization checks (0 known).
authz exit=0

$ php bin/ci-boundary-lint.php
boundary-lint: OK — no new boundary violations (4 known temporary exceptions).
boundary exit=0

$ DB_DATABASE=portal_testing php vendor/bin/pest --group=arch --colors=never
{"tool":"pest","result":"passed","tests":32,"passed":32,"assertions":181,"duration_ms":13548,"warnings":2,"warning_details":[{"file":"/Users/oluwayimika/Documents/portal/tests/Feature/Rbac/ForcingMigrationsDoNotStripLaterGrantsTest.php","line":43,"message":"Constant FORCING_MIGRATIONS already defined"},{"file":"/Users/oluwayimika/Documents/portal/tests/Feature/Rbac/ForcingMigrationsDoNotStripLaterGrantsTest.php","line":48,"message":"Constant CONVERGES_MARKER already defined"}]}

$ composer analyse
{"tool":"phpstan","result":"passed","errors":0}
```

The two arch warnings are pre-existing (`ForcingMigrationsDoNotStripLaterGrantsTest`
redefining two constants) and unrelated to this branch.

Pint was invoked with an explicit four-file list, never a bare path. The one fix
it applied was `fully_qualified_strict_types` on the new command — my own file.

### `git diff --stat` against my model of the change

```
$ git diff --stat HEAD
 app/Services/GuardianService.php | 401 +++++++++++++++++++++++++++++++++++++++
 1 file changed, 401 insertions(+)
```

Plus three untracked new files (the two commands and the test). My model was:
one service file modified, three files added, nothing else. That is what is
there — no sweep.

## The watched red

Both were required and both went red. Restored and re-run green in both cases.

### Red 1 — the collision branch (arm 2)

Mutation, in `buildMergePlan`:

```diff
-                if (! isset($state[$studentId])) {
+                if (true) { // WATCHED RED: collision branch disabled
```

Every pivot then takes the plain re-point.

```
$ DB_DATABASE=portal_testing php vendor/bin/pest tests/Feature/Guardian/GuardianMergeTest.php --colors=never --filter='OR-merges'
{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":0,"duration_ms":36982,"errors":1,"error_details":[{"test":"P\\Tests\\Feature\\Guardian\\GuardianMergeTest::__pest_evaluable_it_OR_merges_a_colliding_link_into_the_keeper_row_instead_of_raising_a_duplicate_key","file":"/Users/oluwayimika/Documents/portal/vendor/laravel/framework/src/Illuminate/Database/Connection.php","line":612,"message":"SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '1-1' for key 'guardian_student.guardian_student_guardian_id_student_id_unique' (Connection: mysql, Host: 127.0.0.1, Port: 3306, Database: portal_testing, SQL: update `guardian_student` set `guardian_id` = 1, `updated_at` = 2026-08-17 17:29:26 where `id` = 2)"}]}
```

The message names the right thing: **1062, on
`guardian_student.guardian_student_guardian_id_student_id_unique`**, on the
`UPDATE … SET guardian_id` that is the re-point. Restored:

```
$ DB_DATABASE=portal_testing php vendor/bin/pest tests/Feature/Guardian/GuardianMergeTest.php --colors=never --filter='OR-merges'
{"tool":"pest","result":"passed","tests":1,"passed":1,"assertions":7,"duration_ms":28480}
```

### Red 2 — the login invariant (arm 4)

Mutation, in `assertMergedLoginIsDeliverable`:

```diff
-            $this->assertLoginRequiresDeliverableEmail($keeper, true);
+            // WATCHED RED: invariant call removed
```

```
$ DB_DATABASE=portal_testing php vendor/bin/pest tests/Feature/Guardian/GuardianMergeTest.php --colors=never --filter='aborts the whole merge'
{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":1,"duration_ms":32668,"failed":1,"failures":[{"test":"P\\Tests\\Feature\\Guardian\\GuardianMergeTest::__pest_evaluable_it_aborts_the_whole_merge_rather_than_move_login_access_onto_an_undeliverable_keeper","file":"/Users/oluwayimika/Documents/portal/tests/Feature/Guardian/GuardianMergeTest.php","line":198,"message":"Exception \"Illuminate\\Validation\\ValidationException\" not thrown."}]}
```

Restored; the file is green at 18/18 above. Note what this red does *not* prove:
it shows the guard is load-bearing for the throw. The "nothing was written"
half of arm 4 is asserted separately in the same arm (the absorbed pivot still
points at the absorbed guardian, the keeper has no pivot, `deleted_at` is still
null) and is not covered by this mutation.

## The drive

No screen changed — the brief says so and I confirmed nothing under
`resources/js` is touched. No drive.

## Database observations

Local copy, read-only, ids and counts only.

```
$ php artisan guardians:find-duplicates
Live guardian records examined: 776 (all schools)

(1) CERTAIN — more than one live guardian row for the same user in the same school: 0

(2) LIKELY — distinct live guardian rows sharing a phone number within a school: 14
+----------+------+------------------------------+
| school   | rows | guardians                    |
+----------+------+------------------------------+
| school#1 | 2    | guardian#2428, guardian#3121 |
| school#1 | 2    | guardian#2478, guardian#3126 |
| school#1 | 2    | guardian#2484, guardian#3124 |
| school#1 | 2    | guardian#2687, guardian#3118 |
| school#1 | 2    | guardian#2730, guardian#3105 |
| school#1 | 2    | guardian#2767, guardian#3123 |
| school#1 | 2    | guardian#2900, guardian#2947 |
| school#1 | 2    | guardian#2953, guardian#3109 |
| school#1 | 2    | guardian#2955, guardian#3091 |
| school#1 | 2    | guardian#3022, guardian#3116 |
| school#1 | 2    | guardian#3023, guardian#3101 |
| school#1 | 2    | guardian#3029, guardian#3103 |
| school#1 | 2    | guardian#3042, guardian#3111 |
| school#1 | 2    | guardian#3055, guardian#3099 |
+----------+------+------------------------------+

No certain duplicates. A unique index on (user_id, school_id) over live rows would hold today.
```

Corroborated independently of the command, so this is not the command grading its
own homework:

```
guardians_rows_total=776
guardians_rows_live=776
same_user_school_groups_including_soft_deleted=0
users_with_live_guardian_rows_in_more_than_one_school=0
schools=2
name_groups_size_ge_2=4
name_groups_size_ge_3=0
max_group_size=2
```

Three things follow, and the third is the one that matters.

1. **The email-bearing duplicate path has produced no live rows here.** The
   defect in `createGuardianWithUser:274` is real in code; in this copy it has
   never fired. Zero certain groups, including soft-deleted rows.
2. **The email-less path is what is visible**: 14 pairs sharing a normalised
   phone within `school#1`, invisible to grouping (1) because each has its own
   `users` row. That matches the brief's account of the mechanism.
3. **No guardian appears three times, by any grouping.** No name group exceeds
   two rows and no phone group exceeds two rows. The reported triplicate is not
   reproducible in this copy. Either the copy predates it, or the third row
   differs in both phone and name spelling, or the report is about something
   else. I did not chase it further — it is outside this slice — but slice 3
   should not assume the census it will run is the one behind the school's
   report.

That the detector *works* is proven separately by a test that plants a certain
duplicate and watches the command exit non-zero, so the zero above is a fact
about the data, not a silent no-op.

## Not done

- **No authorization anywhere on `merge()`.** It is a public service method with
  no policy check, reachable today only from a console command run by an
  engineer. The admin merge UI (explicitly out of scope) must not call it without
  a gate; that is a real requirement being deferred, not an oversight.
- **The `--school` option on `guardians:find-duplicates` is proven only in the
  narrow direction** — arms pass a school id and assert the exit code. There is
  no arm proving that two guardians in *different* schools sharing one phone are
  not grouped. The code keys the union-find on `school_id.':'.$number` so they
  cannot be, but that is an argument, not a test.
- **No arm plants more than one absorbed guardian.** The sequential simulation
  (first-is-a-move, second-is-a-collision) is the subtlest thing in the change
  and it is unproven by test. I convinced myself by reading; a reviewer should
  treat it as unverified.
- **`GuardianLoginInvariantTest` was not modified and I did not need to touch
  it.** It was green in the full run.
- **`docs/handoff/briefs/feat-guardian-merge-command.md` is left untracked.** It
  arrived untracked and it is not mine to commit; the diff would stop matching
  the brief's stated shape.
- **Nothing pushed. No PR. No merge into `staging`.**

## Findings raised, not fixed

- `app/Services/GuardianService.php:274` — the creation-path defect itself, still
  live. Slice 2. **fix**, and it is the reason this slice exists.
- `app/Models/Guardian.php:88-94` — `applySchoolScope`'s OR branch makes another
  school's guardian rows visible under the default scope. Known and flagged in
  the brief; every query in this change works around it. Worth its own change
  because the workaround — `Guardian::withoutGlobalScopes()` — now stands at 9
  call sites across 5 files (`grep -rn 'Guardian::withoutGlobalScopes' app/`,
  counted after this branch, which adds 4 of them). **ticket**.
- `app/Services/GuardianService.php:390-396`, `:495-500` — single-primary is
  enforced in code only, at each writer. The pre-state in arm 3 (two primaries
  for one student) is constructible directly through the pivot and nothing
  detects it. A `guardians:audit-single-primary` in the shape of
  `guardians:audit-login-invariant` would make it a fact rather than a
  convention. **ticket**.
- `tests/Feature/Rbac/ForcingMigrationsDoNotStripLaterGrantsTest.php:43,48` — two
  constants redefined, warned on every `--group=arch` run. Pre-existing, noise.
  **ticket**.
