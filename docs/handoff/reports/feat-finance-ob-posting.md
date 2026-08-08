# Implementation report — §9 step 4b: the posting Action, `posted`, G1 and G1b

Branch `feat/finance-ob-posting`, off `origin/staging` @ `2d55fda`. PR **#216** → `staging`, not merged.

Two passes are recorded here. Pass 1 built 4b. Pass 2 closed three findings a review
raised against pass 1. Both are described; nothing from pass 1 is silently overwritten.

---

## A process correction, applied from this report onwards

The pass-1 version of this file nominated one of its own changes as "the change to
argue about", pre-assigned severities to items it raised against itself, and referred
to review findings the reader did not have. A report that does any of those steers its
reviewer, and a steered review is worth less than an unsteered one — as it happened,
every finding the review returned came from outside the areas the report had
nominated.

From here this report carries facts, evidence, deviations, and what could not be
verified. No severity calls on my own work. No nomination of what is contentious. No
references to material the reader does not have in front of them. The correction is
recorded here rather than only acted on, so the next reader can check whether it held.

## Deviations from the brief

**Pass 1.**

1. **G1's column and index are named as §9 spells them** — `posted_school_key` and
   `ob_batches_posted_school_unique` — not `posted_key` / `UNIQUE (posted_key)` as the
   task wrote them. The shape is otherwise as specified: STORED generated column,
   `IF(status = 'posted', school_id, NULL)`, unique on the generated column alone.
2. **G1b shipped as two triggers, not one.** §9 specifies `BEFORE UPDATE`. `DELETE`
   frees the same generated key, and additionally CASCADEs the staged rows away
   (`finance_opening_balance_rows_batch_school_foreign`), so
   `finance_opening_balance_batches_no_delete_posted` ships alongside
   `..._no_unpost`. The general rule claimed, stated as a rule so it can be checked:
   *a guard on a state's exit must cover every statement that can remove the row, not
   only the statement that can rewrite it.* Pass 2 found this rule was applied one
   table too shallow — see finding 1 below.
3. **`AuditLedgerCoherence` was changed, and the task did not name it.** Its I2
   assertion holds a closed vocabulary of `source_type` values;
   `opening_balance_row` was absent, so the first school to post would have produced
   one I2 finding per opening charge on a ledger whose balances are correct. Added to
   `SOURCE_TABLES` (which also gives I2's dangling-reference check a table to look in)
   and to I7's currency map.
4. **§4's open `external_reference` question was ruled**, which the task did not ask
   for; §4 requires commit 4 to rule it. Ruling: no unique index, duplicates legal.
   §4's stated risk — two batches under different batch references posting the same
   WCBS receipt — is closed by G1 + G1b, and a unique index would additionally abort a
   cutover on an extract that repeats a bill reference across two students.
5. **Three shapes the task left open, chosen:** `received_by_user_id` is NULL on a
   migrated payment (nobody in this system received the cash; who posted is on the
   batch); `external_reference` is the first non-null `wcbs_bill_reference` among that
   student's credit rows (netting is one row against possibly several references, and
   the full set stays on the staged rows); `payer_name` snapshots the batch reference.
6. **A stated-zero balance posts nothing.** §3 assigns an instrument to positive and to
   negative. The zero stays on the staged row.
7. **Pushed and opened the PR.** The `finance-execute` skill says the implementing hand
   never pushes; the task file overrides it explicitly. Not merged.

**Pass 2.**

8. **The batch reference was removed from the credit ledger narration.** It read
   `"Payment #<ref> — Balance Brought Forward (WCBS batch <batch_reference>)"`. That
   string embeds operator input behind 49 fixed characters against `payer_name`'s 37,
   so it — not `payer_name` — would have been the binding constraint on the batch
   reference limit, and the limit would have had to be the minimum of two arithmetics.
   The narration is now `"Payment #<ref> — Balance Brought Forward"`, whose parts are
   all bounded by the Action, so one limit covers the reference. The batch reference is
   still on the payment row, in `payer_name`. **This is why the task's stated 218 is
   the correct number rather than a number that would still have overflowed.**
