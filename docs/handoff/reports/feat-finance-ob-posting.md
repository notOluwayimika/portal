# Implementation report — §9 step 4b, the posting Action, `posted`, G1 and G1b

Branch `feat/finance-ob-posting` @ `a33d369`, off `origin/staging` @ `2d55fda`.
PR **#216** → `staging`. Not merged.

**This is full-review tier** — it touches money, a migration, database triggers, an
append-only ledger and a `school_id`-scoped write path. The `finance-reviewer`
subagent's findings are attached separately; a cold session started from this file
is still recommended before merge.

---

## Headline

Done, with deviations. The `posted` state, G1 (one posted batch per school, at the
database) and G1b (the state is terminal) ship with
`App\Finance\Actions\PostOpeningBalanceBatch`; §9's `term_id` fork is ruled
**repurpose, not nullify**; the command still cannot post and now names 4c when it
refuses. Five watched reds plus three extra, all pasted raw below.

## Deviations from the brief

**1 — G1's column and index are named as §9 spells them, not as the task did.**
The task said `posted_key` / `UNIQUE (posted_key)`; §9's own SQL block says
`posted_school_key` / `ob_batches_posted_school_unique`. I followed §9, because the
spec is the artifact a future reader opens and a name that disagrees with it is a
small permanent papercut. Everything else about the shape is as specified: STORED
generated column, `IF(status = 'posted', school_id, NULL)`, unique on the generated
column **alone**.

**2 — G1b ships as TWO triggers, not one. This is an addition and it is the most
important thing in this report.** §9 specifies `BEFORE UPDATE` only. An UPDATE guard
alone leaves the identical release reachable through the other door:

```sql
DELETE FROM finance_opening_balance_batches WHERE id = <the posted batch>;
```

That frees the generated unique key just as completely, and it is worse than the
UPDATE: `finance_opening_balance_rows_batch_school_foreign` is `ON DELETE CASCADE`
(`2026_08_06_100000:156-161`), so the staged rows go too — and the posted ledger
charges, which can never be deleted (`2026_07_19_100001:56-60`), are left pointing at
`source_id`s that no longer exist. A second batch then posts and the arrears are
double-counted permanently: the exact Rev 3 outcome G1b exists to close.

So `finance_opening_balance_batches_no_unpost` (BEFORE UPDATE) **and**
`finance_opening_balance_batches_no_delete_posted` (BEFORE DELETE) both ship, both
bite-proved. **The general rule I am claiming, stated as a rule so it can be
checked: a guard on a state's exit must cover every statement that can remove the
row, not only the statement that can rewrite it.** That is the same lesson §9
already records about `withTrashed()` — a guard written against one token rather
than the behaviour has a hole shaped exactly like the other spelling.

