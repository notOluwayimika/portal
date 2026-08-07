# Commit 3 — §4 provenance shapes, plus the phase-1/phase-2 merge chain

Branch `feat/opening-balance-import-staging`, PR #211. Commit `7f04733`, pushed.

## Phase 1 — the merge chain

| What | SHA |
|---|---|
| PR #212 → staging | `b3c24d5` (`Merge pull request #212 from notOluwayimika/fix/quality-gate-dependency-integrity`) |
| PR #213 → staging | `f09c243` (`Merge pull request #213 from notOluwayimika/docs/local-enforcement-floor-adr`) |

Both merge commits, not squashes.

The three greps, on the merged staging (`git show origin/staging:bin/quality`):

```
=== printf [%d/ ===
59:    printf '%s[%d/14]%s %s\n' "$BOLD" "$STEP" "$OFF" "$1"
=== abort_check ===
72:# step 1 alone uses abort_check() and exits 2, which is the severity the `[ -d vendor ]`
90:abort_check() {
132:#    ABORTS the run rather than collecting — abort_check(), not check(). See check()'s
135:abort_check "dependency-integrity-lint" php bin/ci-dependency-integrity-lint.php
=== ls lint ===
100755 blob e825cb419b5e1cbb416535757a0f37ddb045d252	bin/ci-dependency-integrity-lint.php
```

`abort_check()` is defined at `bin/quality:90` and its failure path ends `exit 2` at `:100`. Step 1
(`:134-135`) is its only caller. The literal `14` in the counter was cross-checked against the actual
`step "` calls — fourteen, no more:

```
134 dependency integrity   150 wayfinder   157 lint-changed   161 tsc ratchet
175 frontend build         178 authz       181 boundary       193 grants-convergence
196 money                  199 runtime-zero 202 identifier-gen 205 arch
208 larastan               213 tests
```

### ADR 0053 vs the merged gate — no disagreement, nothing touched

Every number the ADR states was checked against the merged `bin/quality`:

- `:33` "Fourteen steps" — the counter says 14 and there are 14 `step` calls.
- `:38` "Steps 2–14 each measure a property of the code" — correct; steps 2-14 all use `check()`.
- `:38-40` "Step 1 … **aborts** the run (exit 2)" — correct: `abort_check` at `:135`, `exit 2` at `:100`.
- `:41` "Thirteen green ticks" — 14 minus step 1. Correct, and left alone as instructed.

Also confirmed rather than assumed: `:58` "It does not verify `node_modules`" matches the gate's own
admission at `:108`, and `:53-56`'s claim about the PHPStan cache matches `:128-129`.

Neither document was adjusted to fit the other.

### The rebased ADR branch's pre-push run, raw

Rebased onto `b3c24d5` (`cfdb1cc`), pushed with `--force-with-lease`:

```
pre-push: running bin/quality (local is the enforcement floor)
quality gate — base b3c24d5
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
To https://github.com/notOluwayimika/portal.git
 + 8c8d5cf...cfdb1cc docs/local-enforcement-floor-adr -> docs/local-enforcement-floor-adr (forced update)
```

## Phase 2 — the finance branch

`git merge origin/staging` into `feat/opening-balance-import-staging` (merge, not rebase — PR #211 is
open and reviewed). **Zero conflicts**, 41 files / +3568 / −169, merge commit `23d5802`. `bin/quality`
on the result: fourteen steps, all green. Same output shape as above, base `f09c243`.

## Commit 3

```
7f04733ecc1cffb65b087b857185f3219c286650
feat(finance): §4 provenance shapes — origin, external_reference, and the seed trap

M	app/Finance/Actions/RecordAccountPayment.php
M	app/Finance/Actions/RecordPayment.php
M	app/Finance/Models/Payment.php
A	database/migrations/2026_08_07_110000_add_provenance_to_finance_payments.php
M	docs/handoff/opening-balance-import-spec.md
A	tests/Feature/Finance/PaymentProvenanceTest.php
```

### The four premises — all four CONFIRMED

