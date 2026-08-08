# Implementation report — `feat/finance-ob-file-format` (§9 step 4a, the FILE FORMAT)

Base: `origin/staging` @ `26e144b`. Branch: `feat/finance-ob-file-format`. Two commits, nine files
(one new migration, four module files, two test files, one docs section, this report).

> **Second pass (2026-08-08) — the review's finding 1 is CONFIRMED and FIXED, and finding 2 was the
> same defect under a different error number.** A row the engine refused used to abort the run and
> leave a committed batch reporting `row_count = 0` over a partially-written rows table, with §7's
> idempotency reference spent. The write now **catches and converts**: a unique violation becomes the
> `duplicate_row_key_in_file` finding, a 1406 becomes `value_too_long`, both are counted in the same
> not-ingested accounting, anything else re-throws. A `max` per column in `COLUMNS` is the defence in
> front of the catch. `last_payment_date` was retired in the same migration. Three watched reds for
> the fix, below. **My earlier claim that "nothing is staged wrong either way" was false, and it was
> made without executing the case** — that is the whole lesson of this pass, and it is corrected at
> all four places it was written (`normaliseLabel()`, the migration docblock, spec §12, and here).

**This is full-review tier** — it touches money columns, a migration, a unique key, an append-only
neighbourhood and a fixture-adjacent test file. Subagent review attached; recommend a cold session
before merge.

---

## Headline

Done, with three deviations and one open fork left open deliberately. The staging tables, the
validator and its test file are realigned onto R5's balance-forward one-row-per-(student × fee type)
file: `fee_type_label` + a signed `balance` + `student_total_balance` in, the four Rev 2/3 money pairs
and `expected_billed` and the batches' three `total_*` pairs out, the key regrained, §1's two-level
checksum replacing the dead four-column identity, §5 and `OpeningBalanceRowStatus::NotComparable`
withdrawn, `wcbs_bill_reference` optional, the non-negative rule gone. `--dry-run` is still the only
mode and the refusal at the top of `handle()` is untouched. `bin/quality` passes all 14 steps; neither
baseline was touched.

## Second pass — what the fix does, and the reasoning I was given and checked

**The batch row is NOT wrapped in a transaction, deliberately.** It is inserted before a byte is
parsed precisely so a concurrent re-run of the same reference stops at
`ob_batches_school_reference_unique` — the create migration's docblock states that as §7's
idempotency guarantee, and I re-read it rather than take it second-hand
(`2026_08_06_100000_...php:42-44`). A transaction hides that row until commit and weakens the
backstop it exists to be. So the fix is catch-and-convert, not atomicity.

**Caught by TYPE and by driver CODE, never by message text.**
`UniqueConstraintViolationException` is its own class; 1406 has none, so it is classified on
`$e->errorInfo[1]` — the code, not the string. Anything unclassified is **re-thrown**: a failure whose
meaning nobody has decided must not be swallowed.

**Every caught row is COUNTED.** Both arms push into `$skipReasons` under the same reason keys the
reader's own drops use (`duplicate_row_key_in_file`, `value_too_long`), so `ingest_incomplete`'s
arithmetic still accounts for the whole gap and `unattributed` stays absent. That is the invariant
`85472dc` installed, and a row that was neither staged nor counted would have broken it silently —
worse than the abort it replaces. Asserted in both new tests.

**A `max` per column, checked before the write.** `mb_strlen`, not `strlen`: a `utf8mb4` varchar
counts CHARACTERS, and a byte count would drop a legitimate accented label (tested — 255 `é` is 510
bytes and is accepted). The four columns backed by `varchar(255)` take the column's own limit, pinned
against `information_schema` by a test so the number cannot drift from the schema. The two money
cells land in `bigint` and inherit no varchar, so theirs is derived: 21 characters is the widest naira
figure a signed bigint holds in kobo. The limit is stated in `format` — what the data team reads — and
held as a number, with a test asserting the two agree.

**One defect, two triggers, one fix — and the layers are independent.** With the `max` rule disabled
the over-length test still passes, because the catch produces the same finding and the same
accounting; with both disabled it aborts. Both states are pasted below.

## Deviations from the brief

