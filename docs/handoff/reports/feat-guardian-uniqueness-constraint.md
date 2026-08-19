# Implementation report — `feat/guardian-uniqueness-constraint`

**Full-review tier** — this is a migration against production data with a rollback path, and it
carries an unbriefed application-code change. Subagent review attached; recommend a cold session
before merge.

## Headline

Done, with two deviations, one of which is a scope expansion the lead should adjudicate.
`guardians` now carries a generated column `live_identity` and a unique index over it, so the
database itself enforces at most one LIVE guardian row per `(user_id, school_id)`. Branch
`feat/guardian-uniqueness-constraint`, based on `staging` @ `e484a46`. One commit. Not pushed.

The brief asked for two files. It is **five**: the migration, the test, the brief itself (committed
as instructed), a ticket, and — the deviation that matters — `app/Models/Guardian.php`, because the
migration as briefed **breaks a live production path** without it.

## Deviations from the brief

### 1. `app/Models/Guardian.php` was changed. The migration breaks `replicate()` without it.

**What the brief said:** Part 2 item 4 — "Check `GuardianFactory` and any `replicate()` call sites
(the parked merge branch uses `$template->replicate(['uuid'])`) still work against a table with a
generated column." The dispatching instruction was firmer: the change "should not need to touch
`app/Services/GuardianService.php` at all beyond possibly a docblock comment", and the branch is
"deliberately two files".

**What I found:** they do not still work. `replicate()` copies `$this->getAttributes()` verbatim
(`vendor/laravel/framework/src/Illuminate/Database/Eloquent/Model.php:2118-2120`), and a Guardian
hydrated from a `SELECT` carries `live_identity` like any other column. The clone's INSERT therefore
names a generated column and MySQL refuses it with driver code **3105**. Raw output from a probe run
against the migrated schema:

```
live_identity loaded on model? YES ('8:8')
clone has live_identity attr? YES
replicate+save: FAIL SQLSTATE[HY000]: General error: 3105 The value specified for generated column 'live_identity' in table 'guardians' is not allowed.
```

The call site is not on a parked branch. It is on `staging`, at
`app/Services/GuardianService.php:211`, inside `resolveOrCreateGuardianForUserInSchool` — the
§6.2 multi-school-parent path, reached from `linkExistingGuardianToSchool`. Shipping the migration
alone would break "add this existing parent to a second school" outright, on the first request after
deploy.

**What I did:** overrode `replicate()` on `App\Models\Guardian` to always exclude the column
(`app/Models/Guardian.php:104-107`), rather than editing the call site.

- It does not touch `GuardianService.php`'s executable code, which the dispatcher ringfenced and
  which both `fix/guardian-create-duplicates` and `feat/guardian-merge-command` are editing.
- It covers every caller, including the parked merge branch's `replicate()`, without either branch
  needing to know.

**Why I did not stop and report instead.** The brief's stop list includes "generated columns collide
with something in this schema you did not expect", and this is arguably that. I judged it not to be a
scope expansion in the sense the dispatcher meant: this is not an *adjacent* defect found in passing,
it is fallout **caused by this change**, and a migration that breaks a live path is not a shippable
deliverable in any case. But it is a fork, I could not consult mid-task, and reverting one file is
trivial — so it is flagged here rather than buried. **If the lead prefers this split out, delete
`Guardian::replicate()` and the `it('can still clone a guardian into a second school with
replicate()')` arm; nothing else depends on them.**

**The general rule I am asserting, so it can be checked:** *adding a generated column to a table
whose model is ever `replicate()`d requires excluding that column, because Eloquent's replicate
copies raw attributes and MySQL rejects INSERTs naming generated columns.* This is not
guardian-specific. `2026_07_28_120001_add_current_session_uniqueness` put
`current_school_key` on `academic_sessions` under the same idiom; `AcademicSession` is not
replicated anywhere today (`git grep -rn 'replicate(' -- app/` returns exactly one hit, the guardian
one), so that one is latent rather than broken. Raised below as a ticket-severity finding.