**3 — I changed a file the brief did not name: `AuditLedgerCoherence`.** Its
assertion I2 holds a CLOSED vocabulary of `source_type` values
(`SOURCE_TABLES`, three entries). `opening_balance_row` was not among them, so the
first school to post its cutover would have turned `finance:audit-ledger-coherence`
red on a *correct* ledger — one I2 finding per opening charge. I added
`'opening_balance_row' => 'finance_opening_balance_rows'` to `SOURCE_TABLES` (which
also gives I2's dangling-reference check a real table to look in) and the matching
entry to I7's currency map. I read this as inside "step 3 — nothing else", which
bounds what the POST writes, not what the auditor is allowed to know; a new posting
instrument that does not teach the auditor its vocabulary leaves a gate reporting
green about a fact that changed underneath it. **If the reviewer disagrees, this is
the change to argue about.**

**4 — I ruled §4's open `external_reference` question, which the task did not
mention.** §4 says *"Commit 4 must rule one way or the other … Do not leave it
unstated a third time."* **The ruling is: no unique index, duplicates are legal.**
The scenario §4 named — two batches under different batch references posting the
same WCBS receipt twice — is closed outright by G1 + G1b in this same commit, so a
unique index would constrain nothing still reachable, while making a legitimate
extract that repeats a bill reference across two students abort a cutover mid-post.
Recorded in §4 of the spec and in the Action's docblock.

**5 — three shapes the brief left to me, named so they are not read as accidents.**

- `received_by_user_id` on a migrated payment is **NULL**. Nobody in this system
  received that money; the column means "who took it at the counter". Who posted is
  recorded on the batch (`posted_by_user_id`).
- `external_reference` on the netted payment is the **first non-null**
  `wcbs_bill_reference` among that student's credit rows. Netting is one row against
  possibly several references, so no single value is complete — the full per-line
  set stays on the staged rows. It is a breadcrumb, not a key (see deviation 4).
- `payer_name` (NOT NULL) snapshots `"Balance brought forward (WCBS batch <ref>)"`.
  The file carries no payer name and inventing one would be worse than naming the
  provenance a bursar can actually trace.

**6 — a stated-zero balance posts nothing.** §3 assigns an instrument to positive and
to negative; zero is neither, and a zero-amount ledger row would be a movement that
did not happen. The stated zero stays on the staged row, which is where the file's
claim belongs.

**7 — I pushed and opened the PR.** The `finance-execute` skill says the implementing
hand commits and never pushes. The task file overrides it explicitly ("Then push and
open the PR. Do not merge."), so I did. Not merged.

## Contradictions of the premise

**None material.** Every file, line number and claim the task cited reproduced:
`GenerateInvoice.php:386-428` is `applyCreditForward` and behaves as described;
`Payment.php:57` is `MIGRATED_REFERENCE_FLOOR`; `OpeningBalanceBatchStatus` had
exactly three cases; `finance_opening_balance_batches` carried no trigger and
`status` no CHECK; the staging tables' composite FK is `ON DELETE CASCADE`.

One thing the brief could not have known, found by watching it bite (see the watched
reds): **MySQL's `MESSAGE_TEXT` is a `varchar(128)`.** My first draft of both G1b
messages carried the full reasoning inline, and the over-long literal made the
`SIGNAL` itself fail with driver code **1648** ("Data too long for condition item")
instead of **1644**. The write is still refused, so the guard *looks* fine — but
every assertion, log and runbook that reads 1644 as "a house trigger refused this"
sees an unrecognised error instead. The messages are now short and the reasoning is
in the migration docblock. Worth carrying forward to any future trigger.

## What changed

`git show --name-status` is below under Proof. In summary:

| File | What |
|---|---|
| `database/migrations/2026_08_08_110000_opening_balance_posting_state_and_guards.php` (new, 195 lines) | `posted_at` + `posted_by_user_id`; the `posted_school_key` generated column + unique index (G1); the two G1b triggers; the `term_id` ruling as a column COMMENT |
| `app/Finance/Actions/PostOpeningBalanceBatch.php` (new, 276 lines) | The posting Action — §3 steps 1/2/3, the reserved band, the transition |
| `app/Finance/Enums/OpeningBalanceBatchStatus.php` | `Posted` added; `approved` still deliberately absent (4c) |
| `app/Finance/Models/OpeningBalanceBatch.php` | `posted_at` cast, two properties, the `term_id` meaning |
| `app/Finance/Console/ImportOpeningBalances.php` | `--term` → `--closing-term`; the refusal now names 4c; `resolveTerm()` records the ruling |
| `app/Finance/Console/AuditLedgerCoherence.php` | I2/I7 learn `opening_balance_row` (deviation 3) |
| `tests/Feature/Finance/OpeningBalancePostingTest.php` (new, 13 tests) | The five proofs plus eight supporting cases |
| `tests/Feature/Finance/OpeningBalanceImportTest.php` | Option rename at 5 call sites; the refusal assertion now pins "the approval gate is §9 step 4c" |
| `docs/handoff/opening-balance-import-spec.md` | §4 `external_reference` ruled; §9 build order split 4a/4b/4c, `term_id` ruled, G1b's second door; §11's G1 claim restored to the strong form |

**No Permission case, no `Submit*.php`, no `grantsMap()` edit** — the two lints that
make 4c indivisible are untouched. **`phpstan-baseline.neon` and
`tests/ratchet-baseline.txt` are untouched.** **No applied migration was edited**
(ADR 0052's corollary); the one migration here is new on this branch.

## The term_id ruling, with reasoning

**Option 2 — REPURPOSE. `term_id` names the term being CLOSED OUT.** It keeps
`NOT NULL` and its FK; only the meaning changes.

R5 does put the cutover on a term boundary, so there is no cutover term **T** — that
is what made the column look wrong. But the file **is** the closing position of a
*specific* term, and recording *which* term is exactly the provenance a cutover
needs: it is the one fact that lets a reader a year later say what period the opening
charges represent. Nullifying discards that, and takes the FK's referential
guarantee and the option's per-School validation with it, in exchange for nothing.
The cost of repurposing is a comment and a rename; the cost of nullifying is a
permanent loss of provenance plus an `ALTER`. Option 2 wins on the merits, not on the
smaller diff.

**And the code agreed with the ruling rather than against it** — the task invited me
to argue if it did not. `resolveTerm()` already validates the term belongs to the
School (`ImportOpeningBalances.php:1069-1071`), and nothing anywhere reads
`batches.term_id` for a cutover-term meaning, so no caller had to be un-taught
anything. The rename is the whole cost.

Carried through so the column is not a lie: the option is `--closing-term` with a
corrected description; `resolveTerm()`'s docblock and its error message state the new
meaning; `OpeningBalanceBatch`'s docblock states it; §9 records it; and the **column
itself carries a MySQL `COMMENT`** — the copy a reader of `SHOW CREATE TABLE` sees.
Stated plainly: the comment is **not** a constraint and is not dressed as one.
Nothing at the engine stops a caller writing the wrong term, and nothing could —
"this is last term, not this term" is a claim about meaning, the class §11
quarantines as procedural.

## Proof

### Migration reversibility — re-derived per run, not assumed

`migrate:status | tail -5` put my migration last, so `--step=1` is mine. Verified by
name in the rollback output, then asserted against `information_schema` rather than
trusting exit 0:

```
 2026_08_08_100000_realign_opening_balance_staging_for_per_fee_type_file [1] Ran
 2026_08_08_110000_opening_balance_posting_state_and_guards .. [1] Ran

 INFO  Rolling back migrations.
 2026_08_08_110000_opening_balance_posting_state_and_guards .. 85.85ms DONE

=== after rollback ===
triggers: []
columns: [{"COLUMN_NAME":"term_id","COLUMN_COMMENT":""}]
index: []
```

Both triggers gone, `posted_at` / `posted_by_user_id` / `posted_school_key` gone, the
unique index gone, the term comment cleared. Re-up, then the shape read back:

```
 INFO  Running migrations.
 2026_08_08_110000_opening_balance_posting_state_and_guards .. 85.73ms DONE

[{"TRIGGER_NAME":"finance_opening_balance_batches_no_unpost","ACTION_TIMING":"BEFORE","EVENT_MANIPULATION":"UPDATE"},
 {"TRIGGER_NAME":"finance_opening_balance_batches_no_delete_posted","ACTION_TIMING":"BEFORE","EVENT_MANIPULATION":"DELETE"}]

[{"COLUMN_NAME":"term_id","IS_NULLABLE":"NO","EXTRA":"","GENERATION_EXPRESSION":"",
  "COLUMN_COMMENT":"The term being CLOSED OUT: the last term, whose closing position this file carries (spec §9, ruled in step 4b). NOT a cutover term T — R5 puts the cutover on a term boundary."},
 {"COLUMN_NAME":"posted_at","IS_NULLABLE":"YES","EXTRA":"","GENERATION_EXPRESSION":"","COLUMN_COMMENT":""},
 {"COLUMN_NAME":"posted_by_user_id","IS_NULLABLE":"YES","EXTRA":"","GENERATION_EXPRESSION":"","COLUMN_COMMENT":""},
 {"COLUMN_NAME":"posted_school_key","IS_NULLABLE":"YES","EXTRA":"STORED GENERATED",
  "GENERATION_EXPRESSION":"if((`status` = _utf8mb4\\'posted\\'),`school_id`,NULL)","COLUMN_COMMENT":""}]

[{"INDEX_NAME":"ob_batches_posted_school_unique","NON_UNIQUE":0,"COLUMN_NAME":"posted_school_key"}]
```

`NON_UNIQUE: 0` on `posted_school_key` **alone** — the index is not on
`(school_id, posted_school_key)`, which is the §9 note about the invoice precedent.

### The new suite

```
{"tool":"pest","result":"passed","tests":13,"passed":13,"assertions":70,"duration_ms":12111}
```

### The suites this change could break

```
{"tool":"pest","result":"passed","tests":66,"passed":66,"assertions":337,"duration_ms":19830}
```

(`OpeningBalanceImportTest`, `LedgerCoherenceTest`, `SchemaConventionsTest`,
`TriggerBodiesAreDumpSafeTest`, `PaymentProvenanceTest` — the last three are the ones
that would catch a badly-shaped trigger, a dropped append-only guard, and a seeded
payment sequence respectively.)

### bin/quality

All 14 steps, raw (exit 0):

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

Step 7 (`boundary-lint`) is the one that would have caught a `Submit*.php` filename or
a `DB::table` on a `finance_` literal inside `app/Finance`; step 8 is the one that
would have caught a `grantsMap()` edit without a migration. Both green with **zero new
baseline entries** — neither baseline file was touched.

Re-derived at the time of writing: `bin/quality` is **14** steps, grants-convergence
is step **8**, the suite is step **14**.

## The watched red

Eight mutations, each planted, run, and restored. Every failure message named the
right thing.

**RED 1 — G1.** Commented out the `ADD UNIQUE ob_batches_posted_school_unique`
statement in the migration.

```
{"tool":"pest","result":"failed","tests":3,"passed":2,"assertions":3,"failed":1,
 "failures":[{"test":"PROOF 1 — G1: a second batch reaching posted for the same school is refused by the unique key (1062)",
   "message":"Failed asserting that 0 is identical to 1062."}]}
```

Observed 0, not 1062: **the second batch posted successfully.** Without the index
there is no refusal at all. Restored; `grep -c "ADD UNIQUE"` → 1.

**RED 2 — G1b, the UPDATE door.** Changed the trigger condition to
`IF 1 = 0 AND OLD.status = 'posted' AND NEW.status <> 'posted'`.

```
{"tool":"pest","result":"failed","tests":3,"passed":2,"assertions":6,"failed":1,
 "failures":[{"test":"PROOF 2 — G1b: UPDATE …SET status=rejected on a posted batch is refused BY THE TRIGGER (1644)",
   "message":"Failed asserting that 0 is identical to 1644."}]}
```

Observed 0: the `UPDATE … SET status='rejected'` succeeded — the exact release the
Rev 3 finding described. Restored.

**RED 2b — G1b, the DELETE door (the addition).** Same mutation on the BEFORE DELETE
trigger.

```
{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":1,"failed":1,
 "failures":[{"test":"PROOF 2b — G1b: DELETE of a posted batch is refused BY THE TRIGGER (1644) — the second door",
   "message":"Failed asserting that 0 is identical to 1644."}]}
```

Observed 0: the posted batch was deleted, and the assertion that the staged rows
survived never ran because the first one failed — the CASCADE took them. This is the
hole deviation 2 closes. Restored.

**RED 2 (the earlier accident, kept because it is the more interesting one).** The
first version of both triggers carried the full reasoning in `MESSAGE_TEXT`. Both
PROOF 2 and PROOF 2b failed like this:

```
{"tool":"pest","result":"failed","tests":13,"passed":11,"assertions":67,"failed":2,
 "failures":[{"test":"PROOF 2 — G1b: UPDATE …","message":"Failed asserting that 1648 is identical to 1644."},
             {"test":"PROOF 2b — G1b: DELETE …","message":"Failed asserting that 1648 is identical to 1644."}]}
```

**1648, not 1644, and not 0** — the guard refused, with the wrong error class,
because `MESSAGE_TEXT` is `varchar(128)`. A test that had asserted "an exception was
thrown" rather than the driver code would have gone green on this.

**RED 3 — the reserved band.** Replaced `nextMigratedReference()`'s body with
`Sequences::next('finance_payment', (string) $schoolId)` — the seed trap from the
other side.

```
{"tool":"pest","result":"failed","tests":2,"passed":0,"assertions":4,"failed":2,
 "failures":[{"test":"PROOF 3 — the migrated reference comes from the reserved band and the live receipt counter is UNCHANGED",
   "message":"Failed asserting that 2 is equal to 900000000 or is greater than 900000000."},
  {"test":"PROOF 3b — a second student in the same batch takes the NEXT band reference, and both are migrated",
   "message":"Failed asserting that 1 is identical to 900000001."}]}
```

Observed **2** where the band starts at 900,000,000: the migrated row took the live
counter's next value, indistinguishable from a real receipt, and advanced the
school's counter. Restored.

**RED 4a — the charge's narration.** `strtolower($row->fee_type_label)` in the
narration.

```
{"tool":"pest","result":"failed","tests":2,"passed":1,"assertions":13,"failed":1,
 "failures":[{"test":"PROOF 4 — every positive fee-type balance posts ONE charge carrying the label verbatim and pointing at its staged row",
   "message":"Failed asserting that two strings are identical.\n--- Expected\n+++ Actual\n@@ @@\n-'Tuition — Balance Brought Forward'\n+'tuition — Balance Brought Forward'"}]}
```

Restored.

**RED 4b — the charge's source.** `source_type` posted as `'invoice'`.

```
{"tool":"pest","result":"failed","tests":2,"passed":1,"assertions":9,"failed":1,
 "failures":[{"test":"PROOF 4 — every positive fee-type balance posts ONE charge …",
   "message":"Failed asserting that actual size 0 matches expected size 3."}]}
```

Zero rows carry `source_type = 'opening_balance_row'` — which is also §12's export
exclusion predicate, so this mutation would have silently put the cutover's charges
into the general-ledger export. Restored.

**RED 5 — THE CREDIT, both directions.** This is the one the task said to stop on, so
it was proved from both sides rather than once.

Mutation: no payment row is written; the ledger credit is posted bare.

```
{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":0,"errors":1,
 "error_details":[{"test":"PROOF 5 — a posted credit is CONSUMED by the next invoice: applyCreditForward draws the migrated payment",
   "message":"No query results for model [App\\Finance\\Models\\Payment]."}]}
```

That red only says "no payment row", so I also wrote a temporary probe
(`tests/Feature/Finance/TmpBareCreditProbe.php`, deleted afterwards) asserting the
failure mode §3 *describes* — right total, fully outstanding invoice. **Under the
mutation it PASSED:**

```
{"tool":"pest","result":"passed","tests":1,"passed":1,"assertions":4,"duration_ms":11059}
```

i.e. with a bare ledger credit the account balance is correct (−500,000 then +700,000
after a 1,200,000 charge) and the new invoice reads **fully outstanding — 1,200,000
allocated 0**. On the restored code the same probe fails, because the credit is now
consumed:

```
{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":2,"failed":1,
 "failures":[{"test":"PROBE — a bare ledger credit nets the balance and leaves the invoice fully outstanding",
   "message":"Failed asserting that 500000 is identical to 0."}]}
```

**§3 step 2's claim holds, verified from both sides**: the account nets identically
either way, and only the payment row makes the next invoice's outstanding fall.
Probe deleted (`git status` clean of it); code restored.

## Database observations

Under the privacy rule — structure and counts only.

- Local test database `portal_testing` only. **The production copy
  (`portaa10_portal`) was not touched, migrated or read.**
- `finance_opening_balance_batches` before: 8 columns' worth of shape unchanged;
  after: `+posted_at`, `+posted_by_user_id`, `+posted_school_key` (STORED GENERATED),
  `+1` unique index, `+2` triggers (it previously carried **0**).
- `finance_opening_balance_rows`: unchanged.
- `finance_payments`: unchanged — no migration touches it. The migrated rows this
  Action writes use columns that shipped in step 3.
- No school in any environment has a posted batch; the state did not exist before
  this branch.

## Not done

- **The approval gate (4c) is not built**, by design. There is therefore **no
  production caller of the Action at all** — it is reachable only from tests. Anyone
  reviewing "is posting authorised?" should read that as "posting is not reachable",
  not as "posting is ungated".
- **Concurrency is not proved by a test.** Two simultaneous posts for one school
  cannot both commit (G1's unique index), but I did not write a concurrency case in
  the shape of `InvoiceConcurrencyTest` / `WalletW3ConcurrencyTest`. The reasoning is
  in the Action's docblock; the reasoning is not a proof. **Suggested: ticket.**
- **The reference allocation's read-then-write is unlocked.** `MAX(reference)` within
  the band is read without a lock; `UNIQUE (school_id, reference)` is the backstop and
  G1 makes the racing case unreachable. Stated rather than tested (same ticket).
- **No U12b surface**, no API, no UI — step 5.
- **§12 decision 1 (does the migrated payment need date D on its own surface?) is
  left open.** 4b did not need it: the payment inherits D from the batch by
  provenance. I did not add a column, and I am not treating silence as a ruling.
- **`--closing-term` is a breaking CLI rename.** Nothing in the repo calls the command
  with `--term` any more (grepped: only historical report/spec prose), but any operator
  runbook held outside this repository will break loudly, which is the intended failure.

## Findings raised, not fixed

- `app/Finance/Console/AuditLedgerCoherence.php:78` — `SOURCE_TABLES` is a **closed
  vocabulary with nothing pinning it to the writers**. Adding a posting instrument
  and forgetting this entry turns the auditor red on a correct ledger, and nothing
  fails until someone runs it. A test enumerating the distinct `source_type` values
  the Actions write and asserting each is in the map would close it. **ticket.**
- MySQL `MESSAGE_TEXT` is `varchar(128)` and a longer literal downgrades a house
  trigger's refusal from 1644 to 1648 — silently, because the write is still refused.
  `TriggerBodiesAreDumpSafeTest` already walks every trigger body for balanced quotes;
  a length assertion in the same test would catch this class for every future trigger.
  **ticket.**
- `finance_opening_balance_batches` has **no CHECK tying `posted_at` /
  `posted_by_user_id` to `status = 'posted'`**. The only writer sets all three in one
  statement, so the columns cannot disagree today; a second writer could. Noted rather
  than built — a constraint over a column the app already writes atomically is
  decoration until there is a second writer. **ticket.**
