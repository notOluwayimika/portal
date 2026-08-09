# Implementation report — S2 + S3, the capture columns

## Headline

**Done.** Five NOT NULL columns on three append-only tables, every writer supplying them, and
`bin/quality` green 14/14.

Branch `feat/finance-capture-columns-s2-s3`, base `8f2c7f5` (`origin/staging`, #228 merged).

**Full-review tier** — money, a migration, and a schema decision that cannot be revisited after the
first row exists. Recommend a cold session before merge.

## Two things to read before the diff

**1. I diverge from your lean on one of the two reversal rulings**, on evidence from the enum's own
docblock. `ApproveVoidRequest` back-dates to the original charge, as you leaned;
`ApproveCreditNote` does not. See *The reversal rulings*.

**2. Rule 2's premise is wrong: there are TWO allocation rules in the code, not one.** Recording one
constant would have stamped every credit-draw allocation with `RecordPayment`'s identity — a false
attribution on an append-only row, which is harder to notice than a missing one. See *The allocation
rules*.

## Premises — verified against `information_schema`, not the migrations

All confirmed exactly as you wrote them: the four column lists, the `_no_update`/`_no_delete`
triggers on all three tables, and `finance_school_settings` carrying one substantive column
(`invoice_number_prefix`). Rule 2's stop condition never fired — no settings column is needed.

**Three corrections, none of which changes the plan:**

- **All three tables are EMPTY in the copy** (0 payments, 0 ledger rows, 0 allocations, 0 invoices).
  This is what made your NOT NULL correction available, and it means there is no stranded history
  *yet* — the urgency is "before term one writes the first row", not "rows are already
  unattributable".
- `reference` and `number` are `bigint unsigned`, not strings.
- `origin` has a CHECK restricting it to `('portal','migrated')`. There is no `manual`.

### The refusal, from the engine

Because the tables are empty there was no existing row to update, so each was inserted and then
updated **inside a rolled-back transaction** — nothing was written:

```
finance_payments             SQLSTATE 45000 / 1644
  finance_payments is append-only (Constitution §15C): UPDATE is denied.
finance_ledger_transactions  SQLSTATE 45000 / 1644
  finance_ledger_transactions is append-only (Constitution §15C): UPDATE is denied.
  Corrections are reversing entries.
finance_payment_allocations  SQLSTATE 45000 / 1644
  finance_payment_allocations is append-only (Constitution §15C): UPDATE is denied.
```

This bit me later, and instructively: my first draft of the void-reversal arm back-dated the original
charge with an `UPDATE` and the trigger refused it — **this commit's own subject breaking its own
test**. Rewritten to travel in time so the charge genuinely belongs to last month. A test that edited
history to set itself up would have been proving something the production path can never do.

## The writer survey — three sites the brief did not name

| Table | Writers |
|---|---|
| `finance_payments` | `RecordPayment:86`, `RecordAccountPayment:91`, `PostOpeningBalanceBatch:236` (`origin = 'migrated'`). `PaymentController` delegates and writes nothing. |
| `finance_payment_allocations` | `RecordPayment:96`, **`GenerateInvoice:416`** — the credit-draw path. The brief named `GenerateInvoice` for charges only. |
| `finance_ledger_transactions` | **One** writer, `SubledgerPoster:47` — a genuine funnel. Seven callers: `GenerateInvoice:225`, `RecordPayment:107`, `RecordAccountPayment:104`, `PostOpeningBalanceBatch:203` and `:270`, **`ApproveCreditNote:90`**, **`ApproveVoidRequest:81`**. |

## The migration — one, not three

`2026_08_09_120000_finance_capture_columns_s2_s3`. Five NOT NULL with **no defaults**; the two
`*_reason` columns nullable. Verified in the running database:

```
finance_payments.received_at                  date          nullable=NO   default=NULL
finance_payments.received_at_reason           varchar(255)  nullable=YES  default=NULL
finance_ledger_transactions.posted_at         timestamp     nullable=NO   default=NULL
finance_ledger_transactions.effective_at      date          nullable=NO   default=NULL
finance_payment_allocations.allocation_rule            varchar(64)   nullable=NO   default=NULL
finance_payment_allocations.allocation_overridden      tinyint(1)    nullable=NO   default=NULL
finance_payment_allocations.allocation_override_reason varchar(255)  nullable=YES  default=NULL
```

**One migration because of the writers, not tidiness.** The same commit teaches six write sites to
supply all five columns. Three migrations of which only the first had run would leave finance unable
to record money at all. One decision, one precondition, one unit.

`docs/handoff/post-deploy-tasks.md` gains a PRE-deploy step with the count query, stating that a
non-empty table aborts the migration **and that this is the design** — a deploy that stops is
recoverable, a fabricated received date on an append-only table is not.

## The reversal rulings

**`ApproveVoidRequest` → the ORIGINAL charge's effective date.** Agreed with your lean. A void says
the invoice should never have existed, and `VoidEligibility` guarantees no payment ever touched it —
so the honest record is one period where charge and reversal net to zero. Dating it today leaves the
original period overstated forever and understates this one by the same amount. Read from the charge
row itself, which is the only authority on which period it landed in.

**`ApproveCreditNote` → TODAY. This differs from your lean, deliberately.** The authority is
`CreditNoteKind`'s own docblock:

> `CreditNote` — "goodwill, correction, an over-charge acknowledged — **the money is forgiven**"
> `WriteOff` — "the receivable is **judged** uncollectable"

Both are present-tense judgements, not corrections of a mis-posting. A void asserts the charge never
should have existed; a credit note asserts the opposite — the charge stood, and a NEW decision is
being taken now to forgive part of it. **A receivable becomes uncollectable at the moment someone
judges it so**; back-dating a write-off to the invoice's period would assert it was never collectable
and restate a period that was correct when it closed.

The reasoning is at the call site, and it says explicitly that this must change because the policy
says so, not because it matched its neighbour. **Overturn it if Brookstone's accounting policy
disagrees** — the ledger is append-only, so it is cheap now and impossible later.

## The allocation rules — two, not one

| Constant | Behaviour |
|---|---|
| `RULE_PAYMENT_AGAINST_NAMED_INVOICE` | The incoming payment is allocated against the invoice **it names**, capped at outstanding; the remainder banks as credit. (`RecordPayment`) |
| `RULE_CREDIT_APPLIED_FORWARD_OLDEST_FIRST` | A newly raised invoice draws down **earlier** payments' unallocated remainders, oldest first, capped at min(credit, total, Σ unallocated). (`GenerateInvoice::applyCreditForward`) |

These answer different questions about where an allocation came from. Not an enum — two named
constants recording what the code does, so a third rule arrives when its writer does.

**The allocation columns carry no date, deliberately.** An allocation is a settlement *link*; it
posts nothing to the ledger, so it has no period of its own. The two dates that matter already live
on the rows it links, and a third could disagree with them permanently.

## `posted_at` vs `effective_at`

`posted_at` is when the row was **written** — a system-clock fact, what an audit trail walks.
`effective_at` is the business date the entry **belongs to** — what a statement, an ageing report and
a period total are built from. They coincide for an invoice raised today and diverge for every
correction, back-dated receipt and migrated opening balance.

`posted_at` is deliberately **not** a parameter of `SubledgerPoster::post`: a caller that could
choose it could lie about when the system learned a fact. `effective_at` is a required argument with
no default, because every caller knows its own period and a default would silently make it "today"
for exactly the callers where that is wrong.

## Proof

```
DB_DATABASE=portal_testing ./vendor/bin/pest tests/Feature/Finance tests/Feature/Quality
{"tool":"pest","result":"passed","tests":425,"passed":425,"assertions":...}

tests/Feature/Finance/CaptureColumnsTest.php — 17 arms, all green
```

### bin/quality — raw, unedited (ANSI stripped)

```
quality gate — base c598e2a

[1/14] dependency integrity (composer.lock vs composer.json vs vendor/)
   ✓ dependency-integrity-lint
[2/14] wayfinder:generate --with-form (must match vite.config.ts formVariants)
   ✓ wayfinder:generate
[3/14] lint changed files (Pint / Prettier / ESLint, check mode)
   ✓ lint-changed
       Pint (check) on 35 changed PHP file(s)
       Prettier (check) on 1 changed file(s)
       ESLint on 1 changed file(s)
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

## The watched reds — mutation observed in the RUNNING PROGRAM

Per #225's RED 3 as amended in #228, each mutation was read back out of the loaded class by
reflection (or out of the actual `rules()` array), never from the diff.

**RED 1 — the migrated payment stamped `now()` instead of cutover.**

```
RUNNING PROGRAM line 266: 'received_at' => now()->toDateString(),
FAILED: PostOpeningBalanceBatch: the migrated payment and its ledger rows are dated CUTOVER, not today
  A migrated payment was dated the day the batch was posted. The money reached WCBS at cutover;
  dating it today moves a term of cash into the import period and the table is append-only, so it
  can never be corrected.
```

**THIS RED DID NOT FIRE ON THE FIRST ATTEMPT, AND THAT IS THE MOST USEFUL THING IN THIS REPORT.**
The whole opening-balance suite stayed green with the value wrong. Cause:
`OpeningBalancePostingTest` builds batches with `cutover_date => now()`, so cutover and today are the
**same value** there and no assertion could tell them apart. I had skipped that writer's arm as
expensive to build — and it was the one the brief called the interesting case. A fixture that cannot
distinguish the right answer from the wrong one is the quietest kind of coverage gap. The new arm
cuts over two months in the past, which is also what a real cutover looks like.

**RED 2 — the credit-draw allocation claiming `RecordPayment`'s rule.**

```
RUNNING PROGRAM line 437: 'allocation_rule' => PaymentAllocation::RULE_PAYMENT_AGAINST_NAMED_INVOICE,
FAILED: A credit-draw allocation is claiming RecordPayment's provenance. The two rules are different
  questions and the row is append-only, so a wrong attribution is permanent.
```

**RED 3 — the void reversal dated today.**

```
RUNNING PROGRAM line 102: now()->toDateString(),
FAILED: The void reversal landed in a different period from the charge it reverses, leaving both
  periods wrong about an invoice that never should have existed.
```

**RED 4 — the received-date requirement relaxed at the edge.**

```
RUNNING PROGRAM rules[received_at] = ["nullable","date","before_or_equal:today"]
FAILED: Failed to find a validation error in the response for key: 'received_at'
```

## The gate went red first, and the capture rule earned its keep immediately

Artefacts copied out **before** any re-run, per the standing rule, to
`scratchpad/nondet/capture-s2s3/`. Conditions: bare `bin/quality` (not a hook, not a re-run after a
red), 2026-08-09 17:45–17:55 WAT, load average 9.15.

**All seven new failures were mine. None was the flake.** Four causes:

| Cause | Count |
|---|---|
| `ArgumentCountError` — my call-site transform keyed on `app(RecordPayment::class)->handle(` being on ONE line; three files put `->handle(` on a continuation line | 5 |
| **My own new test used `->not->toBeNull('message')`** — #222's gate caught it; the message would have been discarded. Inverted to a positive. | 1 |
| Larastan — `LedgerTransaction` had no `@property` for the new columns | 1 |
| Larastan — `DriveFinanceStates` ×3, whose calls end in `$this->maker` and fell outside the same regex | — |

**That last one is the finding.** Three PRODUCTION call sites were missed by the same regex that
missed the tests, and **only Larastan saw them** — the suite structurally could not, because
`DriveFinanceStates` is manual-only and nothing drives it. Had the suite been green on the first
attempt I would have shipped three broken writers. It is also the second time on this branch that a
bulk transform silently skipped a real site.

## A second red, at the pre-push hook — and a finding about the gate itself

The push was refused. Artefacts captured before any re-run, per the standing rule, to
`scratchpad/nondet/capture-prepush/`. Conditions: **PRE-PUSH HOOK** (not a bare run, not a re-run
after a red), 2026-08-09 18:12–18:22 WAT, load average 3.24.

The junit showed **exactly the seven baselined failures** — so `test-ratchet` passed and the red was
elsewhere. It was `lint-changed`: Pint wanted formatting on nine files.

**The finding is why the bare run had passed twenty minutes earlier**, and the mechanism is more
specific than "the diff was empty". `bin/lint-changed.sh:51` reads:

```
git diff -z --name-only --diff-filter=ACMR "$BASE"...HEAD
```

Three dots, and against **HEAD** — so it sees **committed changes only**. Uncommitted work is
invisible to it, and on a branch with nothing committed yet it lints zero files and passes. The
pre-push hook runs after the commit exists, computes `$BASE` as `origin/staging`, sees all nine, and
fails.

So a green `lint-changed` over uncommitted work says nothing at all and looks identical to a green
that checked everything. Same vacuity class as a watched red that never fires — this branch produced
three instances (the `cutover_date => now()` fixture, the regex that skipped continuation lines, and
this).

**Folded in rather than ticketed** (see *The received-date field*): `bin/quality` now prints the
per-tool counts on a green `lint-changed`. Zero is not an error — a docs-only change legitimately
lints nothing — so it reports rather than fails. The silence was the defect, not the zero.

Writing that surfaced a second, smaller instance of the same thing: Pint emits its JSON with no
trailing newline, so the next heading is concatenated onto it and a line-anchored match **silently
dropped the Prettier count** — the exact line most likely to read "no changed frontend files" when it
should not. Matched with `grep -o` instead. Verified on this branch:

```
   ✓ lint-changed
       Pint (check) on 35 changed PHP file(s)
       Prettier (check) on 1 changed file(s)
       ESLint on 1 changed file(s)
```

## The received-date field — folded in before merge, because the branch was shipping a break

`received_at` is required at the FormRequest and both Actions, and `record-payment-modal.tsx` sent
`amount_minor` and `payer_name` only. **Merging as-is would have 422'd every payment — the only
money-in path in the product — behind a green gate.** That is #223's template-the-upload-refused
defect, authored here.

### Proven first, in a browser, before the field was written

```
[asis] a date field is present: false
[asis] 422 /api/v1/finance/students/…/payments
       {"message":"There are validation errors",
        "errors":{"received_at":["The received at field is required."], …}}
```

**The drive ran against a DEDICATED drive database, never the production copy.**
`finance:seed-drive-fixture` refuses anything else by two guards — `APP_ENV=drive` and a database
name containing `drive` — so `portal_drive` was created for it. This also settles the seat problem
that blocked earlier drives: the fixture ships `maker@drive.test` holding `accounts_officer` with a
fixed password, so no credential on the copy had to be touched.

### After

```
[today]     a date field is present: true
[today]     pre-filled with: 2026-08-09
[today]     201 …/payments  reference=4

[backdated] pre-filled with: 2026-08-09
[backdated] reason field visible after back-dating: true
[backdated] 201 …/payments  reference=5
```

Read back out of the database:

```
payment#4 ref=4 received_at=2026-08-09 reason=NULL
   ledger#16 effective_at=2026-08-09 posted_at=2026-08-09
payment#5 ref=5 received_at=2026-08-04 reason='Handed over at the desk on the 4th'
   ledger#17 effective_at=2026-08-04 posted_at=2026-08-09
```

The two-date design end to end: the back-dated payment's ledger credit is **effective on the 4th**
and **posted on the 9th**.

### Pre-fill is not a default, and the comment says why

A server-side default is invisible — the operator submits, a date they never saw is written, and the
row asserts a business fact nobody observed, permanently. A pre-filled input is on screen, in their
hands, and submitting it is **confirmation** rather than omission. The modal's docblock says this
explicitly so the later "just default it server-side" proposal meets its own answer.

The reason field's condition **mirrors** `RecordPaymentRequest`'s `required_unless:received_at,<today>`
rather than restating it.

### One thing the drive did not cover, found while writing it

`config/app.php` sets `'timezone' => 'UTC'` while a Lagos browser is UTC+1, so **between 00:00 and
01:00 WAT the client and server disagree about what "today" is**. Left unhandled, the operator would
get a validation error for a field that was never rendered — a dead end for one hour a day. The
reason field therefore also renders when the SERVER reports that error, so the disagreement is
recoverable rather than terminal. Untested by the drive, which ran at 18:06 WAT; the fix is
reasoning, not measurement, and is flagged as such.

## Not done

- **U9 proper is still not in this commit** — only its received-date half, which had to land or the
  branch shipped a broken payment path. No bank account (S6), no method changes.
- **`allocation_overridden` has no writer that can set it true.** Every allocation is computed. The
  column is false everywhere by explicit statement, not by default; its consumer is U10.
- **The catalog of allocation rules is two, and will be wrong the day a third behaviour ships**
  without a constant. Nothing enforces that pairing — it is a convention, which by this repo's own
  standard is wallpaper. A lint requiring every `allocations()->create(` to name a constant would
  close it. **ticket.**
- **No arm covers `ApproveCreditNote`'s `WriteOff` kind specifically** — the arm uses
  `CreditNoteKind::CreditNote`. Both take the same branch, so the assertion holds for either, but the
  write-off reasoning is the stronger half of the argument and is untested as such.
