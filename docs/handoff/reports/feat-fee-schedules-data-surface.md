# Implementation report — U1 commit 1 of 2, the fee-schedules data surface

## CORRECTIONS — 2026-08-11

Two things below are wrong. The body is left as it was written; these are the corrections.

**1. What deleting the `editDraft` docblock removed.** That docblock said the
`term_id`/`class_level_id` `exists` rules were "UNSCOPED" and "not harmless on `store`/`supersede`,
which read them". **That claim was already false at the base.**
`git show 59e1da8:app/Finance/Http/Requests/FeeScheduleRequest.php` shows both rules carrying
`->where('school_id', ActiveSchool::id())` at lines 49–50. The false sentence came in with #234 and
was not swept when #235's R1 scoped the rules. Deleting the docblock was correct either way, but the
body of this report presents its deletion as removing a **stale contract**, and what it actually
removed was a **false statement about the code**. Those are different things, and only the second one
means a reader of that file had been told something untrue for the life of the branch.

**2. The command cited for the diff figures.** The body cites
`git diff --stat 59e1da8..HEAD` for "12 files, +497/−119". That command does not produce those
numbers — it yields 13 files and +883, because `HEAD` includes this report. The **figures are right**;
they are `git diff --stat 59e1da8..48279f5`, the implementation commit alone. The **command naming
them is what was wrong.** (`48279f5` has since been rewritten — see the remediation section at the
end of this file.)

Neither correction changes a decision, a test, or a line of shipped code.

**3. The rationale given for `?->` on the two labels is FALSE. — 2026-08-11, added in the final pass.**
The comment on `FeeScheduleResource` (and the FIX 3 section of the remediation below) said
`whenLoaded()` "is TRUE for a belongsTo that eager-loaded to NULL, so the index would otherwise return
a 500". It does not.
`vendor/laravel/framework/src/Illuminate/Http/Resources/ConditionallyLoadsAttributes.php:284-286`
returns `null` **before** the closure is evaluated, so the closure can never see a null relation and
there was no 500 to avoid. **The error originated in the advising side's remediation block, which
asserted the framework's behaviour without reading vendor; this commit implemented it as written.**
The `?->` is retained — it is coherence with `$item->bankAccount?->uuid` three lines below, and
reverting a green branch to delete two inert characters is churn — and the reason is corrected in the
comment itself rather than quietly dropped. Attribute the error to the block, not to the
implementation.

## Headline

**Done, with two deviations.** All four changes in the brief are in, with tests and six watched
reds; `bin/quality` is green 14/14 on the committed tree.

Branch `feat/fee-schedules-data-surface`, cut from `origin/staging` @ `59e1da8`. The change is one
commit, `48279f5`; this report is a second, docs-only commit on top of it. No PR opened, not pushed.

**This is full-review tier** — it touches money routing (which bank account a fee line points at),
`school_id` isolation (the index `term_id` rule), a request contract, and a fixture oracle-adjacent
seeder. Subagent review attached; recommend a cold session before merge.

## Deviations from the brief

**1. The `items.*` rules are SHARED through a trait, not copied.**

The brief said: "Copy them from FeeScheduleRequest unchanged, INCLUDING `items.*.bank_account_id`'s
scoped `Rule::exists` … and `items.*.currency`'s ISO-4217 regex", and "It carries `itemSpecs()`, same
body."

What I did: added `app/Finance/Http/Requests/Concerns/HasFeeScheduleItemRules.php`, holding
`feeItemRules()` (the `items` + `items.*` block, byte-identical to what `FeeScheduleRequest` carried)
and `itemSpecs()`. Both `FeeScheduleRequest` and the new `EditFeeScheduleDraftRequest` `use` it.
`FeeScheduleRequest` keeps `term_id`, `class_level_id` and `label`; `EditFeeScheduleDraftRequest`
keeps `label`. Neither class now contains an item rule of its own.

Why: the ticket the brief cites names this exact cost against its own option (1) — "the cost is
keeping the item-rule reuse genuinely shared rather than copied — a second copy of that rule is
exactly what the domain commit avoided" (deleted file, quoted from `git show 59e1da8:docs/handoff/
tickets/edit-draft-request-reuse-decide-at-u1.md`, line 41–42). `items.*.bank_account_id` is an
isolation rule (`->where('school_id', ActiveSchool::id())->whereNull('deactivated_at')`); a second
copy can be weakened or left behind on one of two request classes, and the failure mode is a route
that accepts another School's bank account as the destination for this School's money.

**I read the brief's "unchanged, INCLUDING …" as being about not dropping rules while moving them,
not as a requirement that the text be duplicated. If the intent was a literal second copy, this is a
five-minute revert** — delete the trait, inline `feeItemRules()` into both classes.

No general rule formed. This is one judgement about one file pair.

**2. `loadMissing('items.bankAccount')` added at four render sites, not only `index()`.**

The brief asked for `items.bankAccount` on `index()`. I also added it to `store`, `editDraft`,
`supersede` and `prefill`, because `bank_account_id` is now serialised on every item everywhere the
resource is rendered, and without the load each of those responses is one query per item. `prefill`
is the billing read path, which is the one the brief was most explicit about protecting.

This does not change any payload shape: `loadMissing` on an already-loaded `items` adds the nested
relation without re-querying or re-ordering `items`, and `term` stays unloaded on `prefill`, so both
new labels remain absent there. Pinned by the prefill key-list assertion below.

## Contradictions of the premise

**None.** Every claim in the brief reproduced against the tree:

- `FeeScheduleController::editDraft` took `FeeScheduleRequest` (`59e1da8`, controller line 68);
  `FeeScheduleRequest::rules()` had `term_id` and `class_level_id` `required` (lines 49–50);
  `EditFeeScheduleDraft::handle(FeeSchedule $schedule, string $label, array $items)` takes neither.
- `FeeScheduleResource` serialised `{id, description, amount, is_mandatory, is_discountable,
  sort_order}` per item — no `bank_account_id` — and returned `term_id`/`class_level_id` as raw ints.
- `index()` had no filter, no pagination, `->orderByDesc('id')`, `->with(['items' => …])` only.
- `FeeItem` had **no** `bankAccount` relation — only `schedule()`. The brief said "check first and
  report which it was": **it did not exist, and I added it.**
- `DriveCastSeeder` contained no `Term`, no `ClassLevel`, no `AcademicSession`;
  `DriveFinanceStates` touches none of the three. Confirmed by reading both files whole.

Precondition also confirmed before branching: `origin/staging` @ `59e1da8` is
`Merge pull request #235`, with `6af97e3 Merge pull request #234` beneath it.

