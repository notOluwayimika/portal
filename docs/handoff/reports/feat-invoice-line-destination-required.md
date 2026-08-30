# S11 commit 2 — a charge line must record its destination

**Branch:** `feat/invoice-line-destination-required` · **Base:** `staging` @ `a91806c`
(`75c478e`, S11 commit 1, is an ancestor) · **Shape:** one migration, one new test file,
three app files, one skill file, one new shared test helper, **46** touched test files.
One commit. Not pushed.

---

## 0. Deviations from the brief — read these first

### 0.1 The brief's §3 premise is FALSE, and the fixture therefore needed no exemption

The brief states that after this commit "`unrecorded` is unreachable through any writer —
it exists only on rows issued before commit 1", so the drive fixture "cannot produce one by
executing real actions, and must write it directly", making it a **third instance** of the
plant-a-row exemption to be recorded in the `finance-drive` skill.

That is wrong against the repo, and I did not do it.

`unrecorded` is not a claim about `bank_account_id`. `AllocationProposal::destinationsFor()`
resolves an invoice's destination through **`fee_item_id`**
(`app/Finance/Services/AllocationProposal.php:280` — `$line->fee_item_id === null ? null : …`),
and S11 commit 1 left that read there deliberately; its own commit message says switching it
"would report every pre-column invoice `unrecorded` and black out the mismatch banner… its
replacement is a three-valued read that needs its own commit." A free-text line has no fee
item and never will, so it still renders `unrecorded` **while carrying a perfectly valid
destination**. Nothing became unreachable.

So: the seeded charge lines were given real destinations, the free-text supplementary line
kept its role by executing the real Action as before, and **no row is written directly and no
third exemption exists**. Measured on a throwaway drive database after seeding the fixture
(§5.4): all three destination states — `matches`, `differs`, `unrecorded` — still render.

I did edit the skill, but to record the **rejected** candidate rather than a new exemption,
because the wrong instinct here is very reachable: "commit 2 closed column A, therefore the
state derived from column B is closed" is exactly the reasoning that would have planted a row
the system can still reach.

### 0.2 The prefill endpoint had to change, or the round trip breaks

Not in the brief; found by the suite. `GET /v1/finance/fee-schedules/prefill` emits a `lines`
array that `FinancePrefillRoundTripTest` posts back to the generate endpoint **verbatim**, and
it did not emit `bank_account_id`. After this commit that payload 422s on every line. Worse on
its own terms: the catalog already knows each item's destination
(`finance_fee_items.bank_account_id` is NOT NULL), so making the bursar re-pick it per line
invites a wrong pick that lands money in the wrong account — the argument
`FeeScheduleResource:96-102` already makes for the draft editor.

`FeeScheduleController::prefill()` now emits `'bank_account_id' => $item->bankAccount?->uuid`.
No screen consumes prefill yet (`resources/js` has only the wayfinder route stub), so this is a
contract change with no UI half.

### 0.3 One docblock claim I wrote was wrong and is corrected in the tree

I first wrote — copying U8 commit 3's reasoning — that without the pre-check the trigger's
refusal surfaces as "a 422 carrying the trigger's own sentence". **Measured, it is a 500.**
`GenerateInvoice::isReductionGuardViolation()` matches 1644 only on messages containing
"discount policy" (deliberately narrow); this guard's sentence does not, so the 1644 is
uncaught and falls through to a generic 500 per `bootstrap/app.php`. That makes the pre-check
*more* load-bearing than the brief assumed, not less. The corrected statement is in the
migration-adjacent docblocks and in the test file, with the measurement named.

I deliberately did **not** widen the 1644 catch. After the pre-check, the only route to that
SIGNAL over HTTP is a writer that failed to set the field — a bug, which should be loud.
Translating it into a business-rule 422 would dress a defect as an operator-caused refusal.

### 0.4 Scope the brief did not anticipate: 46 test files

Making the rule real breaks every test that writes a charge line without a destination. First
measurement: **291 failing assertions across 44 files** in `tests/Feature/Finance` alone. All
were fixed by supplying a destination at the call site (not by weakening an assertion). Detail
in §4.

---

## 1. The §2 survey — `assertDiscountPoliciesUsable`

It is **not** in `GenerateInvoice`; it is `GenerateInvoiceRequest::assertDiscountPoliciesUsable()`
(`app/Finance/Http/Requests/GenerateInvoiceRequest.php:351` before this change). Shape:

- **Where it runs.** Called from the *controller*, not from `rules()` — `InvoiceController::generate()`
  and `::generateForStudent()`, in both cases **after** `assertMayReduce()` so a principal
  without `finance.invoice.reduction.apply` gets its 403 first and learns nothing about a
  policy's lifecycle from a refusal it was never entitled to reach. In `generateForStudent()`
  it runs *after* the "no active enrollment" refusal, which is the more fundamental one.
- **Why not `rules()`.** It needs the *resolved* specs (`lineSpecs()`), because whether a rule
  applies depends on the line's `kind`, which `rules()` cannot see per-line without duplicating
  the enum default.
- **How it keys errors.** `$keys = array_keys((array) $this->input('lines', []))` — the
  **original wire keys**, not `0..n-1`, because `lineSpecs()` `array_values()`s its result. A
  payload posting `lines` as a keyed object would otherwise be told about an index that does
  not exist on the wire, and an error the form cannot find is the defect the method exists to
  fix. The field is `'lines.'.($keys[$index] ?? $index).'.discount_policy_id'`.
- **Multiple bad lines.** It **accumulates** into `$errors[$field][]` across the whole loop and
  throws **once** at the end via `ValidationException::withMessages($errors)`. It does not stop
  at the first mistake.
- **One query.** A single `whereIn` over the cited policy ids, read through the scoped model.

`assertDestinationsChosen()` mirrors all five points. Two differences, both stated in its
docblock: it needs no query at all (the destination is an integer already on the spec), and it
skips `isReduction()` lines entirely.

**One consequence I did not hide:** the two pre-checks throw independently, so a request
carrying both an unusable policy on one line and no destination on another surfaces the policy
errors first and the destination errors only on resubmission. Merging them would fix that and
would also mean neither pre-check's arms could fail independently of the other's. Recorded in
the docblock as the cost of keeping each pre-check answerable for its own trigger.

---

## 2. What shipped, in the brief's order

**1 — the pre-check** (`GenerateInvoiceRequest::assertDestinationsChosen()`, wired into both
controller methods immediately after `assertDiscountPoliciesUsable()`). Charge lines only;
error keyed to `lines.N.bank_account_id` on the original wire index; every offending line
reported, not the first.

**2 — the fixture** (`DriveFinanceStates`). `invoice()` now names the school's drive account,
resolved from the ambient `ActiveSchool::getOrFail()->id` (every caller runs inside
`ActiveSchool::runFor`, and the destination must be chosen *before* the line is written, so it
cannot come off the invoice). `unallocatedRemainder()`'s term-bill line takes the **fee item's
own** account — read off the row, which is what `FeeScheduleLineMapper` would have written, so
the mismatch axis stays a property of the catalog rather than a coincidence. Its free-text
supplementary line takes the school's account and keeps rendering `unrecorded` through its
absent `fee_item_id` (§0.1).

**3 — the trigger** (`database/migrations/2026_08_29_120000_finance_invoice_lines_require_destination.php`).
`finance_invoice_lines_destination_guard`, **BEFORE INSERT only**, `BINARY NEW.kind = BINARY
'charge' AND NEW.bank_account_id IS NULL` → `SIGNAL SQLSTATE '45000'`. A separate,
separately-named trigger beside `finance_invoice_lines_reduction_guard`, following
`2026_07_26_140002`. The docblock states the BEFORE-INSERT decision as a decision, names the
UPDATE arm as the thing not to add, and says why (history is valid; an UPDATE arm is dead code
today and a live defect the day the append-only guard is relaxed).

---

## 3. Bite-proofs — reds, then greens

All three mutations were applied one at a time, run, and reverted. Raw failure lines below.

### (i) the pre-check — mutation: comment out both `assertDestinationsChosen()` calls

```
mutated: pre-check removed at 2 sites
result failed passed 5 of 8
 RED: it_refuses_a_charge_line_with_no_destination_BEFORE_the_insert__keyed_to_lines_0 | Expected response status code [422] but received 500. … PDOException: SQLSTATE[45000]: <<Unknown error>>: 1644 A charge line must record the bank account its money is destined for.
 RED: it_reports_EVERY_charge_line_missing_a_destination__each_keyed_to_its_own_wire_i | Expected response status code [422] but received 500. …
 RED: it_keys_the_error_to_the_ORIGINAL_wire_index_when_lines_arrive_as_a_keyed_object | Expected response status code [422] but received 500. …
```

This is the measurement behind §0.3: **500, not a bare 422.** Restored → 8/8 green.

### (iv) the trigger — mutation: `BINARY 'charge'` → `BINARY 'nosuchkind'`