**1. `l1_not_checkable` — a finding code the brief did not name, and the rule behind it.**
The brief specifies L1's failure arm ("that student's whole group rejected, both figures in the
finding") but not what happens when L1 *cannot be evaluated* — a group where one row's `balance` or
`student_total_balance` is blank or unparseable. Left unhandled, that row rejects on its own account
and its **siblings stage as `ok` with the group's arithmetic never checked**, which is exactly the
partial-post shape §7 refuses ("posting three of four lines is worse than posting none"). I added
`l1_not_checkable`: the whole group is rejected, naming how many of the student's rows carried no
usable figure. `ImportOpeningBalances.php` — see `l1Verdict()`.

**2. `inconsistent_student_total` — likewise unnamed.** §2 requires the stated total to be repeated
*identically* on every one of a student's rows. When it is not, there is no stated total to check
against, and picking one would be the command inventing the witness it is checking. The group is
rejected and both figures are named. That student also contributes **nothing** to L2 and is counted as
excluded in the L2 finding's message, so L2 cannot go green over a smaller set than the file names.

**3. `nothing_to_post` was kept and repointed** rather than retired with the columns it used to read.
Old meaning: all three Rev 2/3 figures are zero. New meaning: this line's `balance` is zero, so 4b has
nothing to post for it (§7: "Skip the line; there is no movement to post. It still stages, and it
still counts toward L1"). It is informational — added after the status is decided, so it does not
reject.

**The general rule behind deviations 1 and 2, stated as a rule so it can be checked:** *any condition
that leaves L1 unevaluated for a student is a rejection of that student's whole group, not a silence.*
I believe that is right because the alternative is a staged row marked `ok` whose only checksum never
ran — but it is a rule I formed while implementing, and it widens rejection beyond what the brief
enumerated. It is the first thing to argue with.

## Contradictions of the premise

**None in the brief.** All three pre-flight reads matched what the brief said they would:
`2026_08_06_100000:144` carried `unique(school_id, batch_id, admission_number)`;
`ImportOpeningBalances.php:66-74` was the flat `REQUIRED_COLUMNS` list with `wcbs_bill_reference`
required; `:77` was `NON_NEGATIVE_COLUMNS`; `GuardianImportRowValidator.php:15-19` is the docblock the
brief quotes, and its map is keyed by column name with `required/format/example/notes/group`.

**One correction to a durable fact I was carrying:** `bin/quality` is **14 steps**, not 13
(`bin/quality:59` prints `[%d/14]`). Re-derived, not assumed.

**One thing the brief could not have known, found by watching it fail:** dropping
`ob_rows_school_batch_admission_unique` first is refused — MySQL 1553, *"Cannot drop index … needed in
a foreign key constraint"*. That index is what satisfies the `school_id` FK (it has `school_id`
leftmost, and no separate `…_school_id_foreign` index survived the create). The new key has the same
leading column, so the migration adds it **before** dropping the old one, and `down()` does the mirror.
The comment at the site records this so the next person does not rediscover it.

## What changed

| File | What it does |
|---|---|
| `database/migrations/2026_08_08_100000_realign_opening_balance_staging_for_per_fee_type_file.php` (new, 232 lines) | Deletes the staged scratch rows, drops the 8 retiring CHECKs, adds `fee_type_label` (NOT NULL) + `balance_{minor,currency}` + `student_total_balance_{minor,currency}` with the `^[A-Z]{3}$` CHECK under `utf8mb4_bin`, regrains the key to include `fee_type_label`, drops the 5 retiring row pairs and the 3 batch pairs. `down()` restores the shape (not the data). |
| `app/Finance/Console/ImportOpeningBalances.php` (+685/−…) | `COLUMNS` map replaces `REQUIRED_COLUMNS`; `NON_NEGATIVE_COLUMNS` gone; `--control-total=` added and required; three-phase validation (parse → L1 per group → write); L2 as a batch finding; §5, `FeeScheduleLookup` and the enrollment lookup removed; `normaliseLabel()` added. |
| `app/Finance/Enums/OpeningBalanceRowStatus.php` | `NotComparable` removed; two values. |
| `app/Finance/Models/OpeningBalanceRow.php` | Casts `balance` / `student_total_balance`; the four old pairs and `expected_billed` gone. |
| `app/Finance/Models/OpeningBalanceBatch.php` | The three `total_*` casts and the `MoneyCast` import gone; the batch now carries no money column. |
| `tests/Feature/Finance/OpeningBalanceImportTest.php` | Rewritten for the new format: 27 tests, 150 assertions. |
| `docs/handoff/opening-balance-import-spec.md` §12 | Decision 3 closed. |