## What changed

12 files, +497/−119 (`git diff --stat 59e1da8..HEAD`).

| File | ± | What |
| --- | --- | --- |
| `app/Finance/Http/Requests/Concerns/HasFeeScheduleItemRules.php` | +62 | New. `feeItemRules()` + `itemSpecs()`, shared by both requests. |
| `app/Finance/Http/Requests/EditFeeScheduleDraftRequest.php` | +54 | New. `label` + the shared item rules. No `term_id`, no `class_level_id`. |
| `app/Finance/Http/Requests/FeeScheduleRequest.php` | +8/−29 | Keeps `term_id`/`class_level_id`/`label`; item rules now come from the trait. |
| `app/Finance/Http/Controllers/FeeScheduleController.php` | +55/−19 | `index()` takes `Request`, validates two optional filters, eager-loads four relations; `editDraft` takes the new request; four `loadMissing('items.bankAccount')`. |
| `app/Finance/Http/Resources/FeeScheduleResource.php` | +24/−4 | `bank_account_id` (uuid) per item; `term_label` + `class_level_label` through `whenLoaded`. |
| `app/Finance/Models/FeeItem.php` | +16 | `bankAccount(): BelongsTo`. |
| `database/seeders/DriveCastSeeder.php` | +59 | `seedAcademicSlot()` per drive school: 1 session, 1 term, 2 class levels. |
| `app/Console/Commands/SeedDriveFixture.php` | +25/−3 | `report()` prints the three counts per school, read from the database. |
| `docs/handoff/tickets/edit-draft-request-reuse-decide-at-u1.md` | −71 | **Deleted** — closed by change 1. |
| `docs/handoff/tickets/fee-schedule-index-unpaginated.md` | +33 | New ticket, per the brief. |
| `tests/Feature/Finance/EditFeeScheduleDraftTest.php` | +46 | 2 arms. |
| `tests/Feature/Finance/FeeScheduleTest.php` | +108 | 3 arms + one helper. |

Untouched, as the brief required: `routes/web.php`, `routes/endpoints/finance.php`,
`resources/js/**`, `tests/fixtures/route-access-map.json`, `app/Enums/Permission.php`, and every
Action. No migration.

## Proof

### The two affected test files

```
DB_DATABASE=portal_testing ./vendor/bin/pest tests/Feature/Finance/FeeScheduleTest.php tests/Feature/Finance/EditFeeScheduleDraftTest.php
{"tool":"pest","result":"passed","tests":27,"passed":27,"assertions":122,"duration_ms":20701}
```