```
mutated
result failed passed 7 of 8
 RED: it_refuses_a_charge_line_with_NULL_at_the_DATABASE_when_the_pre_check_is_bypasse | The raw INSERT was not refused — finance_invoice_lines_destination_guard did not fire.
```

The red is the **database** failing to refuse, which is what the arm claims. Note arm (i) stayed
green under this mutation and arm (iv) stayed green under mutation (i) — the two layers fail
independently, which is the point of having both. Restored → 8/8 green.

### (iv-b) the `kind` branch — mutation: `BINARY NEW.kind = BINARY 'charge'` → `NEW.kind IN ('charge','waiver')`

Added on the cold review's finding. Arm (iv) crossed the kind branch with **one** non-charge
value (`discount`), and `InvoiceLineKind` has **three** cases, so a trigger refusing waivers —
the case the migration docblock declares must stay permitted — passed the arm untouched. The
arm now iterates every case that is not `Charge`, derived from the enum so a fourth kind is
covered the day it exists, with `expect(count($nonCharge))->toBeGreaterThan(1)` as the loop's
own precondition against collapsing back to one value.

**The green-while-red, which is the finding.** With the mutated trigger in place, the arm **as
committed in `cb67c8a`**:

```
=== arm (iv)+(v) AS COMMITTED (one non-charge value), trigger still mutated to IN ('charge','waiver') ===
result passed passed 8 of 8 assertions 28
```

Eight of eight green while the database refuses every waiver line with no destination. With the
new sub-assertion, same mutation:

```
MUTATED TRIGGER (charge,waiver) result failed passed 7 of 8
 RED: it_refuses_a_charge_line_with_NULL_at_the_DATABASE_when_the_pre_check_is_bypassed
      A waiver line with no destination was refused at the database: SQLSTATE[45000]: <<Unknown error>>: 1644 A charge line must record the bank account its money is destined for.
```

7 of 8 — so arm (v) and the structural arm stayed green, and inside (iv) the charge-refused and
charge-with-destination-accepted assertions ran before the failure and passed. Restored → 8/8.

**And the instrument had to be fixed first.** The loop was first written as
`->not->toThrow(QueryException::class, "a {$kind->value} line … was refused")`. That reads the
second argument as the **expected exception message**, not as a description of the failure, so
the negated expectation passed *because* the thrown message did not contain the annotation: the
first run under this mutation reported **8/8 green** with the mutation confirmed applied in
`information_schema`. Rewritten as `try`/`catch` + `$this->fail()`. The measurement that caught
it was checking the trigger body in the database before believing the green — a mutation
reported dead while it is working is the failure mode, and the note is left in the test.

### (v) BEFORE INSERT is load-bearing — mutation: add a `_upd` sibling trigger (INSERT+UPDATE)

```
mutated: INSERT+UPDATE
result failed passed 7 of 8
 RED: it_carries_the_destination_rule_on_a_BEFORE_INSERT_trigger_and_NOTHING_else_on_t | A trigger on finance_invoice_lines was added, removed or re-timed. If that is a destination rule on UPDATE, it is the change 2026_08_29_120000 argues against: every line issued before the column existed carries NULL legitimately, and an UPDATE arm retro-refuses all of them. Failed asserting that two arrays are identical.
```

**Deviation worth naming.** The brief asked that arm (v) itself red on this mutation. It cannot,
and I did not pretend otherwise: adding an UPDATE arm changes nothing *behaviourally* today,
because `finance_invoice_lines_no_update` already denies every UPDATE, so no observable
outcome moves. The decision is therefore pinned **structurally**, by a sibling arm in the same
file that reads `information_schema.TRIGGERS` and asserts the **whole trigger set** on
`finance_invoice_lines` — not a `LIKE '%destination%'` filter, because a filter chosen to match
the thing it guards can only restate it, and a second trigger under any other name is the same
defect. Restored → 8/8 green.

---

## 4. The suite fallout, and how it was fixed

First run after the trigger landed: **291 failing assertions, 44 files** in
`tests/Feature/Finance` (`tests 876 passed 585`). Every one was a test writing a charge line
with no destination. **46 existing test files** gained a destination at the call site — the
commit touches 48 files under `tests/`, of which `tests/Pest.php` (a new shared helper) and
`InvoiceLineDestinationRequiredTest.php` (new) are not fixture repairs. An earlier draft of
this report said 49; that number was carried, not derived, and the cold review re-derived it.
The fixes take four shapes:

1. **42 `new InvoiceLineSpec(...)` sites** → `bankAccountId: testBankAccountId()`. Skipped: the
   two DTO-invariant arms in `BssPerStudentDiscountTest` (no DB write), reduction/percentage
   specs, and `MultiLineInvoiceTest`'s `'Zero'`/`'Negative'` arms, which assert a
   `BusinessRuleException` raised before any insert.