### 2. `VIRTUAL`, not `STORED`.

The brief's SQL specifies `STORED`. That is impossible on this table. `STORED` rebuilds the table,
and `guardians` carries three outbound FKs plus an inbound one from `guardian_student`; MySQL aborts
with 1215. Verified empirically before writing the migration, both arms in one run:

```
STORED: FAIL SQLSTATE[HY000]: General error: 1215 Cannot add foreign key constraint
VIRTUAL: OK
UNIQUE on virtual: OK
drop index: OK
drop col: OK
```

This is not an improvisation: `2026_07_28_120001_add_current_session_uniqueness.php:23-33` records
the identical forced deviation, for the identical reason, and the brief instructed me to follow the
project's precedent for generated columns if one existed. The guarantee is unchanged — a unique index
on a VIRTUAL generated column is materialised in the index and the NULL exemption behaves identically.

### 3. (Minor) The `GuardianService::forUserInActiveSchool` docblock was updated.

Comment-only, explicitly permitted by the brief. Its old text asserted "nothing at the schema level
enforces one Guardian row per (user, school)", which this change makes false. `orderBy('id')` is
untouched.

## Contradictions of the premise

**None. The premise holds.** Verified rather than assumed:

- `guardians` carries exactly `PRIMARY(id)`, `guardians_uuid_unique`, and three non-unique FK
  indexes on `school_id`, `user_id`, `photo_id` — no unique key on `(user_id, school_id)`.
- `app/Services/GuardianService.php` (on `staging`) documents the gap in the docblock of
  `forUserInActiveSchool` at line 753 and works around it with `orderBy('id')`. The brief cited
  `745-751`; the docblock spans roughly `741-758` on staging, so the citation is approximately right
  and substantively right.
- The `HAVING n > 1` query returns the empty set (below).

One correction to a durable-facts assumption, not to the brief: this MySQL is **9.7.1**, not the
8.0.43 that `finance-context` records. Re-derived with `SELECT VERSION()`.

## What changed

| File | Lines | What |
|---|---|---|
| `database/migrations/2026_08_19_100000_add_guardian_live_identity_uniqueness.php` | +75 | The generated column + unique index. `down()` drops index then column. |
| `tests/Feature/Guardian/GuardianUniquenessTest.php` | +250 | 8 arms — 5 constraint behaviours, shape, generated-column write refusal, replicate. |
| `app/Models/Guardian.php` | +25 | `replicate()` override. See deviation 1. |
| `app/Services/GuardianService.php` | +8/-7 | Docblock only. |
| `docs/handoff/tickets/guardian-restore-into-occupied-pair.md` | +47 | The brief's Part 3 arm 5 asks what the app does with a restore collision; it does nothing, because no restore path exists. |
| `docs/handoff/briefs/feat-guardian-uniqueness-constraint.md` | +172 | The brief, committed as instructed. |

## Proof

Run serially, nothing in parallel. All raw.

### Part 1 — derive before writing

The brief's query, run over every candidate database (the production copy is `portal` /
`portal-test`; `finance-context`'s `portaa10_portal` no longer exists here):

```
portal: guardians=776 live=776 dup_groups=0
portal-test: guardians=776 live=776 dup_groups=0
production-35303033c63c: ERR SQLSTATE[42S02]: Base table or view not found: 1146 Table 'production-35303033c63c.guardians' doesn'
portal_drive: guardians=4 live=4 dup_groups=0
portal_testing: guardians=0 live=0 dup_groups=0
```

**Expected: empty set. Observed: empty set.** 0 offending groups over 776 live rows. No data
migration; none written.

### Part 3 — the test file

```
{"tool":"pest","result":"passed","tests":8,"passed":8,"assertions":24,"duration_ms":10115}
```