Expected 27 pass; observed 27 pass. (The runner is configured to emit JSON, not Pest's usual list —
that is this repo's output, not a summary I wrote.)

### `bin/quality`, run on the committed tree

```
quality gate — base 59e1da8

[1/14] dependency integrity (composer.lock vs composer.json vs vendor/)
   ✓ dependency-integrity-lint
[2/14] wayfinder:generate --with-form (must match vite.config.ts formVariants)
   ✓ wayfinder:generate
[3/14] lint changed files (Pint / Prettier / ESLint, check mode)
   ✓ lint-changed
       Pint (check) on 10 changed PHP file(s)
       Prettier: no changed frontend files
       ESLint: no changed JS/TS files
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
```

Expected PASS; observed PASS, 14/14, no step failed on a file I did not touch.

**This is the second run of the gate, and the first one FAILED.** Reported as two runs, not as one
pass. The first, on the uncommitted tree, failed step 13:

```
[13/14] static analysis (Larastan level 5 vs baseline)
   ✗ larastan
       {"tool":"phpstan","result":"failed","errors":1,"error_details":{"…/FeeScheduleResource.php":[{"line":38,"message":"Anonymous function should return Illuminate\\Support\\Collection<int, array{id: string, description: string, amount: App\\Support\\Money, bank_account_id: string|null, is_mandatory: bool, is_discountable: bool, sort_order: int}> but returns Illuminate\\Support\\Collection<int, array{…same shape…}>.","identifier":"return.type","tip":"Template type TValue on class Illuminate\\Support\\Collection is not covariant."}]}}

✗ quality: FAIL (1): larastan
```

That is a real red on a file I wrote, not a flake: adding `bank_account_id` widened the mapped array
shape and Larastan then had to unify two identical shapes across `Collection`'s invariant `TValue`.
Fixed by returning `->values()->all()` from the item map — a list array, identical JSON, no
suppression and no baseline entry. `composer analyse` alone then returned
`{"tool":"phpstan","result":"passed","errors":0}` before the gate was re-run.

One other difference between the runs, worth stating because it affects what step 3 proves: on the
first (uncommitted) run, step 3 printed **"Pint: no changed PHP files"** — `bin/lint-changed.sh`
cannot see uncommitted work (the known ticket). I ran Pint explicitly against the ten files
throughout; the post-commit run is the one where the gate itself lints them.

### The drive fixture

```
APP_ENV=drive DB_DATABASE=portal_drive php artisan finance:seed-drive-fixture

Drive fixture seeded. Sign in at APP_URL with any user below (password: drive-password):
+--------------------------------------------+-------------------------+
| Role in the drive | Email |
+--------------------------------------------+-------------------------+
| Maker (accounts_officer) | maker@drive.test |
| Full checker (executive_director) | checker@drive.test |
| Void-only checker (no credit-note.approve) | void-checker@drive.test |
| Super admin | super@drive.test |
| School B bursar (isolation) | school-b@drive.test |
+--------------------------------------------+-------------------------+

Academic slot per school — the fee-schedules screen selects a term and a class level:
+--------------+-------------------+-------+--------------+
| School | Academic sessions | Terms | Class levels |
+--------------+-------------------+-------+--------------+
| A (school#1) | 1 | 1 | 2 |
| B (school#2) | 1 | 1 | 2 |
+--------------+-------------------+-------+--------------+
Statements: open /finance and click a student; the queue is /finance/approvals.
```

Expected 1/1/2 per school; observed 1/1/2 per school. Run against a throwaway `portal_drive`
database created for this proof and **dropped afterwards** — the command `migrate:fresh`-es, and its
two guards (APP_ENV=drive, and a database name matching `/(^|_)drive(_|$)/`) both had to be satisfied
honestly rather than bypassed.

### Required columns, read from `information_schema` — not inferred from `$fillable`

Query run through `php artisan tinker` against `portaa10_portal`:
`SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION`.

```
===== terms =====
id bigint unsigned null=NO default=NULL auto_increment
uuid char(36) null=NO default=NULL
academic_session_id bigint unsigned null=NO default=NULL
school_id bigint unsigned null=NO default=NULL
name varchar(255) null=NO default=NULL
slug varchar(255) null=NO default=NULL
order tinyint unsigned null=NO default=NULL
start_date date null=NO default=NULL
end_date date null=NO default=NULL
status enum('active','upcoming','completed') null=NO default=upcoming
created_at timestamp null=YES default=NULL
updated_at timestamp null=YES default=NULL
registration_deadline timestamp null=YES default=NULL
result_visible_at timestamp null=YES default=NULL
===== class_levels =====
id bigint unsigned null=NO default=NULL auto_increment
uuid char(36) null=NO default=NULL
school_id bigint unsigned null=YES default=NULL
name varchar(255) null=NO default=NULL
level_type enum('JSS','SSS') null=YES default=NULL
order smallint unsigned null=NO default=0
created_at timestamp null=YES default=NULL
updated_at timestamp null=YES default=NULL
grading_scheme_id bigint unsigned null=YES default=NULL
===== academic_sessions =====
id bigint unsigned null=NO default=NULL auto_increment
uuid char(36) null=NO default=NULL
school_id bigint unsigned null=YES default=NULL
name varchar(255) null=NO default=NULL
slug varchar(255) null=NO default=NULL
is_current tinyint(1) null=NO default=0
created_at timestamp null=YES default=NULL
updated_at timestamp null=YES default=NULL
current_school_key bigint unsigned null=YES default=NULL VIRTUAL GENERATED
```

So the columns the seeder MUST supply are: `terms` — `academic_session_id`, `school_id`, `name`,
`slug`, `order`, `start_date`, `end_date` (all NOT NULL, no default; `status` defaults to `upcoming`,
`uuid` is filled by the model's `creating` hook); `class_levels` — `name` only (`school_id` nullable,
`order` defaults 0, `level_type` nullable); `academic_sessions` — `name` and `slug` (`is_current`
defaults 0). I set `status = active` on the term and `level_type = JSS`, `order = 1|2` on the class
levels because a drive fixture should look like data, not like the minimum the schema permits.

The unique keys that constrain the seeder, from `SHOW INDEX`:

```
terms_academic_session_id_order_unique (academic_session_id, order)
terms_academic_session_id_slug_unique  (academic_session_id, slug)
terms_uuid_unique                      (uuid)
academic_sessions_slug_school_id_unique   (slug, school_id)
academic_sessions_current_school_unique   (current_school_key)  -- generated column
class_levels: unique_uuid only
```

Hence the per-school slug suffix on both the session and the term.

## The watched red

Six mutations, one at a time, each restored immediately and re-run green.

**RED 1 — the request split.** `FeeScheduleController::editDraft`'s type-hint changed back to
`FeeScheduleRequest`:

```
{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":1,
 "test":"…it_accepts_an_edit_that_sends_NO_term__id_and_NO_class__level__id…",
 "message":"Expected response status code [200] but received 422.\nFailed asserting that 422 is identical to 200.\n\nThe following errors occurred during the last request:\n\n{\n    \"message\": \"There are validation errors\",\n    \"errors\": {\n        \"term_id\": [\n            \"The term id field is required.\"\n        ],\n        \"class_level_id\": [\n            \"The class level id field is required.\"\n        ]\n    }\n}"}
```

The message names both discarded fields by name. Restored.

**RED 2 — `bank_account_id` deleted from the resource's item map.**

```
{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":3,
 "test":"…index_serialises_each_item’s_destination_account_as_a_uuid…",
 "message":"Failed asserting that null is identical to 'a27a6df1-1738-40c2-b6c4-ce845a732eea'."}
```

Note: the failure is `null is identical to <uuid>`, **not** "Unable to find JSON path" —
`assertJsonPath` reads an absent path as null. The comment in the test says so, because I watched it
say this and not the other thing. Restored.

**RED 3 — `term.academicSession` and `classLevel` dropped from `index()`'s eager loads.** Same arm,
one assertion further on:

```
{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":4,
 "message":"Failed asserting that null is identical to '2026/2027 — First Term'."}
```

This is the one that matters for `whenLoaded`: dropping an eager load does not error, it silently
empties the label. Restored.

**RED 4 — both labels made unconditional accessors** (`whenLoaded` removed):

```
{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":4,
 "test":"…prefill’s_payload_keeps_its_shape_—_the_two_labels_are_ABSENT_there__not_present_and_null",
 "message":"prefill grew or lost a top-level key on the schedule. term_label/class_level_label must be ABSENT on this path, not present-and-null, and nothing else about this payload moves.\nFailed asserting that two arrays are identical.\n--- Expected\n+++ Actual\n@@ @@\n     0 => 'id',\n     1 => 'term_id',\n     2 => 'class_level_id',\n-    3 => 'label',\n-    4 => 'status',\n-    5 => 'items',\n+    3 => 'term_label',\n+    4 => 'class_level_label',\n+    5 => 'label',\n+    6 => 'status',\n+    7 => 'items',\n ]"}
```

Restored.

**RED 5 — the isolation half of the index filter.** `->where('school_id', ActiveSchool::id())`
removed from the `term_id` rule:

```
{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":12,
 "test":"…index_filters_by_term_and_by_status__and_REFUSES_a_term__id_belonging_to_another_School",
 "message":"Expected response status code [422] but received 200.\nFailed asserting that 200 is identical to 422."}
```

Twelve assertions passed before it, so the filter arms themselves stayed green and only the isolation
refusal turned into a 200 — the other School's term became an acceptable filter value. Restored.

**RED 6 — the drive fixture's academic slot.** Both `seedAcademicSlot()` calls removed from
`DriveCastSeeder::run()`, command re-run:

```
Academic slot per school — the fee-schedules screen selects a term and a class level:
+--------------+-------------------+-------+--------------+
| School | Academic sessions | Terms | Class levels |
+--------------+-------------------+-------+--------------+
| A (school#1) | 0 | 0 | 0 |
| B (school#2) | 0 | 0 | 0 |
```

This is what proves the counts are read from the database rather than printed from the seeder's own
intent. Restored, re-run, back to 1/1/2.

## Database observations

No migration; no schema change; no row written to any real database by this commit.

- Dev copy `portaa10_portal`: read only, through `php artisan tinker`, for `information_schema` and
  `SHOW INDEX`. Not migrated, not written to. It is currently **one migration behind the branch** —
  `2026_08_10_120000_finance_bank_account_foreign_keys` is `Pending` there, which is why
  `finance_fee_items` on that copy has no `bank_account_id` column yet. I left it that way: this
  commit ships no screen to drive, and migrating a production copy was not asked for.
- Test database `portal_testing`: `RefreshDatabase`, migrated from zero by the suite.
- `portal_drive`: created for the fixture proof, `migrate:fresh`-ed by the command's own guarded
  path, **dropped** afterwards. Schools in it were `school#1` and `school#2`.

## Not done

- **No browser drive.** Commit 1 ships no route and no screen, so there is nothing to open. The
  fixture the drive needs is now in place and proven by the count table; the drive itself is commit
  2's.
- **The `status` filter is not bite-proven for isolation**, only for enum validity (`?status=publishedish`
  → 422) and for selectivity (`?status=active` returns exactly the active one). It carries no
  School dimension — `FeeSchedule` is `BelongsToSchool`, so `SchoolScope` bounds the rows — so there
  was nothing to scope on the rule.
- **`bank_account_id` is not asserted on the `store` or `supersede` responses.** It is asserted on
  `index`, on `prefill`, and on the `editDraft` response. The three write routes render the same
  resource through the same `loadMissing`, so I judged one of the three sufficient; if a reviewer
  disagrees that is a cheap arm to add.
- **The trait's `itemSpecs()` is not separately pinned.** It is exercised by every existing
  create/edit arm in both files (a broken `itemSpecs()` fails them all), but there is no arm whose
  name says so.
- **Choice I made without asking:** `nullable` rather than `sometimes` on both index filters, so that
  `?term_id=` (empty, from a screen that has not chosen a term) means "all" rather than 422. The
  brief said "optional"; `sometimes` would have made an empty-string parameter a validation error.

## Findings raised, not fixed

- `terms.terms_end_after_start_check` is **absent from the dev copy `portaa10_portal`**, although
  `2026_07_28_120000_add_term_date_order_check` reports `Ran` in `migrate:status` there. Fifteen other
  CHECK constraints are present in that database, so this is not a MySQL-version or query artefact —
  I queried `information_schema.CHECK_CONSTRAINTS` for the schema and the constraint is simply not in
  the list. I did not chase it. **Suggested severity: ticket** — it is a divergence between the
  migration ledger and the actual schema on the production copy, and if it is also true of
  production then a backwards term window is unconstrained there. Out of scope for this commit, and I
  have not checked production (nor should I from here).
- `tests/Feature/Finance/EditFeeScheduleDraftTest.php:227` — `$theirs` is assigned and never used
  (pre-existing, flagged by the IDE while I was editing the file, untouched by this commit).
  **Suggested severity: ticket**, and only if someone is already in the file.

---

# Remediation of the cold review — 2026-08-11

All five review findings are closed. Three as fixes the lead re-graded up from ticket (2, 3, 5), one
as a fix (1), one as a filed ticket (4), plus a sixth ticket and one extra arm the lead asked for.

`48279f5` was unpushed, so the work was folded into it rather than stacked on top. The branch is now
**one implementation commit, `b141e89`**, plus this report's commit. `48279f5` and the old report
commit `01c328a` no longer exist on the branch. Nothing was ever pushed, so no published ref moved.

`git diff --stat 59e1da8..b141e89` — 19 files, +803/−131.

## FIX 1 — the drive fixture could not author in School B

**Reproduced before fixing.** `DriveFinanceStates::bankAccountId()` was `private` and called from
`:62`, `:69` and `:84` only — all three `RecordPayment` paths. `SeedDriveFixture:88` gave School B
one state, `plainInvoice`, which records no payment. So School B had no account, while
`school-b@drive.test` holds `accounts_officer` (`DriveCastSeeder:157`), which holds
`finance.fee-schedule.manage` (`RbacSeeder.php:381`), and `HasFeeScheduleItemRules` requires a
School-scoped, not-deactivated `bank_account_id` on every line.

**Closed with one source, not a second creation site.** `bankAccountId()`'s body moved to a new
**public `DriveFinanceStates::ensureBankAccount(int $schoolId)`**; `bankAccountId()` is now a
one-line delegate to it, kept under its own name because the three payment call sites are asking
"which account did this money land in", not "make sure one exists". `SeedDriveFixture` calls
`ensureBankAccount` for both school ids, inside the matching `ActiveSchool::runFor`, before any state
block runs. The `account_number` formula `'90'.str_pad($schoolId, 8, '0', STR_PAD_LEFT)` therefore
still has exactly one definition, and the payment paths hit the same `firstOrCreate` key and **find**
the row.

**The count the brief asked for: School A has ONE bank account after a full seed.** Counted from the
seeded database, not from the report table:

```
school#1 accounts=1
school#2 accounts=1
school#1 account_number=9000000001
school#2 account_number=9000000002
payments=3 distinct_account_ids=1
```

Three payments across School A, all pointing at one account id. That is what proves the delegate
finds the seeded row rather than creating a second beside it.

**The new four-column table**, from `APP_ENV=drive DB_DATABASE=portal_drive php artisan
finance:seed-drive-fixture` (throwaway database, dropped after):

```
Authoring slot per school — the fee-schedules screen selects a term, a class level and an account:
+--------------+-------------------+-------+--------------+---------------+
| School | Academic sessions | Terms | Class levels | Bank accounts |
+--------------+-------------------+-------+--------------+---------------+
| A (school#1) | 1 | 1 | 2 | 1 |
| B (school#2) | 1 | 1 | 2 | 1 |
+--------------+-------------------+-------+--------------+---------------+
```

**The docblock was rewritten, not preserved.** The old one said the account is created in
`DriveFinanceStates` "because this class is the only thing that records payments, and a fixture
account that exists but is never used would be a row nobody could explain". That reasoning is false
as of U1 and the new docblock says so, names both consumers (`finance_payments.bank_account_id`'s
origin-keyed CHECK, and `finance_fee_items.bank_account_id`), and states why calling it twice is safe.

**One deviation inside this fix.** The brief said to add the bank-accounts column using "same
`$count` closure, table `finance_bank_accounts`". I could not: `bin/ci-boundary-lint.php:127` fails a
`finance_*` table literal anywhere outside `app/Finance/`, and `SeedDriveFixture` is
`app/Console/Commands/`. Nor could the count be written with `DB::table` inside `app/Finance` — the
`finance-escape-hatches` rule at `:136` forbids `DB::table(` and `withoutGlobalScopes(` there. So the
Finance side counts its own table through the scoped model:
`DriveFinanceStates::bankAccountCount()`, called inside `ActiveSchool::runFor`. The column is in the
table; the closure that produces it is not the same one.

## FIX 2 — one term, three screens, one string

`Term::displayLabel(): string` added at `app/Models/Term.php`, holding the `trim()`/concat with the
`?? ''` on the session hop. All three sites point at it: `FeeScheduleResource`
(`term_label`), `routes/web.php` (the opening-balance term select), and `FeeScheduleChangeResource`
(`target_term`, which printed the **bare** name).

The arm — "one term is named the same string by all three screens that name it",
`tests/Feature/Finance/FeeScheduleTest.php` — builds `'2026/2027 — First Term'` **as a literal** and
asserts it at all three, over HTTP. It does not call `displayLabel()` to compute what it expects; it
calls it once to assert the method itself returns that literal.

Three seats, because no seeded role holds all three abilities: `finance.access` for the list,
`finance.opening-balance.submit` for the operator screen, `finance.fee-schedule.change.approve` for
the pending feed. Added `fsUser(School, array $permissions)` — the permission-keyed actor shape
`OpeningBalanceOperatorScreenTest:63` already uses, for its reason: a role-keyed actor moves under the
test the next time the grants map changes.

**Not asked for, and done:** `FeeScheduleChangeController::pending()` eager-loaded
`target.term`, which was enough for `->term->name` and is not enough for `displayLabel()` — it would
lazy-load one `academicSession` per pending row. Changed to `target.term.academicSession`. No output
changes; it is a query count.

I did **not** touch `resources/js/lib/finance/approval-feeds.ts:207`, which joins
`target_class_level` and `target_term` for display. It now shows the longer string, which is the
intent. `ApprovalsQueueRendersEveryTypeTest.php:415` asserts only `toBeString()->not->toBe('')` and
`:422` uses the value inside a uniqueness key, so neither constrains the format — confirmed by
running that file green.

## FIX 3 — `?->` on both new labels

`FeeScheduleResource`'s `term_label` is now `$this->term?->displayLabel()` and `class_level_label` is
`$this->classLevel?->name`.

**No arm, deliberately, and this is the honest reason:** `?->` is a language operator, not a rule.
There is no guard here to bite-prove — the mutation that would exercise it is a schedule whose
`term_id` or `class_level_id` names a row invisible under `SchoolScope`, and that row is not
constructible through today's validation (both `exists` rules are School-scoped). An arm asserting
"a null relation yields a null label" would be asserting PHP, not asserting this codebase. The
change is justified by coherence with `$item->bankAccount?->uuid` three lines below it, not by a
demonstrated failure, and I am not dressing it as more than that.

## FIX 5 — the comment this commit made false

`tests/Feature/Finance/EditFeeScheduleDraftTest.php`'s bank-account arm no longer claims
"FeeScheduleRequest IS REUSED, not re-implemented". It now says the rule reaches the edit path through
`HasFeeScheduleItemRules`, shared rather than copied, and that **this arm passing unchanged across the
request split is the evidence that the share is real** — which is what the review established it to be.

## TICKET 4 — the CHECK constraint

`docs/handoff/tickets/term-date-order-check-absent-from-schema.md`. Filed as **the reviewer's
reproduction, attributed**, because neither I (in the remediation pass) nor the lead ran the SQL: the
ledger row at batch 11, the 15-constraints-and-0-matching read, the unconditional `ALTER` at
`:34-40`, ADR 0052's verify-by-shape rule, and the open production question named as the lead's.

## TICKET 6 — the unarmed isolation half

`docs/handoff/tickets/fee-schedule-item-bank-account-foreign-school-unarmed.md`. Not fixed here, per
the brief. The ticket records what the review established — the rule text is byte-identical to base,
so this commit did not open the gap — and adds one thing the review did not: the composite FK
`finance_fee_items_bank_account_school_foreign` means a cross-School reference is refused at the
database, but both Actions resolve the uuid through the scoped model, so a foreign uuid becomes a
**500**, not the 422 the rule exists to produce. That is what the missing arm would pin.

## The added arm — the empty filter

"an EMPTY filter means unfiltered", `FeeScheduleTest`. `?term_id=&status=` must return byte-identical
JSON to no query string at all.

**Watched red** by swapping `nullable` for `required` on the `term_id` rule:

```
{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":1,
 "message":"Expected response status code [200] but received 422.\nFailed asserting that 422 is identical to 200.\n\nThe following errors occurred during the last request:\n\n{\n    \"message\": \"There are validation errors\",\n    \"errors\": {\n        \"term_id\": [\n            \"The term id field is required.\"\n        ]\n    }\n}"}
```

Restored.

## The label arm's watched red

`FeeScheduleChangeResource`'s `target_term` put back to `$this->target?->term?->name`:

```
{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":5,
 "test":"…one_term_is_named_the_same_string_by_all_three_screens_that_name_it",
 "message":"Failed asserting that two strings are identical.\n--- Expected\n+++ Actual\n@@ @@\n-'2026/2027 — First Term'\n+'First Term'"}
```

Five assertions passed first — `displayLabel()` itself, and sites 1 and 3 — so the failure names the
approvals queue specifically, which is the copy that disagreed. Restored.

## Proof

The four files the label change reaches, run together:

```
DB_DATABASE=portal_testing ./vendor/bin/pest tests/Feature/Finance/FeeScheduleTest.php tests/Feature/Finance/EditFeeScheduleDraftTest.php tests/Feature/Finance/FeeScheduleChangeTest.php tests/Feature/Finance/OpeningBalanceOperatorScreenTest.php
{"tool":"pest","result":"passed","tests":60,"passed":60,"assertions":296,"duration_ms":35279}
```

`bin/quality`, on `b141e89`. **One run, green** — no red to report this time:

```
quality gate — base 59e1da8

[1/14] dependency integrity (composer.lock vs composer.json vs vendor/)
   ✓ dependency-integrity-lint
[2/14] wayfinder:generate --with-form (must match vite.config.ts formVariants)
   ✓ wayfinder:generate
[3/14] lint changed files (Pint / Prettier / ESLint, check mode)
   ✓ lint-changed
       Pint (check) on 15 changed PHP file(s)
       Prettier: no changed frontend files
       ESLint: no changed JS/TS files
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
```

Step 3's "15 changed PHP file(s)" is the remediated commit's PHP count (19 files total, four of them
markdown).

## What I could not verify in this pass

- **The CHECK-constraint drift.** I re-read `information_schema` on the dev copy in the first pass and
  saw the same thing the reviewer did, but the ticket is filed under the reviewer's reproduction
  because that is what the brief instructed and because neither reading touched production.
- **No browser drive.** Still nothing to open — commit 1 ships no route. The account fix is proven by
  the seed, the row counts and the account numbers, not by a screen.
- **The approvals queue's rendered string.** I asserted `target_term` on the wire and confirmed the
  two arms that read it stay green; I did not render the React component.
- **The three-seat arm's dependence on the grants map.** `fsUser()` creates roles from bare permission
  lists, so it does not depend on the seeded map — but it does depend on those three permission
  strings continuing to gate those three routes. Nothing pins that beyond the routes themselves.

---

# Final pass on the second cold review — 2026-08-11

Five findings from the second review. Four closed as fixes, one filed as a ticket, one run performed.
Three of the four fixes are comment corrections, which is not busywork in a commit whose CORRECTIONS
section is about false comments.

Folded into the implementation commit again rather than stacked. The branch is now
**`6abe3db`** plus this report's commit; `b141e89` and `16d18ff` no longer exist on it. Still
unpushed, so no published ref has moved at any point.

`git diff --stat 59e1da8..6abe3db` — **20 files, +947/−132.**

## FIX A — the three write routes returned no labels

**Reproduced.** `CreateFeeSchedule:85` and `EditFeeScheduleDraft:90` both return
`->load(['items' => fn ($q) => $q->orderBy('sort_order')])` — no `term`, no `classLevel` — and the
controller added only `loadMissing('items.bankAccount')` at the three write sites. So `index` returned
`term_label`/`class_level_label` and `store` (201), `editDraft` (200) and `supersede` (200) returned
neither.

All three `loadMissing` calls are now
`loadMissing('items.bankAccount', 'term.academicSession', 'classLevel')`. **Not in the Actions** — an
Action's return value is its contract with every caller including its tests, and what a payload
renders is the controller's business. `prefill` is deliberately unchanged and still returns the
labels absent; its key-list arm still passes.

**The arm:** "the three WRITE routes return the same schedule shape as index — labels included",
`tests/Feature/Finance/FeeScheduleTest.php`. Same shape as the prefill arm — a key-list identity
against
`['id','term_id','class_level_id','term_label','class_level_label','label','status','items']` — plus
the `term_label` **value**, so a label that is present-and-null cannot pass either. Each of the three
assertions carries a message naming its route and method.

One thing the arm had to work around and says so at the call site: `supersede` authors a fresh draft
for the same slot, and `finance_fee_schedules_pending_unique` permits one draft-or-pending per slot,
so the existing draft is moved to `active` with a raw write before the third leg runs.

**WATCHED RED**, dropping the two relations from `editDraft`'s `loadMissing` only:

```
{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":5,
 "message":"editDraft (PUT /v1/finance/fee-schedules/{uuid}/draft, 200) does not return the same schedule shape as index(). A page re-rendering a row from this response loses whichever key is missing.\nFailed asserting that two arrays are identical.\n--- Expected\n+++ Actual\n@@ @@\n     0 => 'id',\n     1 => 'term_id',\n     2 => 'class_level_id',\n-    3 => 'term_label',\n-    4 => 'class_level_label',\n-    5 => 'label',\n-    6 => 'status',\n-    7 => 'items',\n+    3 => 'label',\n+    4 => 'status',\n+    5 => 'items',\n ]"}
```

Five assertions passed first — `store`'s key list and value, and the two `assert` calls before them —
so the failure names `editDraft` specifically and the other two legs stayed green. Restored.

## FIX B — the `whenLoaded` rationale was false

Read out of vendor rather than asserted.
`vendor/laravel/framework/src/Illuminate/Http/Resources/ConditionallyLoadsAttributes.php:274-293`:

```php
protected function whenLoaded($relationship, $value = null, $default = new MissingValue)
{
    if (! $this->resource->relationLoaded($relationship)) {
        return value($default);
    }

    $loadedValue = $this->resource->{$relationship};

    if (func_num_args() === 1) {
        return $loadedValue;
    }

    if ($loadedValue === null) {
        return;
    }

    if ($value === null) {
        $value = value(...);
    }

    return value($value, $loadedValue);
}
```

So with two arguments and a loaded-null relation it returns `null` at the third `if` and the closure
is never evaluated. There is no path on which `$this->term?->displayLabel()` could have raised, and
therefore no 500 the `?->` was preventing.

The `?->` is **kept**, and the comment block on `FeeScheduleResource` now states both cases in one
place rather than describing two different cases as though they were one:

- relation **not loaded** → `value($default)` = `MissingValue` → the key is **absent** (this is
  `prefill`);
- relation **loaded to null** → returns at `:284-286` → the key is **present and null**, and that
  outcome is reachable for a schedule whose slot is invisible under `SchoolScope`.

The earlier sentence four lines above it said "present-and-null would be a claim that the schedule has
no term", which read as though present-and-null were unreachable; the two are now reconciled, and the
block says explicitly that the previous version asserted a framework behaviour that does not exist.
See CORRECTION 3 at the top of this file for the attribution.

## FIX C — `Term::displayLabel()`'s docblock

**(i)** "in one place" was false. Three more expressions exist, in the opposite word order with a
different separator:

```
app/Http/Resources/TermResource.php:20   $this->name.' - '.$this->academicSession->name
app/Services/BroadsheetService.php:65    $term->name.' - '.$term->academicSession->name
app/Services/BroadsheetService.php:163   same
```

The claim is now narrowed to "the three finance-adjacent screens", those three sites are named in the
docblock as surfaces that deliberately do not read it, and the docblock points at the new ticket.

**(ii)** "an unloaded or out-of-scope session must degrade" was half false. On an **unloaded**
relation the property access lazy-loads and returns the real name. The grep the brief asked for,
pasted whatever it showed — it showed nothing:

```
$ grep -rn "preventLazyLoading\|shouldBeStrict\|preventSilentlyDiscarding\|preventAccessingMissing" app/ bootstrap/ config/
EXIT=1
```

No matches, so there is no strict-mode violation exception either — the lazy load simply happens. The
docblock now says the hop lazy-loads when unloaded, degrades only when the relation resolves to null,
and that callers must keep eager-loading `academicSession` or pay one query per row, naming
`FeeScheduleController::index` and `FeeScheduleChangeController::pending` as the two that do.

## FIX D — the broken cross-reference

`FeeScheduleRequest`'s "Same shape and same reason as `items.*.bank_account_id` **below**" now reads
"…in `{@see HasFeeScheduleItemRules}` — it used to be BELOW this line and U1 moved it into the shared
trait". A reader checking the three-way symmetry can now tell relocation from removal at a glance,
which is the question the split most needs answerable.

## TICKET — the platform names a term two ways

`docs/handoff/tickets/term-label-two-formats-across-the-platform.md`. Records both formats, that they
differ in word order **and** separator so they are not interchangeable, the six sites, and that
converging them changes what renders on result screens and in exported broadsheets — a product
decision, which is the reason it is a ticket.

## RUN — the fourth column's red

**The mutation does not produce 0/0, and the reason is the finding itself.** Removing both
`ensureBankAccount` calls and re-seeding:

```
Authoring slot per school — the fee-schedules screen selects a term, a class level and an account:
+--------------+-------------------+-------+--------------+---------------+
| School | Academic sessions | Terms | Class levels | Bank accounts |
+--------------+-------------------+-------+--------------+---------------+
| A (school#1) | 1 | 1 | 2 | 1 |
| B (school#2) | 1 | 1 | 2 | 0 |
+--------------+-------------------+-------+--------------+---------------+
```

School A still reads **1** because its payment paths call `bankAccountId()` → `ensureBankAccount()`
and create the row on first use; School B reads **0** because its only state is a plain invoice that
records no payment. That asymmetry — `1` and `0` in the same column, on the same run — **is** the
defect FIX 1 closed, reproduced. A `0/0` would have required also deleting School A's payment states,
which would have been mutating a different thing.

Restored, re-seeded:

```
Authoring slot per school — the fee-schedules screen selects a term, a class level and an account:
+--------------+-------------------+-------+--------------+---------------+
| School | Academic sessions | Terms | Class levels | Bank accounts |
+--------------+-------------------+-------+--------------+---------------+
| A (school#1) | 1 | 1 | 2 | 1 |
| B (school#2) | 1 | 1 | 2 | 1 |
+--------------+-------------------+-------+--------------+---------------+
```

Throwaway `portal_drive` database both times, dropped after the second run.

## Proof

The four files the changes reach:

```
DB_DATABASE=portal_testing ./vendor/bin/pest tests/Feature/Finance/FeeScheduleTest.php tests/Feature/Finance/EditFeeScheduleDraftTest.php tests/Feature/Finance/FeeScheduleChangeTest.php tests/Feature/Finance/OpeningBalanceOperatorScreenTest.php
{"tool":"pest","result":"passed","tests":61,"passed":61,"assertions":305,"duration_ms":36068}
```

61/305, up from 60/296 — one new arm, nine new assertions.

`bin/quality` on `6abe3db`. **One run, green:**

```
quality gate — base 59e1da8

[1/14] dependency integrity (composer.lock vs composer.json vs vendor/)
   ✓ dependency-integrity-lint
[2/14] wayfinder:generate --with-form (must match vite.config.ts formVariants)
   ✓ wayfinder:generate
[3/14] lint changed files (Pint / Prettier / ESLint, check mode)
   ✓ lint-changed
       Pint (check) on 15 changed PHP file(s)
       Prettier: no changed frontend files
       ESLint: no changed JS/TS files
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
```

## Deviations in this pass

**One.** The brief said the drive-fixture run would show the Bank accounts column "at 0/0". It shows
`1/0`, for the reason set out under RUN above — School A's payment paths create the account whether
or not the seeding call exists. I ran the mutation the brief named and pasted what it produced rather
than reshaping the mutation to produce the predicted number.

## What I could not verify in this pass

- **The rendered approvals-queue string.** `target_term` is asserted on the wire and the two arms that
  read it stay green; no React component was rendered by anyone.
- **The three Format B sites.** I read them and quoted them; I did not run a broadsheet export, so
  "converging them changes what renders" is inference from the string, not an observed diff.
- **The whole suite beyond the four files, other than through the gate's step 14 ratchet.** The gate
  passed once, and ADR 0053's determinism residual applies to a single green run here as anywhere.
- **`terms_end_after_start_check`** remains a filed ticket under the first reviewer's reproduction. I
  did not re-query the dev copy in this pass, and production is untouched and the lead's.

---

# Last pass on the third cold review — 2026-08-11

Four findings, all four closed as fixes, no tickets. Three are comment corrections and one commits
the brief. **No behaviour changes in this pass** — not one line of executable code moved, and the
suite proves that by staying green without a single assertion being touched.

Folded into the implementation commit again. The branch is now **`054277f`** plus this report's
commit; `6abe3db` and `78386ca` no longer exist on it. Never pushed at any point.

`git diff --stat 59e1da8..054277f` — **21 files, +1640/−135.** The jump over the previous pass's
+947 is almost entirely the brief file, which reproduces four blocks verbatim.

## Deviation, stated first

**FIX D said "the three blocks you were sent in this chat — the commit-1 brief, the remediation
block, and the final-pass block". I reproduced FOUR — those three, plus the last-pass block that
asked for the file.** The reason FIX D gives for the file is that a reviewer can diff what was asked
against what was built; leaving out the block that asked for the four fixes in this very commit would
break exactly that for the most recent quarter of the work. It is reproduced under the same rule as
the others — verbatim, unedited, in arrival order — and the file says at the head of that section
that it was not among the three named and why it is there. If the intent was strictly three, deleting
the last section is a one-edit revert.

## FIX A — the index isolation rule's stated reason

**The guard was right and the reason was wrong, and those are different failures.** The rule
`Rule::exists('terms','id')->where('school_id', ActiveSchool::id())` is unchanged, the assertion is
unchanged, and RED 5 stands exactly as it was recorded — it was only ever the *interpretation*
attached to it that was false. A control that does the right thing for a reason nobody can verify is
one reader away from being deleted as decorative; a control that does the wrong thing is a defect
today. This was the first kind.

**The two rewritten passages, in full — the deliverable.**

`app/Finance/Http/Controllers/FeeScheduleController.php`, the `index()` docblock:

```php
    /**
     * The School's schedules, newest first, with TWO OPTIONAL FILTERS and nothing else.
     *
     * `term_id` carries the same School-scoped `exists` rule as {@see FeeScheduleRequest} — written as an
     * explicit `where` rather than through the scoped model, because Rule::exists queries the TABLE and no
     * global scope applies to it.
     *
     * WHAT THE SCOPING CLOSES IS A TERM-ID EXISTENCE ORACLE, PLATFORM-WIDE — and nothing about the rows.
     * `FeeSchedule` uses BelongsToSchool, so this query is bounded to the active School before
     * `where('term_id', …)` is applied: the response is `200 []` whether another School's term has zero
     * schedules or fifty, and no count, no id and no fact about that School is conveyed either way.
     *
     * What an UNSCOPED `Rule::exists('terms','id')` would convey is the difference between **422** (this
     * term id exists nowhere on the platform) and **200 []** (it exists in SOME school). Feed it ids and
     * it enumerates which term ids are real across every school on the installation. Scoped, both cases
     * are the same 422, and the question is never answered rather than answered emptily.
     *
     * ABSENT MEANS UNFILTERED. Both are `nullable`, so an empty `?term_id=` is "all" rather than a 422 on
     * a screen that has not chosen a term yet, and nothing that calls this endpoint today changes.
     *
     * PAGINATION IS NOT HERE. A caller passing no term still gets every schedule the School has ever
     * written, with its items — see docs/handoff/tickets/fee-schedule-index-unpaginated.md.
     */
```

`tests/Feature/Finance/FeeScheduleTest.php`, the isolation arm's docblock:

```php
    /*
     * WATCHED RED on the isolation half: removing `->where('school_id', ActiveSchool::id())` from the
     * term_id rule turns the last assertion's 422 into a 200.
     *
     * WHAT THAT 200 DISCLOSES IS NOT THE ROWS. `FeeSchedule` uses BelongsToSchool, so the body is `[]`
     * whether the other School's term has zero schedules or fifty — nothing about that School travels
     * either way. What travels is the STATUS CODE: unscoped, 422 means "this term id exists nowhere on
     * the platform" and 200 means "it exists in some school", which turns the endpoint into a term-id
     * existence oracle across every school on the installation. Scoped, both are 422.
     *
     * So the rule is the control, and the control is about the code, not the collection.
     */
```

The self-refuting parenthetical — "(The rows themselves are safe either way — SchoolScope bounds
them — so this is about not answering the question, not about leaking the rows.)" — is gone from the
controller, and the "told, truthfully, how many schedules another School's term has" clause is gone
from the test.