**1. The payment sequence takes no seed; the dominant pattern does.** `git grep -n "Sequences::next" -- app/`
returns six call sites. The two identifier concerns pass a seed closure and adopt the domain maximum —
`HasAdmissionNumber.php:55` (`fn () => static::currentAdmissionSuffixMax(...)`) and
`HasStaffNumber.php:54` (`fn () => static::currentStaffSuffixMax(...)`), each with a docblock explaining
that a switch onto the counter must never reissue an existing identifier. The four finance call sites
pass **two arguments only** — `GenerateInvoice.php:197`, `RecordAccountPayment.php:82`,
`RecordPayment.php:79`, `SubmitCreditNote.php:57`. So on the payment scope the counter starts at 0.

**2. `Sequences::next`'s third parameter is what makes that load-bearing.** `Sequences.php:52` reads
`'value' => $seed ? (int) $seed() : 0` — with no seed the row is created at 0 and `:64` returns 1. The
docblock at `:33-36` states the seed's purpose is exactly "to adopt an existing maximum on first use".

**3. §4's table name is dead.** `git log --oneline -1` on the rename migration:
`625d54f feat(finance): freeze the module template — finance_* rename + DB-enforced uniform school_id`.
`2026_07_19_110000_rename_fee_tables_to_finance.php:27` maps `'fee_payments' => 'finance_payments'`.
§4 said `fee_payments` throughout.

**4. `bank_account_id` does not exist.** `git grep -ln "bank_account_id" -- database/migrations app/`
exits 1 with no output.

**5. There is no payment-method enum.** `ls app/Finance/Enums/` — fifteen enums (CreditNoteKind,
CreditNoteStatus, DiscountBasis, DiscountPolicyChangeKind, DiscountPolicyChangeStatus,
DiscountPolicyStatus, FeeScheduleChangeKind, FeeScheduleChangeStatus, FeeScheduleStatus,
InvoiceLineKind, InvoiceStatus, LedgerEntryType, OpeningBalanceBatchStatus, OpeningBalanceRowStatus,
VoidRequestStatus). None is a payment method. `finance_payments.method` is
`$table->string('method')->default('manual')` (`2026_07_19_100002_create_fee_payments_tables.php:36`) —
an unconstrained string.

### The migration, verbatim

See `database/migrations/2026_08_07_110000_add_provenance_to_finance_payments.php`. Two columns
(`origin` VARCHAR(16) NOT NULL DEFAULT `'portal'` after `method`; `external_reference` nullable), then a
raw `ALTER TABLE finance_payments ADD CONSTRAINT finance_payments_origin_shape CHECK (origin COLLATE
utf8mb4_bin IN ('portal', 'migrated'))`, following
`2026_08_01_120000_add_currency_shape_checks.php:66-71`. `down()` looks the constraint up in
`information_schema.TABLE_CONSTRAINTS` before dropping it (8.0.43 rejects `DROP CHECK … IF EXISTS`),
then drops both columns. No `bank_account_id`.

### One correction to the brief's item 6, found before building

The brief asked for a bite-proof that "the origin CHECK refuses an **UPDATE** to a third value at the
database". On `finance_payments` that door was already shut harder: the table is append-only and
`finance_payments_no_update` (BEFORE UPDATE, `SIGNAL SQLSTATE '45000'`, driver **1644**) fires ahead of
any CHECK evaluation, so an UPDATE never reaches 3819. The CHECK's live door on this table is
**INSERT**. Both refusals are at the database; only the code differs, and the test asserts the one that
actually fires in each case. This is the same shape `CurrencyShapeConstraintTest`'s path 3 already
records ("CHECK 3819 on insert; immutability trigger still 1644 on update"), so it is precedent, not a
new claim.

### The three watched reds, raw

**1. Drop the CHECK → the third value inserts.** Commented out the `ALTER TABLE … ADD CONSTRAINT`:

```json
{"tool":"pest","result":"failed","tests":3,"passed":2,"assertions":5,"duration_ms":11173,"errors":1,"error_details":[{"test":"P\\Tests\\Feature\\Finance\\PaymentProvenanceTest::__pest_evaluable_it_origin_—_a_third_value_is_refused_3819_at_the_INSERT__an_UPDATE_to_one_is_refused_1644_by_the_append_only_trigger","file":"/Users/mac/Documents/Projects/portal/tests/Feature/Finance/PaymentProvenanceTest.php","line":111,"message":"expected the origin CHECK to refuse a third value"}]}
```

**2. Flip the default to `'migrated'` → the untouched write path stops writing `'portal'`.**