## §12 decision 3 — the ruling

**The collision is KEPT: `'Tuition'` and `'tuition'` are the same fee type, and the in-PHP detection
was changed to agree with the index.**

- The column is `utf8mb4_unicode_ci` — derived from `information_schema.COLUMNS`, in both
  `portaa10_portal` and `portal_testing`, not read off `config/database.php`. So the new key collides
  the pair at the engine whatever PHP believes.
- Keeping the collision is right on the domain: a WCBS export spelling one fee type two ways has two
  lines for one fee type, which §7 already calls an extract defect. Normalising the *stored* label was
  rejected — R7 carries it verbatim into the ledger narration.
- Byte comparison in PHP was therefore not merely inconsistent, it was **worse than useless**: the
  second row passed the in-PHP pass and died at the INSERT with 1062, aborting the run mid-batch
  instead of producing a named finding. `normaliseLabel()` folds case and trims.
- **Residual, stated rather than implied:** `utf8mb4_unicode_ci` also folds accents and is PAD SPACE;
  `mb_strtolower` + `trim` reproduces case and padding only. An accent-only pair (`'Tuición'` /
  `'Tuicion'`) is still caught by the index rather than the in-PHP pass — a worse operator experience,
  not a correctness hole. Recorded in `normaliseLabel()`'s docblock, the migration's docblock and §12.

## Proof

### The suite for this file

```
DB_DATABASE=portal_testing php vendor/bin/pest tests/Feature/Finance/OpeningBalanceImportTest.php
{"tool":"pest","result":"passed","tests":27,"passed":27,"assertions":150,"duration_ms":11770}
```

### `bin/quality` — 14 steps

```
quality gate — base 26e144b

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
```

`git diff --cached --name-only | grep -E 'phpstan-baseline|ratchet-baseline'` → **no match**. Neither
baseline grew, and neither needed to.

### Migration up → down → up, on `portal_testing`

Rollback depth was re-derived rather than assumed: `migrate:status` shows this migration alone in
batch `[2]`, so `--step=1` is this migration and nothing else.

```
2026_08_08_100000_realign_opening_balance_staging_for_per_fee_type_file [2] Ran
```

After `up()` (read from `information_schema`, not from the migration source):

```
rows cols: admission_number,balance_currency,balance_minor,batch_id,created_at,fee_type_label,findings,id,last_payment_date,line_number,school_id,status,student_id,student_total_balance_currency,student_total_balance_minor,updated_at,uuid,wcbs_bill_reference,wcbs_student_ref
batch cols: batch_reference,created_at,cutover_date,file_row_count,filename,findings,id,row_count,school_id,status,term_id,updated_at,uploaded_by_user_id,uuid
rows indexes: PRIMARY,finance_opening_balance_rows_uuid_unique,ob_rows_school_batch_admission_fee_type_unique,finance_opening_balance_rows_batch_school_foreign
checks: finance_opening_balance_rows.ob_rows_balance_currency_shape,finance_opening_balance_rows.ob_rows_student_total_balance_currency_shape
fee_type_label: {"c":"utf8mb4_unicode_ci","n":"NO"}
```

After `migrate:rollback --step=1` — **my** columns are gone and the old shape is back, asserted rather
than inferred from exit 0:

```
rows cols: admission_number,batch_id,created_at,expected_billed_currency,expected_billed_minor,findings,id,last_payment_date,line_number,paid_to_date_currency,paid_to_date_minor,prior_arrears_currency,prior_arrears_minor,school_id,status,student_id,updated_at,uuid,wcbs_bill_reference,wcbs_billed_total_currency,wcbs_billed_total_minor,wcbs_student_ref,wcbs_total_balance_currency,wcbs_total_balance_minor
batch cols: … total_paid_to_date_currency,total_paid_to_date_minor,total_prior_arrears_currency,total_prior_arrears_minor,total_wcbs_billed_currency,total_wcbs_billed_minor …
rows indexes: PRIMARY,finance_opening_balance_rows_uuid_unique,ob_rows_school_batch_admission_unique,finance_opening_balance_rows_batch_school_foreign
checks: (all eight retired CHECKs restored, batches ×3 + rows ×5)
fee_type_label: null
```