9. **The `fee_type_label` limit test at `OpeningBalanceImportTest` was re-pointed, not
   deleted.** It asserted every column's `max` equals its own storage width. That is
   still asserted for `admission_number`, `wcbs_student_ref` and
   `wcbs_bill_reference`. `fee_type_label` now asserts two things instead: that it
   still fits its own column, **and** that it equals the narration column's width minus
   the suffix. Strictly more than it asserted before.

## Contradictions of the premise

Pass 1: none material. Every file, line number and behaviour the task cited
reproduced — `GenerateInvoice.php:386-428` is `applyCreditForward` and sources
allocations from `Payment` rows ordered by `id`; `Payment.php:57` is
`MIGRATED_REFERENCE_FLOOR`; `OpeningBalanceBatchStatus` had three cases;
`finance_opening_balance_batches` carried no trigger and `status` no CHECK.

Pass 2: one number in the task did not survive contact with the code as written.
The task derives the batch-reference limit as 218 = 255 − 37 (`payer_name`). At the
time it was written the credit narration also embedded the batch reference behind 49
fixed characters, making 197 the true limit. Rather than take the minimum of two
arithmetics, the reference was removed from that narration (deviation 8), which makes
218 correct. Stated because the alternative — silently using 218 against the code as
it stood — would have left a 218-character reference aborting the post at 1406 on
`narration` instead of `payer_name`.

**MySQL fact found by watching a guard bite, carried forward for any future trigger:**
`MESSAGE_TEXT` is a `varchar(128)`. A longer literal makes the `SIGNAL` itself fail
with driver code **1648** instead of **1644**. The write is still refused, so the guard
looks correct; only an assertion on the driver code distinguishes them.

## What changed

Pass 1 (`a33d369`):

| File | What |
|---|---|
| `database/migrations/2026_08_08_110000_opening_balance_posting_state_and_guards.php` (new) | `posted_at`, `posted_by_user_id`; `posted_school_key` generated column + unique index (G1); two G1b triggers; the `term_id` ruling as a column COMMENT |
| `app/Finance/Actions/PostOpeningBalanceBatch.php` (new) | The posting Action — §3 steps 1/2/3, the reserved band, the transition |
| `app/Finance/Enums/OpeningBalanceBatchStatus.php` | `Posted` added; `approved` absent (4c) |
| `app/Finance/Models/OpeningBalanceBatch.php` | `posted_at` cast, two properties, the `term_id` meaning |
| `app/Finance/Console/ImportOpeningBalances.php` | `--term` → `--closing-term`; refusal names 4c; `resolveTerm()` records the ruling |
| `app/Finance/Console/AuditLedgerCoherence.php` | I2/I7 learn `opening_balance_row` |
| `tests/Feature/Finance/OpeningBalancePostingTest.php` (new) | The five proofs plus supporting cases |
| `tests/Feature/Finance/OpeningBalanceImportTest.php` | Option rename; refusal assertion pins "the approval gate is §9 step 4c" |
| `docs/handoff/opening-balance-import-spec.md` | §4 `external_reference` ruled; §9 build order split, `term_id` ruled, G1b's second door; §11's G1 claim |

Pass 2:

| File | What |
|---|---|
| `database/migrations/2026_08_08_120000_opening_balance_posted_rows_are_terminal.php` (new) | Two triggers on `finance_opening_balance_rows` (UPDATE/DELETE denied while the parent batch is posted); `..._no_unpost` dropped and recreated with the `school_id` clause |
| `database/migrations/2026_08_08_100000_...php` | **Docblock only** — the three superseded claims struck in place with what changed and when |
| `app/Finance/Actions/PostOpeningBalanceBatch.php` | Four public constants (`SNAPSHOT_COLUMN_MAX`, `NARRATION_SUFFIX`, `PAYER_NAME_PREFIX`, `PAYER_NAME_SUFFIX`); credit narration no longer carries the batch reference |
| `app/Finance/Console/ImportOpeningBalances.php` | `fee_type_label` `max` 255 → 229; `BATCH_REFERENCE_MAX = 218` and its refusal before the batch insert |
| `tests/Feature/Finance/OpeningBalancePostingTest.php` | PROOF 6/6b (row guards), 7 (`school_id` door), 8/8b/8c (derived limits and both mirrors), the fail-closed context case |
| `tests/Feature/Finance/OpeningBalanceImportTest.php` | Both boundary pairs; the `fee_type_label` limit assertion re-pointed |

**Pass 2 edited no applied migration's executing half.** `2026_08_08_100000`'s `up()`
and `down()` are byte-identical to what ran; only its docblock is amended, and the
amendment says so. `2026_08_08_110000` was not edited at all — its `no_unpost` trigger
is superseded by a DROP-and-CREATE in a new migration, which is why `120000` exists
instead of a one-line change to `110000`.

**Untouched throughout:** `phpstan-baseline.neon`, `tests/ratchet-baseline.txt`. **No
Permission case, no `Submit*.php`, no `grantsMap()` edit** — the two lints that make 4c
indivisible are not approached.

## The `term_id` ruling

**Option 2 — REPURPOSE. `term_id` names the term being CLOSED OUT.** It keeps
`NOT NULL` and its FK; only the meaning changes.

R5 puts the cutover on a term boundary, so there is no cutover term **T** — that is
what made the column look wrong. The file is the closing position of a *specific*
term, and recording which term is the provenance that lets a reader a year later say
what period the opening charges represent. Nullifying discards that, and takes the FK's
referential guarantee and the option's per-School validation with it. Repurposing costs
a comment and a rename.

The task invited a counter-argument if the code disagreed. It did not: `resolveTerm()`
already validated the term against the School (`ImportOpeningBalances.php`), and no
code anywhere read `batches.term_id` for a cutover-term meaning, so no caller needed
un-teaching. The rename is the whole cost.

Carried through: `--closing-term` with a corrected description; `resolveTerm()`'s
docblock and error message; `OpeningBalanceBatch`'s docblock; §9's record; and a MySQL
`COMMENT` on the column, which is what a reader of `SHOW CREATE TABLE` sees. The
comment is not a constraint and is not presented as one — nothing at the engine stops a
caller writing the wrong term, and "this is last term, not this term" is a claim about
meaning, which §11 classes as procedural.

## The derived-limit arithmetic

Two operator-supplied strings reach a `varchar(255)` snapshot column at posting.
Nothing is truncated — R7 carries the fee-type label VERBATIM onto a parent's
statement — so both refusals live at the validator.

```
finance_ledger_transactions.narration   varchar(255)   (read from information_schema)
finance_payments.payer_name             varchar(255)   (read from information_schema)

PostOpeningBalanceBatch::NARRATION_SUFFIX    = ' — Balance Brought Forward'          26 chars
PostOpeningBalanceBatch::PAYER_NAME_PREFIX   = 'Balance brought forward (WCBS batch ' 36 chars
PostOpeningBalanceBatch::PAYER_NAME_SUFFIX   = ')'                                    1 char

narration  = <fee_type_label> . NARRATION_SUFFIX
             → COLUMNS['fee_type_label']['max'] = 255 − 26  = 229

payer_name = PAYER_NAME_PREFIX . <batch_reference> . PAYER_NAME_SUFFIX
             → BATCH_REFERENCE_MAX             = 255 − 37  = 218

credit narration = 'Payment #' . <reference> . NARRATION_SUFFIX
             → no operator input; bounded by the Action. This is why there is one
               batch-reference limit rather than min(218, 197). See deviation 8.
```