```json
{"tool":"pest","result":"failed","tests":3,"passed":1,"assertions":7,"duration_ms":11413,"failed":2,"failures":[{"test":"…it_default_—_the_existing_payment_path_writes_origin____portal__with_no_code_change","file":"…/PaymentProvenanceTest.php","line":157,"message":"Failed asserting that two strings are identical.\n--- Expected\n+++ Actual\n@@ @@\n-'portal'\n+'migrated'"},{"test":"…it_seed_trap_—_a_migrated_row_in_the_reserved_band_does_NOT_drag_the_live_receipt_sequence_up_with_it","file":"…/PaymentProvenanceTest.php","line":184,"message":"Failed asserting that 0 is identical to 1."}]}
```

The seed test failed collaterally here because it selected the payment by `origin = 'portal'`. That
coupling was a defect in the test — it would have failed for another test's reason — so it was changed
to select by `payer_name` before the final run.

**3. Add a seed closure to `RecordPayment` → the portal receipt lands in the reserved band.** This is
the corruption itself, reproduced:

```json
{"tool":"pest","result":"failed","tests":3,"passed":2,"assertions":8,"duration_ms":11317,"failed":1,"failures":[{"test":"…it_seed_trap_—_a_migrated_row_in_the_reserved_band_does_NOT_drag_the_live_receipt_sequence_up_with_it","file":"…/PaymentProvenanceTest.php","line":186,"message":"Failed asserting that 900000002 is identical to 1."}]}
```

All three restored; the file is green at `3 passed, 9 assertions`.

> Note on the raw output shape: `./vendor/bin/pest` is wrapped in this environment and emits JSON rather
> than Pest's normal renderer. The JSON above is the unedited output.

### Can the seed trap be ENFORCED? No — only asserted, and the test says so

The invariant is the **absence of an optional third argument** at two call sites, and nothing mechanical
can see an absence:

- **A DB constraint sees only the value.** A seeded counter produces `900000002`, a legal unsigned
  bigint under `UNIQUE (school_id, reference)`; nothing at the schema level tells it apart from an
  intended migrated reference. The one constraint that *would* be structural —
  `CHECK (origin = 'migrated' OR reference < 900000000)` — is expressible, since both columns are on
  the row, and is **deliberately rejected**: it turns a silent permanent corruption into a hard 3819 on
  every payment the school takes after the import, i.e. it closes the bursar's front door rather than
  preventing the mistake. Recorded as considered, with the note on how to reopen it.
- **A lint would be evadable.** `bin/ci-identifier-generation-lint.php` shows the machinery exists, but
  the rule would be "`Sequences::next` with scope `'finance_payment'` takes exactly two arguments",
  pinned to a string literal at a call site. Extracting the scope into a variable walks past it. A lint
  that a refactor defeats is wallpaper with a build step.
- **Static analysis has no opinion** — the parameter is optional and both arities type-check.

So **the test is the only mechanism**, and the test's own header states that plainly rather than
implying more. It is a real one — it fails the suite, which is `bin/quality` step 14, which the pre-push
hook runs — but it catches the mistake at push time, not at write time. The comments at
`Payment::MIGRATED_REFERENCE_FLOOR`, `RecordPayment.php:79` and `RecordAccountPayment.php:82` are
documentation, not enforcement, and are labelled as such.

### The receipt surface — there isn't one, so nothing was built

Searched `app/`, `routes/`, `resources/js/`, `resources/views/` for any receipt, printable, PDF, mail,
export or download over `finance_payments`. **Nothing produces a receipt for a payment.** What exists:

| Surface | File |
|---|---|
| `POST …/invoices/{invoice:uuid}/payments` (JSON) | `routes/endpoints/finance.php:24` → `PaymentController::store` (`:19`) |
| `POST …/students/{student:uuid}/payments` (JSON) | `routes/endpoints/finance.php:145` → `PaymentController::storeForStudent` (`:42`) |
| payments embedded in an invoice read model (JSON) | `InvoiceController::forStudent` (`:119`) |
| a read-only payments table, no actions | `resources/js/pages/admin/finance/statement.tsx:688` |
| `NotificationType::PAYMENT_RECEIVED` — enum case with **no dispatcher** | `app/Notifications/Enums/NotificationType.php:39` |