## FIX B — the whenLoaded comment, cut

Twenty-one lines to six. The whole block now reads:

```php
            // whenLoaded: relation unloaded → key ABSENT (this is prefill). Relation loaded to NULL →
            // key PRESENT and null, returned before the closure runs (vendor
            // ConditionallyLoadsAttributes.php:284-286). No write path here produces a loaded-null term or
            // class level — both `exists` rules are School-scoped — so that second case is a shape
            // guarantee, not an observed one, and the `?->` is inert, kept only for coherence with
            // `$item->bankAccount?->uuid` below.
```

The "AN EARLIER VERSION OF THIS COMMENT SAID" paragraph is **deleted**; CORRECTION 3 at the top of
this file is where that changelog lives. The "IS reachable" claim that contradicted this report's own
FIX 3 is gone — the loaded-null case is now stated as a shape guarantee with no write path behind it,
which is what both texts can honestly say at once.

## FIX C — `routes/web.php`'s false substrate fact

Confirmed pre-existing before touching it:

```
$ git show 59e1da8:routes/web.php | sed -n '193,202p'
     * School through the `tenant` middleware and an explicit where: `terms` is not a BelongsToSchool
     * model, so this one is written rather than inherited.

$ git show 59e1da8:app/Models/Term.php | sed -n '14,17p'
class Term extends Model
{
    use BelongsToSchool, LogsActivity;
```