Re-`up()` → `DONE` in 206.86ms, shape as above.

## The watched red

Five mutations, each planted in the **production** code (never in the test), run, restored, and the
whole file re-run green afterwards. Output is what this environment's pest reporter prints; it carries
the assertion diff verbatim.

**1 — L1 disabled.** `l1Verdict()`: `if ($sum->equals($total))` → `if (true || $sum->equals($total))`.

```
{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":4,"failed":1,"failures":[{"test":"…it_accepts_a_student_whose_fee_type_balances_sum_to_their_stated_total__and_rejects_the_WHOLE_row_group_of_one_that_is_off_by_a_kobo__naming_both_sides","line":185,"message":"Failed asserting that two variables reference the same object.\n--- Expected\n+++ Actual\n@@ @@\n-App\\Finance\\Enums\\OpeningBalanceRowStatus Enum #5164 (Rejected, 'rejected')\n+App\\Finance\\Enums\\OpeningBalanceRowStatus Enum #5154 (Ok, 'ok')"}]}
```

Named the right thing: line 185 is the assertion that the *second* row of the failing student's group
is rejected — i.e. the group arm, not the arithmetic arm. Restored; green.

**2 — L2 disabled.** `if (! $statedSum->equals($controlTotal))` → `if (false && …)`.

```
{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":1,"failed":1,"failures":[{"test":"…it_raises_a_BATCH_finding_when_the_stated_totals_do_not_sum_to___control_total__and_rejects_NO_row","line":254,"message":"Failed asserting that an array contains 'control_total_mismatch'."}]}
```

Restored; green.

**3 — the label fold removed** (`normaliseLabel()` returns the raw string, i.e. byte comparison). This
is the most informative of the five, because it proves **both halves** of the duplicate-key claim in
one run: the in-PHP pass lets the case-variant through, and the database refuses it — 1062, on the new
four-column key by name.

```
{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":0,"errors":1,"error_details":[{"test":"…it_treats_a_fee_type_differing_only_in_case_as_ONE_key__the_second_line_is_reported_and_never_staged","message":"SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '1-1-ADM-CASE-tuition' for key 'finance_opening_balance_rows.ob_rows_school_batch_admission_fee_type_unique' (… insert into `finance_opening_balance_rows` (`batch_id`, `line_number`, `admission_number`, `wcbs_student_ref`, `fee_type_label`, `balance_minor`, …) values (1, 3, ADM-CASE, W1, tuition, 4500000, NGN, …))"}]}
```

Note the duplicate entry printed by MySQL: `'1-1-ADM-CASE-tuition'` — the engine folded `Tuition` and
`tuition` onto one key, which is the collation fact the ruling rests on, observed rather than argued.
Restored; green. (A second test asserts the same 1062 from a *direct* `OpeningBalanceRow::create()`
that bypasses `normaliseLabel()` entirely, so the index is proven independently of the command.)

**4 — `wcbs_bill_reference` back to `'required' => true`** in the `COLUMNS` map.

```
{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":1,"failed":1,"failures":[{"test":"…it_accepts_a_row_with_a_blank_wcbs__bill__reference__and_still_rejects_a_blank_REQUIRED_column_beside_it","line":364,"message":"Failed asserting that two variables reference the same object.\n--- Expected\n+++ Actual\n@@ @@\n-App\\Finance\\Enums\\OpeningBalanceRowStatus Enum #5303 (Ok, 'ok')\n+App\\Finance\\Enums\\OpeningBalanceRowStatus Enum #5288 (Rejected, 'rejected')"}]}
```

This is the behaviour-change proof the brief asked for: the row that must now be accepted is exactly
the row the old rule rejected. Restored; green.

**5 — the retired non-negative rule re-planted** (a `negative_amount` finding on a negative `balance`).

```
{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":1,"failed":1,"failures":[{"test":"…it_accepts_a_NEGATIVE_balance_as_a_credit__and_nets_it_into_the_student_s_stated_total","line":428,"message":"Failed asserting that two variables reference the same object.\n--- Expected\n+++ Actual\n@@ @@\n-App\\Finance\\Enums\\OpeningBalanceRowStatus Enum #5303 (Ok, 'ok')\n+App\\Finance\\Enums\\OpeningBalanceRowStatus Enum #5107 (Rejected, 'rejected')"}]}
```