Per the brief, **nothing was built**. §4 now records the refusal as an obligation on whichever commit
introduces the first receipt surface: refuse for `origin = 'migrated'` **with a stated reason**, never a
silent hide.

### §4 spec corrections, same commit

- `fee_payments` → `finance_payments`, `fee_invoices` → `finance_invoices`, with a note that the
  *filename* citations (`2026_07_19_100002_create_fee_payments_tables.php`) stay correct.
- the `bank_account_id` row now says the column **does not exist**, struck through, rather than "stays
  null".
- the seed-trap invariant, including the honest enforceability answer and the rejected CHECK.
- the `method`-is-unconstrained limit, with the explicit division: `origin` is the predicate and has a
  constraint, `method` is a description and has none, code filters on `origin`.
- the CHECK's rationale (`COLLATE utf8mb4_bin`; INSERT is its live door on an append-only table).
- the receipt refusal recorded as owed.

### Baselines

`phpstan-baseline.neon` and `tests/ratchet-baseline.txt` are **untouched** —
`git diff origin/staging...HEAD` over both paths is empty. Neither needed to grow (ADR 0041).

### Reversibility, against the real dev DB — depth re-derived, not assumed

`migrate:status` put this migration in batch **23**, shared with
`2026_08_05_120000_create_notification_suppressions`, so a bare `--step=1` could plausibly have hit the
wrong one. The rollback output names the migration it reverted, and the schema was probed on both sides
rather than trusting an exit code:

| | `origin` col | `external_reference` col | CHECK rows | default | nullable | `notification_suppressions` |
|---|---|---|---|---|---|---|
| after `up()` | true | true | 1 | `portal` | NO | true |
| after `migrate:rollback --step=1` | false | false | 0 | — | — | **true** (untouched) |
| after re-`migrate` | true | true | 1 | `portal` | NO | true |

Rollback output: `2026_08_07_110000_add_provenance_to_finance_payments .. 58.10ms DONE` — *this*
migration, and the co-batch one survived.

### One thing the proof does NOT cover

`finance_payments` holds **0 rows** on the local production copy, so the ALTER's "every existing row
becomes `'portal'` in the ALTER itself" claim is exercised only on test fixtures, never on real data.
That matches the currency-shape migration's own finding that Finance was empty in production as of
2026-08-01, and the same window closes the first time a school records a payment.

### `bin/quality`, fourteen steps, on the pushed commit

```
[1/14] dependency integrity (composer.lock vs composer.json vs vendor/)   ✓ dependency-integrity-lint
[2/14] wayfinder:generate --with-form                                     ✓ wayfinder:generate
[3/14] lint changed files (Pint / Prettier / ESLint, check mode)          ✓ lint-changed
[4/14] types (tsc ratchet vs tsc-baseline)                                ✓ tsc-ratchet
[5/14] frontend build                                                     ✓ build
[6/14] authorization guard (no new commented-out checks)                  ✓ authz-lint
[7/14] boundary lint (§17.2)                                              ✓ boundary-lint
[8/14] grants-convergence lint                                            ✓ grants-convergence-lint
[9/14] money lint                                                         ✓ money-lint
[10/14] runtime-zero lint (S7 legacy access sources)                      ✓ runtime-zero-lint
[11/14] identifier-generation bypass guard (1.4b)                         ✓ identifier-generation-lint
[12/14] architecture tests (§17.1)                                        ✓ arch
[13/14] static analysis (Larastan level 5 vs baseline)                    ✓ larastan
[14/14] tests (failure ratchet vs tests/ratchet-baseline.txt)             ✓ test-ratchet
✓ quality: PASS — per-push floor.
```

The first run of this gate failed step 3 (Pint: `fully_qualified_strict_types`, `ordered_imports` on the
new test). Fixed with `./vendor/bin/pint`, amended, re-run green — recorded because the gate found it,
not because it was anticipated.

---

## Review remediation — `28bc9d5`, pushed

A cold review of `7f04733` returned **ship with fixes**: one `fix`, four `ticket`s. All five closed in
`28bc9d5`. Fourteen steps green on the push.

### The fix — the seed test pinned one of the two doors it claimed to pin