A PHP const expression cannot call `mb_strlen`, so both limits are literals with the
arithmetic written beside them, and `PROOF 8` asserts the two agree — including reading
both column widths from `information_schema` rather than from a migration's source.
Editing an affix without moving its limit fails there.

## Proof

### Migration reversibility — re-derived per run

`migrate:status | tail` put `120000` last, so `--step=1` is mine; confirmed by name in
the rollback output, then asserted against `information_schema` rather than exit 0.

```
 2026_08_08_110000_opening_balance_posting_state_and_guards .. [1] Ran
 2026_08_08_120000_opening_balance_posted_rows_are_terminal .. [1] Ran

 2026_08_08_120000_opening_balance_posted_rows_are_terminal .. 33.87ms DONE

=== after rollback ===
finance_opening_balance_rows :: (no triggers)
finance_opening_balance_batches :: finance_opening_balance_batches_no_unpost :: no school_id clause
finance_opening_balance_batches :: finance_opening_balance_batches_no_delete_posted :: no school_id clause
```

The rollback restores `110000`'s narrower trigger rather than leaving the table
unguarded. Re-up:

```
 2026_08_08_120000_opening_balance_posted_rows_are_terminal .. 27.37ms DONE

finance_opening_balance_rows :: finance_opening_balance_rows_no_update_when_posted :: BEFORE UPDATE :: no school_id clause
finance_opening_balance_rows :: finance_opening_balance_rows_no_delete_when_posted :: BEFORE DELETE :: no school_id clause
finance_opening_balance_batches :: finance_opening_balance_batches_no_unpost :: BEFORE UPDATE :: HAS school_id clause
finance_opening_balance_batches :: finance_opening_balance_batches_no_delete_posted :: BEFORE DELETE :: no school_id clause
```

Pass 1's equivalent (`110000` rolled back and re-upped, generated column and index read
back with `NON_UNIQUE: 0` on `posted_school_key` alone) is unchanged and was recorded
at the time:

```
[{"COLUMN_NAME":"posted_school_key","IS_NULLABLE":"YES","EXTRA":"STORED GENERATED",
  "GENERATION_EXPRESSION":"if((`status` = _utf8mb4\\'posted\\'),`school_id`,NULL)","COLUMN_COMMENT":""}]
[{"INDEX_NAME":"ob_batches_posted_school_unique","NON_UNIQUE":0,"COLUMN_NAME":"posted_school_key"}]
[{"COLUMN_NAME":"term_id","IS_NULLABLE":"NO","COLUMN_COMMENT":"The term being CLOSED OUT: the last term, whose closing position this file carries (spec §9, ruled in step 4b). NOT a cutover term T — R5 puts the cutover on a term boundary."}]
```

### Suites

`OpeningBalancePostingTest` (20 tests) alone:

```
{"tool":"pest","result":"passed","tests":20,"passed":20,"assertions":96,"duration_ms":11754}
```

`OpeningBalancePostingTest` + `OpeningBalanceImportTest` + `LedgerCoherenceTest` +
`SchemaConventionsTest` + `TriggerBodiesAreDumpSafeTest` + `PaymentProvenanceTest`:

```
{"tool":"pest","result":"passed","tests":87,"passed":87,"assertions":445,"duration_ms":20734}
```

### bin/quality

Run on pass 2's tree, exit 0:

```
quality gate — base 2d55fda

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

Re-derived at the time of writing: 14 steps, boundary-lint at 7, grants-convergence at
8, the suite at 14. Neither baseline file was touched, and neither lint reports a new
entry.

### 4a's executing half, proved unchanged rather than asserted

```
$ git show origin/staging:…2026_08_08_100000….php | sed -n '/^return new class/,$p' > old
$ sed -n '/^return new class/,$p' …2026_08_08_100000….php                                > new
$ diff old new && echo "4a EXECUTING HALF: byte-identical to origin/staging"
4a EXECUTING HALF: byte-identical to origin/staging
```

The whole diff on that file is inside the docblock. ADR 0052's corollary bites on the
executing half; the comment is amended, `up()` and `down()` are not.

## The watched red

Pass 2's six, each planted, run, restored, and the restore confirmed by `diff` against
a pre-mutation copy.

**RED A — the row UPDATE guard.** Trigger condition prefixed `1 = 0 AND`.

```
{"tool":"pest","result":"failed","tests":2,"passed":1,"assertions":3,"failed":1,
 "failures":[{"test":"PROOF 6 — G1b at the ROW level: UPDATE and DELETE of a posted batch staged row are both refused (1644)",
   "line":486,"message":"Failed asserting that 0 is identical to 1644."}]}
```

Line 486 is the `updateCode` assertion. Observed 0 — the `UPDATE` of a posted batch's
staged row succeeded.

**RED B — the row DELETE guard.** Same mutation on the other trigger.

```
{"tool":"pest","result":"failed","tests":2,"passed":1,"assertions":4,"failed":1,
 "failures":[{"test":"PROOF 6 — G1b at the ROW level: …",
   "line":487,"message":"Failed asserting that 0 is identical to 1644."}]}
```

Line 487 is `deleteCode` — a different assertion from RED A, so the two doors are
attributed separately.

**RED C — the `school_id` door.** `..._no_unpost` reverted to `110000`'s condition
(`OLD.status = 'posted' AND NEW.status <> 'posted'`).

```
{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":2,"failed":1,
 "failures":[{"test":"PROOF 7 — G1b: moving a posted batch to another School is refused (1644), on a batch with NO staged rows",
   "line":531,"message":"Failed asserting that 0 is identical to 1644."}]}
```

Observed 0 on a **zero-row** posted batch: the `school_id` moved and the generated key
moved with it. The FK that blocks the row-carrying case is not involved in this case at
all, which is why the proof uses a batch with no rows.

**RED D — the label limit.** `COLUMNS['fee_type_label']['max']` returned to 255.

```
{"tool":"pest","result":"failed","tests":5,"passed":2,"assertions":12,"failed":2,
 "failures":[{"test":"PROOF 8 — the validator limits are DERIVED from the strings this Action builds, not remembered",
   "message":"Failed asserting that 255 is identical to 229."},
  {"test":"…accepts a label at exactly the limit and REFUSES one character more — the boundary pair",
   "message":"Failed asserting that 255 is identical to 229."}],
 "errors":1,"error_details":[{"test":"PROOF 8b — the mirror at the posting end …",
   "message":"SQLSTATE[22001]: String data, right truncated: 1406 Data too long for column 'narration' at row 1 (… insert into `finance_ledger_transactions` … XXXX…XXXX — Balance Brought Forward …)"}]}
```

The 1406 in the third entry is the defect itself, reproduced: a label the validator
declared valid aborting the post at the engine.

**RED E — the batch-reference limit.** `BATCH_REFERENCE_MAX` returned to 255.

```
{"tool":"pest","result":"failed","tests":3,"passed":1,"assertions":4,"failed":1,
 "failures":[{"test":"PROOF 8 — the validator limits are DERIVED …",
   "line":547,"message":"Failed asserting that 255 is identical to 218."}],
 "errors":1,"error_details":[{"test":"PROOF 8c — the mirror for payer_name …",
   "message":"SQLSTATE[22001]: String data, right truncated: 1406 Data too long for column 'filename' at row 1 …"}]}
```

Line 547 is the load-bearing assertion. The 1406 in the second entry is on `filename`,
not `payer_name` — an artefact of the test helper deriving a filename from the
reference under the mutation, not the guard under test. Stated so it is not read as
evidence it is not.

**RED F — the fail-closed context guard.** `ActiveSchool::id()` null branch replaced
with a fallback to the batch's own `school_id`.

```
{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":2,"failed":1,
 "failures":[{"test":"refuses to post with NO active School context — the fail-closed branch, exercised",
   "line":617,"message":"Exception \"App\\Exceptions\\BusinessRuleException\" not thrown."}]}