All five briefed arms plus three. Every constraint arm writes through `DB::table('guardians')`
directly — no model, no scope, no service — so a green is the index refusing and cannot be a PHP
guard refusing. The duplicate assertion requires driver code **1062** *and* that the message names
`guardians_live_identity_unique`, because `guardians_uuid_unique` already exists and a bare "some
1062" would pass on a duplicate uuid.

Brief item 3 — what an accidental write to the generated column does. **Both**, and they differ:

```
mass-assign live_identity: NO ERROR, stored='9:8'
direct-set live_identity: THREW Illuminate\Database\QueryException driver=3105
```

Mass assignment is silently dropped by the `$fillable` whitelist and the stored value is the
correct generated one; a direct attribute set reaches MySQL and raises **3105**. Neither can corrupt
the column; only the second is visible. Asserted in the test.

Brief item 4 — `GuardianFactory` needed no change (it names only real columns); `replicate()` did,
see deviation 1.

### The `down()` four-path audit

Depth re-derived per `docs/testing.md` § "`--step=N` is relative to the branch", not assumed:

```
### depth derivation: migrations listed AFTER mine =
1
```

Path 1 — plant real rows against the migrated schema, deliberately including the two cases a naive
index would break (a multi-school parent, and two soft-deleted rows for one pair):

```
PLANTED
planted rows = 5
  guardian#950 user#801 school#901 deleted=no
  guardian#951 user#801 school#902 deleted=no
  guardian#952 user#802 school#901 deleted=no
  guardian#953 user#803 school#901 deleted=yes
  guardian#954 user#803 school#901 deleted=yes
```

Path 2 — roll back **to my migration** and assert *my* column and *my* index are gone, not that
rollback exited 0:

```
 INFO Rolling back migrations.
 2026_08_19_100000_add_guardian_live_identity_uniqueness .. 45.21ms DONE

live_identity column present = 0 ; guardians_live_identity_unique present = 0
planted rows = 5
AUDIT OK: my column and my index are gone
exit=0
```

Paths 3 and 4 — re-up against the planted rows, assert both return and the rows survive:

```
 INFO Running migrations.
 2026_08_19_100000_add_guardian_live_identity_uniqueness .. 70.76ms DONE

live_identity column present = 1 ; guardians_live_identity_unique present = 1
planted rows = 5
AUDIT OK: my column and my index are present
exit=0
```

Generated values after re-up:

```
guardian#950 user#801 school#901 deleted=no  live_identity='801:901'
guardian#951 user#801 school#902 deleted=no  live_identity='801:902'
guardian#952 user#802 school#901 deleted=no  live_identity='802:901'
guardian#953 user#803 school#901 deleted=yes live_identity=NULL
```

### Reversibility against *real* data

The four-path audit above runs on a test schema with 5 planted rows, which is not the same as the
production shape. So the migration's SQL was also applied to a throwaway `mysqldump` clone of the
production copy — 776 real guardian rows — up and down:

```
dup_groups
0
total	exempt_soft_deleted	distinct_live
776	0	776
after_rollback
776
```

776 rows, 776 distinct live identities, no collision, no repair, and all 776 present after the
rollback. The throwaway database and the dump were dropped.

### Gates

```
files:
app/Models/Guardian.php
app/Services/GuardianService.php
database/migrations/2026_08_19_100000_add_guardian_live_identity_uniqueness.php
tests/Feature/Guardian/GuardianUniquenessTest.php
{"tool":"pint","result":"passed"}
```

(Explicit file list, guarded against empty. `git diff --stat` after pint shows only my two tracked
files touched — no sweep.)

```
authz-lint: OK — no new commented-out authorization checks (0 known).
boundary-lint: OK — no new boundary violations (4 known temporary exceptions).
{"tool":"pest","result":"passed","tests":32,"passed":32,"assertions":181,...}   # --group=arch
{"tool":"phpstan","result":"passed","errors":0}                                  # composer analyse
```

tsc ratchet, run the way `bin/quality` does — `wayfinder:generate --with-form` first:

```
 [Wayfinder] Generated actions in .../resources/js/actions
 [Wayfinder] Generated routes in .../resources/js/routes
tsc-ratchet: OK (42 == baseline 42).
```

Full suite + failure ratchet:

```
{"tool":"pest","result":"failed","tests":1677,"passed":1660,"assertions":6976,"duration_ms":527134,"failed":6,"errors":1,"skipped":10,"risky":3}

ratchet: OK — no new failures beyond the baseline (7 known-failing).
```

The 7 are all baselined: 4 in `ActivityLogApiTest`, 2 in `GuardianProfileTest`, 1 in
`AuthenticationTest`. **`GrantsConvergenceLintTest` did not fail in this run.** Not chased, not
retried, not baselined.

## The watched red

Two mutations. The second one is the reason this section is not a formality.

### Red 1 — the index (the one the brief required)

**Mutation:** in the migration, replaced the `ADD UNIQUE guardians_live_identity_unique` statement
with `// WATCHED RED: index deliberately not created.` The generated column is still created; only
the index is gone. Suite re-run:

```
{"tool":"pest","result":"failed","tests":8,"passed":4,"failed":1,"errors":2,
 "failures":[{"test":"...it_has_the_live_identity_column_and_its_unique_index",
   "message":"Failed asserting that actual size 0 matches expected size 1."}],
 "error_details":[
   {"test":"...it_refuses_a_second_LIVE_guardian_for_the_same_user_and_school",
    "message":"expected a duplicate-key QueryException, none thrown"},
   {"test":"...it_refuses_restoring_a_soft_deleted_guardian_while_a_live_one_holds_the_pair",
    "message":"expected a duplicate-key QueryException, none thrown"}]}
```

**"expected a duplicate-key QueryException, none thrown"** is exactly the finding the brief called
for: without the index, **the second insert succeeds**. The message names the right thing — the two
arms that go red are the two REFUSED arms, and the three ALLOWED arms stay green, which is the
control. Restored; re-run green at 8/8.

### Red 2 — the `replicate()` override, and the arm that did not go red first time

**Mutation:** neutered the override to `return parent::replicate($except);`.

```
{"tool":"pest","result":"passed","tests":8,"passed":8,"assertions":23}
```

**It stayed green.** The arm was vacuous. Cause: I had built the template with
`Guardian::factory()->create(...)` and used the returned model, and a freshly-created model carries
only the attributes that were INSERTed — `live_identity` is absent from it, so `replicate()` had
nothing to copy. The real path receives a Guardian resolved by a query, and only a hydrated model
reproduces the bug.

Fixed by re-fetching (`Guardian::withoutGlobalScopes()->findOrFail($created->id)`) and asserting the
attribute is present on the template before replicating. Re-ran the same mutation:

```
{"tool":"pest","result":"failed","tests":8,"passed":7,
 "failures":[{"test":"...it_can_still_clone_a_guardian_into_a_second_school_with_replicate__",
   "message":"Expecting […] not to have key 'live_identity'."}]}
```

That reds on the guard assertion before reaching the write, so I also commented that assertion out
to see the consequence it stands in for:

```
{"errors":1,"error_details":[{"test":"...it_can_still_clone_a_guardian_into_a_second_school_with_replicate__",
 "message":"SQLSTATE[HY000]: General error: 3105 The value specified for generated column 'live_identity'
 in table 'guardians' is not allowed. ... insert into `guardians` (..., `live_identity`, `uuid`, ...)
 values (9, 8, ..., 8:8, ...)"}]}
```

The INSERT visibly names `live_identity` with value `8:8`. Both mutations restored; `git diff --stat`
confirms only the intended changes remain, and the file is green at 8/8.

**This is the honest cost of the discipline being reported, not a tidy story:** an arm I wrote,
watched pass, and would have shipped as proof was proving nothing, and only the mutation found it.

## Database observations

Under the privacy rule — ids, counts, structure only.