`RecordPayment` and `RecordAccountPayment` share **one** counter (scope `finance_payment`, key the
school id — `RecordAccountPayment`'s own comment: *"one receipt series per school across both doors"*),
and `Sequences.php:47-56` evaluates the seed on **first use only**. So whichever door a school uses
first after an import creates the counter row. A seed added to `RecordAccountPayment` **alone** left the
original test green — it never calls that Action — and would then corrupt the band through *both* doors,
since the counter is shared. The account door is reached by `POST …/students/{student:uuid}/payments`
under the same `finance.payment.record` permission, so it is exactly as likely to be a school's first
payment after cutover.

Closed: `PaymentProvenanceTest` now drives both doors, one seed case each, over a shared
`plantImportedBandRow()` helper. **Watched red, raw** — seeding `RecordAccountPayment` only:

```json
{"tool":"pest","result":"failed","tests":4,"passed":3,"assertions":11,"duration_ms":13120,"failed":1,"failures":[{"test":"…it_seed_trap__ACCOUNT_door_—_the_same_counter__reached_without_an_invoice__must_not_adopt_the_band_either","file":"…/PaymentProvenanceTest.php","line":229,"message":"Failed asserting that 900000002 is identical to 1."}]}
```

The account case failed and the invoice case stayed green — the reviewer's failure mode reproduced
exactly, which is what makes the new case worth having. Restored: `4 passed, 12 assertions`.
`Payment::MIGRATED_REFERENCE_FLOOR` no longer over-claims, and names the third-call-site gap explicitly:
commit 4's posting Action, if it allocates through `Sequences` on this scope, is pinned by neither case
and must arrive with its own.

### The four tickets

- **Commit 1's header contradicted commit 3.** `2026_08_06_100000:16-19` still listed
  `origin` / `external_reference` / the reserved band as "DELIBERATELY ABSENT". Two of the three shipped
  here. The reviewer also caught that the header's stated reason was wrong *when written* — §9 puts
  provenance at step 3 and the posting Action at step 4, so `origin` was always meant to precede its
  writer. Reframed as a point-in-time list, saying what shipped and what did not.
- **Two citations no longer reproduced, both mine.** The `bank_account_id` docblock handed the reader a
  grep recipe that the docblock then satisfied itself; the recipe is dropped and the fact stated, with
  the self-hit disclosed. And `RecordPayment.php:79` / `RecordAccountPayment.php:82` pointed at the first
  line of the comment block this very commit inserted — the calls had moved to `:83` / `:86`. Every
  call-site citation is now by symbol, which cannot drift.
- **The receipt refusal lived only in a 900-line spec.** Pointers now sit where the grep lands:
  `NotificationType::PAYMENT_RECEIVED` (declared, no dispatcher) and `PaymentResource` (the only surface
  that renders a payment). Still no guard built — there is still nothing to guard.
- **`external_reference` shipped with no uniqueness decision.** Nullable, no index; batch-level
  idempotency (`ob_batches_school_reference_unique`) does not cover it, so two batches under different
  batch references carrying the same WCBS receipt post twice. §4 now names it as a commit-4 decision:
  unique on `(school_id, external_reference)` where non-null, or an explicit "duplicates are legal
  because …".

### One review argument raised, tested, and REJECTED

The reviewer challenged the migration's rationale that `origin` ships early because "retro-marking is
impossible", on the grounds that `NOT NULL DEFAULT 'portal'` retro-marks correctly whenever the ALTER
runs, so §9's build order is the real and sufficient justification. **That is wrong, and the objection
is rejected.** It was accepted in the first pass of this report; that was an error, corrected here
before the report was committed, because the sentence it weakens is the one that stops `origin` being
descoped later.

`NOT NULL DEFAULT 'portal'` retro-marks **uniformly, not correctly.** The two coincide only while no
migrated row exists — which is precisely the precondition the sentence exists to enforce, not an
independent fact about the default. Tested against the R4 export path: run the import first and add the
column after, and every migrated payment is silently stamped `'portal'`, joins the WCBS collections and
general-ledger export, and double-counts the cutover — the exact failure R4 made `origin` structural to
prevent. The DEFAULT cannot do better, because by then the rows no longer carry the distinction it would
need to mark: **you cannot correctly retro-mark a distinction the data has already lost.** The column is
not a label applied to rows, it is a fact that must be captured at write time.

So the ordering is load-bearing on its own terms and does not rest on §9. §9's build order is a **second**
reason, not a replacement for the first, and the migration docblock now says both explicitly so a future
reader cannot reach for the weaker one alone.