```

Pass 1's reds (G1's unique index, G1b's UPDATE and DELETE doors, the reserved band
drawn through `Sequences`, the charge's narration and `source_type`, and the credit
proved from both sides with a temporary probe) are unchanged and were recorded at the
time. The credit's two-sided proof, restated because it is the one the task said to
stop on: under a bare-ledger-credit mutation a probe asserting "balance correct,
invoice fully outstanding" **passed**; on the restored code the same probe **failed**
at `Failed asserting that 500000 is identical to 0`. The probe was deleted.

## Database observations

Under the privacy rule — structure and counts only.

- Local test database `portal_testing` only. The production copy was not touched,
  migrated or read in either pass.
- `finance_opening_balance_batches`: `+posted_at`, `+posted_by_user_id`,
  `+posted_school_key` (STORED GENERATED), `+1` unique index, `+2` triggers. It carried
  0 triggers before this branch.
- `finance_opening_balance_rows`: `+2` triggers (pass 2). It carried 0 before, and its
  columns are unchanged.
- `finance_payments`, `finance_ledger_transactions`: no schema change in either pass.
- No school in any environment holds a posted batch; the state did not exist before
  this branch.

## Not done

- **The approval gate (4c) is not built.** There is no production caller of the Action;
  it is reachable from tests only. "Posting is not reachable" and "posting is ungated"
  are different statements, and the first is the true one.
- **Concurrency has no test.** Two simultaneous posts for one school cannot both commit
  (G1's unique index), and the reference allocation's read-then-write is unlocked with
  `UNIQUE (school_id, reference)` as the backstop. Both are argued in the Action's
  docblock; neither is proved. No case in the shape of `InvoiceConcurrencyTest` or
  `WalletW3ConcurrencyTest` was written.
- **The row triggers' cost under load is not measured.** Each is a `SELECT` on the
  parent inside `FOR EACH ROW`, paid on UPDATE and DELETE of a staged row only —
  neither is an INSERT trigger, and validation only INSERTs. That reasoning is stated
  in the migration; it is not a benchmark.
- **§12 decision 1 (does the migrated payment need date D on its own surface?) is left
  open.** No column was added; the payment inherits D from the batch by provenance.
- **`--closing-term` is a breaking CLI rename**, and `--batch-reference` now has a
  length limit it did not have. Nothing in this repository calls the command with
  `--term`; operator runbooks held elsewhere will fail loudly.
- **`bin/quality`'s output below was produced by running it; the pass-1 rollback
  transcript above was recorded during pass 1 and not re-run in pass 2.**

## Findings raised, not fixed

- `app/Finance/Console/AuditLedgerCoherence.php` — `SOURCE_TABLES` is a closed
  vocabulary with nothing pinning it to the Actions that write `source_type`. A new
  posting instrument that forgets an entry turns the auditor red on a correct ledger,
  and nothing fails until someone runs it. A test enumerating the distinct
  `source_type` values the Actions write and asserting each is in the map would close
  it.
- `MESSAGE_TEXT` is `varchar(128)` and a longer literal downgrades a refusal from 1644
  to 1648 silently. `TriggerBodiesAreDumpSafeTest` already walks every trigger body for
  balanced quotes; a length assertion in the same test would cover every future
  trigger.
- `finance_opening_balance_batches` has no CHECK tying `posted_at` /
  `posted_by_user_id` to `status = 'posted'`. The only writer sets all three in one
  statement inside one transaction, so they cannot disagree today.
- `finance_opening_balance_rows_batch_school_foreign` is `NO ACTION` on update. The
  `school_id` trigger no longer depends on that, but the FK's update rule is still
  undocumented anywhere except the `120000` migration's docblock.