The rule rejects the student in credit, which is precisely why it retired. Restored; `git diff` after
restoration shows no mutation residue and the full file is green at 27/27.

## The watched red — second pass (the catch-and-convert fix)

Three mutations, because the fix has two layers and a single red could not tell them apart.

**A — the catch's unique arm neutered** (`catch (UniqueConstraintViolationException $mutation) { throw $mutation; }`).
The accent test aborts, and the abort is exactly the failure the review reported:

```text
{"tool":"pest","result":"failed","errors":1,"error_details":[{"test":"…it_converts_an_engine_refused_duplicate_into_the_same_finding_and_CONTINUES_the_run","message":
"SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '1-1-ADM-ACCENT-Tuicion' for key
'finance_opening_balance_rows.ob_rows_school_batch_admission_fee_type_unique' (… insert into
`finance_opening_balance_rows` (… `fee_type_label`, …) values (1, 3, ADM-ACCENT, W1, Tuicion, 5000000, NGN, …))"}]}
```

Restored; green. Note the key MySQL prints — `'1-1-ADM-ACCENT-Tuicion'` collided with the already-staged
`Tuición`, which is the accent folding observed rather than argued.

**B — the `max` rule disabled, catch left in place.** The over-length test **still passes**: the
backstop caught the 1406 and produced the same `value_too_long` finding and the same accounting.

```text
{"tool":"pest","result":"passed","tests":1,"passed":1,"assertions":10,"duration_ms":10994}
```

**C — the `max` rule disabled AND the 1406 arm neutered** (`if (true || … !== 1406) { throw $e; }`).
Now it aborts, which is what proves arm B's green was the catch and not an accident:

```text
{"tool":"pest","result":"failed","errors":1,"error_details":[{"test":"…it_drops_an_over_length_value__names_the_column__and_CONTINUES_the_run","message":
"SQLSTATE[22001]: String data, right truncated: 1406 Data too long for column 'fee_type_label' at row 1 (…)"}]}
```

Both restored (`grep` confirms `if (mb_strlen(trim(...)) > $spec['max'])` and
`if ((int) ($e->errorInfo[1] ?? 0) !== 1406)` are back); the two files run 36/36, 202 assertions.

## Database observations

Privacy rule applied: ids and counts only.

- `portaa10_portal` (the production copy) held **0** batches and **0** rows before the migration, so
  the delete in `up()` was a no-op there. It is written for environments where it may not be.
- Migration ran on `portaa10_portal` (`DONE`, 214.58ms) and on `portal_testing`.
- **The command was driven for real** against `school#1` (611 students) on the production copy, in
  `--dry-run`, with a three-line synthetic CSV: two students, one with two fee types, one wholly in
  credit. L1 passed for both; L2 matched exactly (Σ stated = control total, Δ 0); all three rows
  rejected as `student_not_found`, which is correct for synthetic admission numbers. The scratch batch
  was then **deleted** (`ZZ-DRIVE-A`); its 3 rows went with it by CASCADE, and both staging tables are
  back to 0/0. Nothing else in that database was touched.
- **A privacy slip I made, reported rather than hidden:** the operator report prints the "students in
  School absent from the file" list, and driving it against a real school with a synthetic file made
  that list 611 entries long — real admission numbers were printed to my terminal before I filtered
  them. Nothing was written anywhere, and none appear in this report or in any committed file, but the
  next person driving this command against the copy should redirect stdout to a file rather than read
  it. Arguably the command should cap or elide that list under a flag; noted below as a ticket.

## Not done

- **`batches.term_id` is untouched, and §9's OPEN decision stays open.** R5 says there is no cutover
  term; the column is NOT NULL with an FK and `--term` is still required and still validated. Either
  answer changes a migration and 4a is the file format, so I left it as it shipped rather than
  half-changing it. `resolveTerm()`'s docblock now says so explicitly. **4b or 4c must close it.**
- ~~**`rows.last_payment_date` survives, unwritten.**~~ **Done in the second pass** — retired in the
  same 4a migration, on the same reasoning that retires the four withdrawn pairs. The migration had
  only ever run on the two local databases and was unpushed, so it was rolled back on both, edited,
  and re-applied rather than chased with a second ALTER; the up → down → up audit was re-run
  afterwards and `last_payment_date` is absent after `up()` and restored by `down()`.