So the claim was false at the base, not introduced here — but this commit edited two lines below it
and converged three term-label sites, so it read past the line. Corrected to say that `Term` does
carry `BelongsToSchool`, that `SchoolScope` therefore already bounds the query and the explicit
`where` is the redundant one, and why it is kept anyway: the route runs inside `tenant`, and an
explicit predicate on a props query lets the next reader of the closure see what the select is bounded
by without going to the model. **The `where` was not deleted.**

## FIX D — the brief is committed

`docs/handoff/u1-fee-schedules-brief.md`. Four blocks, verbatim, in arrival order, fenced so their
indentation and backticks survive rendering — the fences are the only thing added, and the file says
so. A header states that commit 2 (the route, the page, the sidebar entry) is not written and is not
covered, and points at this report as the answer to the blocks.

## Proof

Comment-only pass, so the arms are unchanged and the point of running them is that nothing moved:

```
DB_DATABASE=portal_testing ./vendor/bin/pest tests/Feature/Finance/FeeScheduleTest.php tests/Feature/Finance/EditFeeScheduleDraftTest.php tests/Feature/Finance/OpeningBalanceOperatorScreenTest.php
{"tool":"pest","result":"passed","tests":42,"passed":42,"assertions":241,"duration_ms":27943}
```

`bin/quality` on `054277f`. **One run, green:**