2. **28 HTTP `'lines' => [...]` entries** and **66 further charge-line literals** →
   `'bank_account_id' => testBankAccountUuid()`. Applied to arms asserting a `rules()`-level
   refusal too (currency, USD), deliberately: leaving those without a destination would let them
   pass for the *wrong* reason if the rule they aim at were ever deleted.
3. **`AllocationProposalTest`** — the four fee-item lines take **`$item->bank_account_id`**, not
   the generic test account, so the fixture's two-account structure stays meaningful.
4. **Two `postInvoice`/`postPct` helpers** (`FixedAmountReductionTest`, `PercentageReductionTest`)
   fill the destination *inside* the helper, after setup, beside the existing
   `discount_policy_id` fill — because a literal calling `testBankAccountUuid()` in an argument
   list runs before `schools` has a row (measured: 14 × FK 1452).
5. **`MultiLineInvoiceTest::SLICE2_LINES`** became `slice2Lines()`. A `const` initialiser may
   not call a function; leaving it as a const is a PHP fatal, not a test failure.

**No assertion was weakened.** Two were *strengthened*: `FinancePrefillRoundTripTest` now
asserts the stored destination equals the fee item's (the destination guard only refuses NULL —
naming the *wrong* account is a 201, and nothing else would see it), and its fixture was given
a **second bank account** so the two items point at different ones, without which that
assertion could not fail on its own axis.

`InvoiceLineDestinationTest`'s history arm (vi) now plants its pre-column row through a new
shared helper, `withoutDatabaseTrigger()` in `tests/Pest.php` — it captures the trigger's
definition from `information_schema`, drops it, runs the closure, and replays the captured
definition in a `finally`. Replayed rather than re-typed, because a hand-written
`CREATE TRIGGER` in a test file is a second copy of a migration's rule that drifts silently
into a weaker guard. **Measured for leakage**: DDL commits implicitly, so I checked the test
database after running the file — `0 invoices, 0 lines, 0 schools`. Nothing leaks, so the
suite's many global `finance_invoice_lines`/`finance_invoices` count assertions are unaffected.

---

## 5. Verification

### 5.1 New tests — `tests/Feature/Finance/InvoiceLineDestinationRequiredTest.php`, 8 arms

```
{"tool":"pest","result":"passed","tests":8,"passed":8,"assertions":28,"duration_ms":33680}
```

(i) keyed refusal + nothing written · (ii) accepted, stores the account **chosen** (second
account, so a defaulting implementation fails) · (iii) reduction with NULL accepted beside a
charge with A · (iv) raw insert refused 1644 by message, **plus** the same row with a
destination accepted **plus** EVERY non-charge kind with NULL accepted at the same layer —
`waiver` and `discount`, derived from the enum, because one value cannot tell "not a charge"
from "this one other kind" (§3 (iv-b)) ·
(v) pre-column NULL line readable at the model and over HTTP + the structural trigger-set pin ·
(vi) two bad lines at indices **0 and 2 with a good line between them**, and a keyed-object
payload asserting `lines.3` / `lines.7`.

### 5.2 Full suite

```
result failed  tests 2478  passed 2461  failed 6  errors 1  dur_s 1483
ratchet: OK — no new failures beyond the baseline (7 known-failing).
```

The 7 are the documented flakes — `ActivityLogApiTest` ×4 and `GuardianProfileTest` ×2
(activity-log cross-test pollution) and `AuthenticationTest::users are rate limited` — all in
`tests/ratchet-baseline.txt`, none touching Finance.

**One red I have to report as an instrument failure, not a result.** An earlier full-suite run
reported `1078 NEW test failures` across completely unrelated areas (ScoreEntry, TermCalendar,
Guardian, Auth). It was not a regression: the messages cluster as
`Table 'portal_testing.migrations' doesn't exist` (287) and `Table 'cache' already exists` (66)
— two pest processes on one database. A backgrounded run I believed had died was still running
while I started the second, and I also ran `pint` and `migrate:fresh` underneath it. The
numbers in §5.2 are from a clean single run started after confirming no `pest` process was
alive. I am reporting this rather than quietly re-running, because "retrying until green is
indistinguishable from fixing" unless the cause is named.

### 5.3 Migration rollback audit, by STATE

Rollback depth **re-derived** (migrations at or after mine that are `Ran` = 1; mine is the
latest on the branch):