- **The operator's control total is not persisted anywhere.** It arrives as `--control-total`, is
  checked, and is reported; on a *failed* L2 both figures land in the batch's `findings` JSON, but on
  a **passing** L2 nothing records what the operator attested to. The brief's migration list retires
  the three batch `total_*` pairs and adds nothing, so I added nothing — but 4c's approval screen will
  want the attested figure, and adding it later is another ALTER. Flagged as a decision, not fixed.
- **`student_soft_deleted` is not implemented.** §9 assigns the `student_not_found` split to "commit
  4" and it needs a second port method (the trashed roster); the brief's numbered scope does not
  include it, and reaching for `withTrashed()` inside Finance is now lint-forbidden. Left for 4b/4c.
- **The template export (R13/U12b) is not built** — it is step 5. The `COLUMNS` map is pinned by a
  test that freezes the six columns, the single optional one, and the presence of every template field
  (`notes`, `example`, …), so the map cannot silently lose what the template will need.
- **No approval gate, no permissions, no G1/G1b, no posting** — 4b and 4c, as scoped.

## The review's five findings — disposition

| # | Disposition |
|---|---|
| 1 — engine-refused duplicate orphans the batch | **FIXED.** Catch-and-convert, counted, three watched reds. My "not a correctness hole" claim was wrong and is corrected in all four places. |
| 2 — no length rule, 1406 aborts identically | **FIXED as the same defect**, not deferred: closing only 1062 would have left the hole open under another error number. `max` in `COLUMNS` + the 1406 catch arm. |
| 3 — the two new currency CHECKs unpinned | **TAKEN.** Path 4 added to `CurrencyShapeConstraintTest` — both columns, `'ngn'` → 3819, `'NGN'` inserts. It is the first case there on a table with no immutability trigger, so the CHECK is the only door. |
| 4 — `term_id` still open | **DEFERRED, on the merits, and now recorded in §9** rather than left looking overlooked. Both options turn on what a batch's term is *for*, which nothing answers until posting exists; 4b is the first commit with a caller. |
| 5 — `last_payment_date` written by nothing | **FIXED.** Retired in the same migration. |

One correction to my own first-pass report, which the reviewer caught: the absent-students list prints
**50** entries, not 611 — `printList` caps at `LIST_LIMIT` with an announced truncation. The count was
611; the print was 50. The ticket below is sized to the print.

## Findings raised, not fixed

- `app/Finance/Console/ImportOpeningBalances.php` `report()` — the "students absent from the file" list
  prints up to 50 real admission numbers to the console. Correct for an operator holding the file,
  hazardous for anyone driving the command in a shared terminal or pasting output. Suggest a
  `--quiet-identifiers` flag or capping it to a count with the ids only in the staged data. **ticket**