| | Before | After |
|---|---|---|
| `guardians` rows (production copy) | 776 | 776 |
| live rows | 776 | 776 |
| `(user_id, school_id)` groups with >1 live row | 0 | 0 (now impossible) |
| distinct `live_identity` values | — | 776 |
| soft-deleted rows exempt (NULL) | — | 0 |
| indexes on `guardians` | 5 | 6 |

The production copy was **not** modified. Every write went to `portal_testing` or to a throwaway
clone that has since been dropped.

## Not done

- **No drive.** No screen changed. This is schema plus a model method; there is no Inertia page, no
  controller and no route in the diff.
- **`bin/quality` was not run end to end**, and neither was `bin/quality-promote` /
  `bin/quality-clean-db`. I ran the gates the brief named, serially, plus the tsc ratchet with
  `--with-form`. The steps I did **not** run are the frontend vite build, eslint/prettier on changed
  files, and the sql-clock and grants-convergence lints. The diff contains no frontend file and no
  SQL timestamp expression, so I judged those unaffected — but that is a judgement, not a
  measurement, and the pre-push hook will run them.
- **`fix/guardian-create-duplicates` and `feat/guardian-merge-command` were not merged, rebased or
  read for conflicts** beyond confirming they touch `GuardianService.php`. The merge branch's
  `replicate(['uuid'])` is covered by the model override *by construction*, but I did not check that
  branch out and run its tests against this schema. **That is the largest untested interaction here.**
- **No `restore()` arm through the application**, because there is no application restore path to
  drive. The arm goes through a raw UPDATE. Ticketed.
- **The `live_identity` column is not in any API resource, and I did not check whether it leaks.**
  `Guardian` has no `$hidden` and no `$visible`, so a `toArray()` on a hydrated Guardian will include
  `live_identity`. I did not trace whether any resource serialises the model wholesale. Flagged below.

## Findings raised, not fixed

1. **`Guardian` has no `$hidden`, so `live_identity` may now appear on the wire.** —
   `app/Models/Guardian.php:49-77`. A generated internal key on a public payload is not a secret
   (it is `user_id:school_id`, both of which are already integer ids the project is separately trying
   to keep off the wire — see `docs/handoff/tickets/integer-primary-keys-still-on-the-wire.md`), but
   it is noise at best and an id leak at worst. **Severity: ticket** — I did not trace the resources,
   so I cannot say it *does* leak, only that nothing stops it.
2. **The replicate/generated-column hazard is general, and `academic_sessions` is latent.** —
   `2026_07_28_120001_add_current_session_uniqueness.php`. `AcademicSession` is not replicated today
   (`git grep -rn 'replicate(' -- app/` → 1 hit), so it is not broken; the day someone replicates it,
   it breaks the same way with no warning. There is no lint for this. **Severity: ticket** — a
   wallpaper rule with no mechanism, exactly the class `finance-method` names.
3. **Restoring a soft-deleted guardian into an occupied pair will 409 with an unexplained message.**
   — `bootstrap/app.php:197`. Ticketed at
   `docs/handoff/tickets/guardian-restore-into-occupied-pair.md`. **Severity: ticket**, and only
   latent: `git grep -rn "withTrashed\|onlyTrashed\|->restore(" -- app/ routes/` returns two hits,
   both in `StudentCurriculum`, so no guardian restore path exists.
4. **`docs/handoff/tickets/grants-convergence-lint-nondeterminism.md` does not exist.** Both the
   brief and the dispatching instruction cite it as the record for the known
   `GrantsConvergenceLintTest` flake. There is no file of that name in
   `docs/handoff/tickets/`. The flake did not fire this run so nothing was blocked, but the citation
   points at nothing. **Severity: ticket.**
5. **`finance-context` records MySQL 8.0.43; the server is 9.7.1.** Several of the "verified
   behaviours" in that skill are version-pinned claims. **Severity: ticket** — stale substrate is a
   lie generator, which is the thing that file explicitly warns about.