```
quality gate — base 59e1da8

[1/14] dependency integrity (composer.lock vs composer.json vs vendor/)
   ✓ dependency-integrity-lint
[2/14] wayfinder:generate --with-form (must match vite.config.ts formVariants)
   ✓ wayfinder:generate
[3/14] lint changed files (Pint / Prettier / ESLint, check mode)
   ✓ lint-changed
       Pint (check) on 15 changed PHP file(s)
       Prettier: no changed frontend files
       ESLint: no changed JS/TS files
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
```

## No watched red in this pass, and why

Nothing executable changed. There is no guard to bite-prove: FIX A rewrites the prose attached to an
assertion that already has a watched red (RED 5) and is untouched, FIX B and FIX C are comments, and
FIX D adds a markdown file. Inventing a mutation to produce a red here would be theatre. The ten
watched reds recorded across the earlier passes all still stand against `054277f`; none of the code
they mutate moved in this pass.

## What I could not verify in this pass

- **That the brief blocks are byte-identical to what was sent.** They were transcribed from the four
  messages in this session and I believe them exact, but I have no copy of the originals outside this
  conversation to diff against. A reviewer comparing them to the lead's own copy is the only real
  check, and that is worth doing precisely because the file's whole value is fidelity.
- **The existence-oracle claim as an observed behaviour.** I reasoned it from
  `Rule::exists('terms','id')` being unscoped against the whole table and from `FeeSchedule` carrying
  `BelongsToSchool`; I did not run a probe that enumerates term ids across schools against a live
  unscoped build. The scoped behaviour — 422 for another School's term — is armed and watched.
- **The suite beyond the three files run here**, other than through the gate's step 14 ratchet. One
  green run, ADR 0053's determinism residual unchanged.
- **Production, and `terms_end_after_start_check`.** Untouched in this pass; still the filed ticket
  under the first reviewer's reproduction, still the lead's question.