- `app/Finance/Contracts/BillableEnrollment.php:46-47` — `termId` / `classLevelId` now have **no
  production caller** in the repository; the only thing exercising them is the port test at the bottom
  of `OpeningBalanceImportTest.php`, kept deliberately. §5 predicted this ("front-loading biting in
  reverse"). Not wrong, merely early — bulk billing will consume them. **ticket**
- `app/Finance/Services/FeeScheduleLookup.php` is no longer used by this command; it retains callers
  (`FeeScheduleController@prefill`, two test files), so nothing is orphaned. No action. **none**

---

## Appendix A — the migration, verbatim

See `database/migrations/2026_08_08_100000_realign_opening_balance_staging_for_per_fee_type_file.php`
in this commit. The load-bearing body:

```php
public function up(): void
{
    // Rows first, then batches — the CASCADE would take the rows anyway, but an explicit delete
    // says what is happening rather than leaving it to a constraint the reader has to look up.
    DB::table('finance_opening_balance_rows')->delete();
    DB::table('finance_opening_balance_batches')->delete();

    foreach (self::RETIRED_CHECKS as [$table, $name]) {
        $this->dropCheckIfPresent($table, $name);
    }

    Schema::table('finance_opening_balance_rows', function (Blueprint $table) {
        // NOT NULL for an ingested row: every line of R5's file names its fee type, and a line
        // that does not is an extract defect the validator rejects rather than stages as absent.
        $table->string('fee_type_label')->after('wcbs_student_ref');

        // SIGNED. Positive is owed, negative is credit — R8 reads the instrument off the sign.
        // Nullable for the same reason every other staged amount is: §2's "blank ≠ zero" means a
        // blank or unparseable cell stages as ABSENT and is rejected with a named finding, never
        // coerced to a zero the file did not state.
        $table->bigInteger('balance_minor')->nullable()->after('fee_type_label');
        $table->char('balance_currency', 3)->nullable()->after('balance_minor');

        // L1's per-row witness (§1): the student's STATED total, repeated identically on every
        // row of that student's group. Nullable for the same "blank ≠ zero" reason.
        $table->bigInteger('student_total_balance_minor')->nullable()->after('balance_currency');
        $table->char('student_total_balance_currency', 3)->nullable()->after('student_total_balance_minor');
    });

    // THE NEW KEY GOES ON BEFORE THE OLD ONE COMES OFF, and the order is not cosmetic: the
    // school_id FK (finance_opening_balance_rows_school_id_foreign) is satisfied by whichever
    // index has school_id leftmost, and on this table that was the old unique. Dropping it first
    // is refused 1553 "needed in a foreign key constraint" (watched, not assumed). The new key
    // has the same leading column, so once it exists the old one is free to go.
    Schema::table('finance_opening_balance_rows', function (Blueprint $table) {
        $table->unique(['school_id', 'batch_id', 'admission_number', 'fee_type_label'], self::NEW_KEY);
    });

    Schema::table('finance_opening_balance_rows', function (Blueprint $table) {
        $table->dropUnique(self::OLD_KEY);
        $table->dropColumn(self::RETIRED_COLUMNS['finance_opening_balance_rows']);
    });

    Schema::table('finance_opening_balance_batches', function (Blueprint $table) {
        $table->dropColumn(self::RETIRED_COLUMNS['finance_opening_balance_batches']);
    });

    foreach (self::NEW_CHECKS as [$table, $column, $name]) {
        DB::statement(
            "ALTER TABLE {$table} ADD CONSTRAINT {$name}
             CHECK ({$column} IS NULL OR {$column} COLLATE utf8mb4_bin REGEXP '^[A-Z]{3}\$')"
        );
    }
}
```

## Appendix B — the `COLUMNS` map, verbatim

```php
public const COLUMNS = [
    // Linking
    'admission_number' => [
        'required' => true,
        'format' => 'string',
        'example' => 'STU2025001',
        'notes' => 'The join key. Must already exist in this School — a student is NEVER created from a finance import.',
        'group' => 'Linking',
    ],
    'wcbs_student_ref' => [
        'required' => true,
        'format' => 'string',
        'example' => 'WCBS-10233',
        'notes' => "WCBS's own id, stored for traceability. Never used to join.",
        'group' => 'Linking',
    ],
    'fee_type_label' => [
        'required' => true,
        'format' => 'string',
        'example' => 'Tuition',
        'notes' => 'The fee type as WCBS names it, carried verbatim onto the statement. One row per student PER FEE TYPE. Spelling is matched case-insensitively, so "Tuition" and "tuition" are the same fee type and two rows for it are refused.',
        'group' => 'Amounts',
    ],
    'balance' => [
        'required' => true,
        'format' => 'naira with two decimal places, SIGNED (120000.00 / -5000.00)',
        'example' => '120000.00',
        'notes' => 'That fee type\'s closing balance for that student. POSITIVE is owed, NEGATIVE is credit. Blank is not zero — write 0.00 if the balance really is nil.',
        'group' => 'Amounts',
    ],
    'student_total_balance' => [
        'required' => true,
        'format' => 'naira with two decimal places, SIGNED',
        'example' => '145000.00',
        'notes' => "The student's total across ALL their fee types. Write the SAME figure on every one of that student's rows — it is the independent check that no line of theirs went missing.",
        'group' => 'Amounts',
    ],
    'wcbs_bill_reference' => [
        'required' => false,
        'format' => 'string',
        'example' => 'BILL-2026-0912',
        'notes' => 'OPTIONAL. The reference on the last paper bill, if WCBS carries one. A blank here does NOT reject the row.',
        'group' => 'Provenance',
    ],
];
```