```
re-derived rollback depth = 1
STATE up        : trigger count = 1
STATE down      : trigger count = 0
STATE re-up     : trigger count = 1
round2 down: 0
round2 re-up: 1
```

And against a **POPULATED** table — rolled back, planted a charge line with a NULL destination
through the real Action, re-upped:

```
SURVIVED re-up: id=53 bank_account_id=NULL amount=100000
READABLE via model: Historical tuition / 100000 / bankAccount=NULL
NEW NULL CHARGE REFUSED: 1644 SQLSTATE[45000]: … A charge line must record the bank account its money is destined for.
```

History survives, stays readable, and only *new* writes are refused — which is the BEFORE
INSERT decision holding at the migration level rather than only in a docblock.

### 5.4 The drive fixture actually runs

Seeded onto a throwaway `portal_drive_check` database (created, seeded, then **dropped**):

```
charge lines total: 13
charge lines with NULL destination: 0
non-charge lines: 0 (NULL dest: 0)
charge lines with NO fee_item_id (render unrecorded): 11
payment#4 states: differs, unrecorded
payment#5 states: matches, unrecorded
```

All three destination states still render on the allocation screen, produced by executing the
real Actions. This is the evidence behind §0.1.

### 5.5 Gates

```
pint --test            : passed (53 changed files)
authz-lint             : OK — 0 known
boundary-lint          : OK — 8 known temporary exceptions
money-lint             : OK — 0 known exceptions
sql-clock-lint         : OK — 4 named exceptions
citation-lint          : OK — 165 baselined keys, 182 citations
dev-namespace-lint     : OK
identifier-generation  : OK — 0 baselined
runtime-zero-lint      : OK
dependency-integrity   : OK — composer.lock unchanged
pest --group=arch      : 115/115 passed
composer analyse       : phpstan errors 0
```

`grants-convergence-lint` reported `NOT LINTED — could not resolve base '<empty>'`: it needs a
base ref that `bin/quality` supplies. This change touches no grant map, so there is nothing for
it to converge; it should still be exercised by the gate run.

`bin/quality` was **not** run — Segun runs it.

---

## 6. `git diff --stat`

```
52 files changed, 451 insertions(+), 138 deletions(-)
```

Of which the substance is:

```
.claude/skills/finance-drive/SKILL.md              | 15 ++++
app/Finance/Console/DriveFinanceStates.php         | 63 +++++++++++++++--
app/Finance/Http/Controllers/FeeScheduleController.php | 19 +++++
app/Finance/Http/Controllers/InvoiceController.php | 12 ++++
app/Finance/Http/Requests/GenerateInvoiceRequest.php | 80 +++++++++++++++++++
database/migrations/2026_08_29_120000_finance_invoice_lines_require_destination.php  (new, 110)
tests/Feature/Finance/InvoiceLineDestinationRequiredTest.php                          (new, 347)
tests/Pest.php                                     | 61 +++++++++++++++
```

The remaining 44 files are the one-line destination additions of §4.

---

## 7. What I did NOT do

- **No drive.** The generate modal's refusal path changed and the drive fixture changed; the
  brief says Segun will brief that separately. §5.4 proves the fixture *seeds* and still
  produces all three destination states; it does not prove the modal *renders* the keyed error
  next to the right line, and no test can settle that.
- **No `finance-reviewer` subagent.** The `finance-execute` skill's hand-off calls for one; the
  session's standing instruction is not to call the Agent tool unless asked. Flagging the
  conflict rather than choosing silently. **This is full-review tier** — migration, trigger,
  money destination, a fixture oracle and 46 touched test files — so a cold session on this
  report before merge is worth the time.
- **No 1644 catch widened** (§0.3), and no UPDATE arm on the trigger (§3(v)).
- **The trigger is a PRESENCE guard, not an EXISTENCE guard**, and the migration docblock now
  says so in those terms (the review's Q1). It tests `IS NULL` only, so a caller casting a null
  destination to `0` would satisfy it while naming no account; the composite foreign key
  `(bank_account_id, school_id)` is what answers 1452 there. Unreachable through either writer
  today — `finance_fee_items.bank_account_id` is NOT NULL, and the wire carries a uuid the
  FormRequest resolves or refuses — so the layering is stated rather than guarded twice.
- **The `BINARY` hole is inherited, not new.** A row inserted with `kind = 'CHARGE'` escapes
  this guard, exactly as it escapes the reduction guard. Named in the migration docblock; the
  argument that it is safe is that `kind` is only ever written through `InvoiceLineKind`.
- **`grants-convergence-lint` not exercised** here (§5.5).
