# U8 commit 3 — the reduction guard's refusals become field errors

**Branch:** `fix/u8-reduction-guard-field-errors`
**Base:** `origin/staging` @ `833ba97de0b3c594bc58687ee48d4284555e9e9a`
(`833ba97 Merge pull request #248 from notOluwayimika/feat/u8-wire-ids-uuid`)
**Commit:** `cb14240`

---

## The brief's premise was right, and the ticket it points at was wrong

The brief asked me to test the previous ticket's claim that the reduction guard's refusals surface as
an unhandled 500, and said the claim was probably false. **It is false.** All seven payloads I drove
returned 422; not one returned 500. The measurement is below, raw.

The commit therefore kept the shape the brief specified — the defect is the *shape* of the 422, not
its code — and I corrected the ticket in the same commit rather than leaving a document that would
send the next reader after a status code that never occurs.

---

## 1. What is true — verified before building

### The trigger is defined once

```
$ grep -rn "finance_invoice_lines_reduction_guard" database/migrations/
database/migrations/2026_07_26_140002_add_discount_policy_to_finance_lines.php:51:    private const GUARD = 'finance_invoice_lines_reduction_guard';
```

One hit, and it is the `const` the `CREATE TRIGGER` at `:63` interpolates. Nothing else in
`database/migrations/` touches it. Across the whole repo (excluding `vendor/`, `node_modules/` and
`build/phpstan/` cache) the remaining hits are all comments, tests and docs — no second definition,
no drop-and-recreate.

`finance_invoice_lines` has exactly one writer in `app/`: `GenerateInvoice.php:212`
(`$invoice->lines()->create([...])`). Confirmed by grepping for `lines()->create`,
`InvoiceLine::create` and `InvoiceLine::query()->create` across `app/` and `database/`.

### The five MESSAGE_TEXT strings, raw

From `2026_07_26_140002_add_discount_policy_to_finance_lines.php:62-101`:

1. (`:72-73` — `kind <> 'charge' AND discount_policy_id IS NULL`)
   `A reduction line must reference an active discount policy; discretionary reductions go through a credit note.`
2. (`:81-82` — `v_status IS NULL OR BINARY v_status <> BINARY 'active'`)
   `The referenced discount policy is not active.`
3. (`:86-87` — `v_requires = 1`)
   `This discount policy requires per-application approval: apply it as a credit note, not an invoice line.`
4. (`:91-92` — `v_school <> NEW.school_id`)
   `The discount policy belongs to another School.`
5. (`:97-98` — `BINARY NEW.kind = BINARY 'charge' AND NEW.discount_policy_id IS NOT NULL`)
   `A charge line may not reference a discount policy.`

**Every one contains the substring `discount policy`.** That is the load-bearing fact the ticket
missed. `GenerateInvoice::isReductionGuardViolation` (`GenerateInvoice.php:478-482`) matches driver
code 1644 **and** `str_contains($e->getMessage(), 'discount policy')`, so `:271-273` converts all five
to `BusinessRuleException`, and `InvoiceController.php:47-49` answers 422. The 1644 fall-through in
`bootstrap/app.php:196-204` is real but is never reached from this route.

---

## 2. The 500-vs-422 measurement, raw

Driven over HTTP against `POST /api/v1/finance/invoices` on the base commit, before any code was
written. Status and full response body, verbatim from the run:

```
=== ARM 1 — reduction, discount_policy_id absent ===
STATUS: 422
BODY: {"message":"A reduction line must reference an active discount policy; discretionary reductions go through a credit note."}

=== ARM 1b — reduction, discount_policy_id = "" (unselected <select>) ===
STATUS: 422
BODY: {"message":"A reduction line must reference an active discount policy; discretionary reductions go through a credit note."}

=== ARM 2 — reduction, policy status = retired ===
STATUS: 422
BODY: {"message":"The referenced discount policy is not active."}

=== ARM 2b — reduction, policy status = superseded ===
STATUS: 422
BODY: {"message":"The referenced discount policy is not active."}

=== ARM 3 — reduction, policy requires_approval = 1 ===
STATUS: 422
BODY: {"message":"This discount policy requires per-application approval: apply it as a credit note, not an invoice line."}

=== ARM 4 — reduction, policy belongs to another School ===
STATUS: 422
BODY: {"message":"There are validation errors","errors":{"lines.1.discount_policy_id":["The selected lines.1.discount_policy_id is invalid."]}}

=== ARM 5 — charge line carrying discount_policy_id ===
STATUS: 422
BODY: {"message":"A charge line may not reference a discount policy."}
```

```
=== ARM 4b — super_admin, NO active School, policy uuid resolves unscoped ===
STATUS: 422
BODY: {"message":"No active School context: an invoice cannot be raised."}
```

**No 500 anywhere.** Arms 1, 2, 3 and 5 return a bare `{"message": …}` carrying the trigger's own
sentence and **no `errors` key** — that is the defect. Arm 4 never reaches the trigger at all.

---

## 3. Which arms are reachable from the edge

| Arm | Reachable? | Evidence |
| --- | --- | --- |
| 1 — reduction, null policy | **Yes** | ARM 1 / ARM 1b above. `""` is rewritten to `null` by `ConvertEmptyStringsToNull` before any rule sees it (U8 commit 1's ruling; `GenerateInvoiceRequest.php:163-172`), so an unselected `<select>` produces exactly this. |
| 2 — policy not `active` | **Yes** | ARM 2 (retired) and ARM 2b (superseded) above. The `discount_policy_id` rule checks existence only, deliberately (`GenerateInvoiceRequest.php:151-155`). |
| 3 — `requires_approval = 1` | **Yes** | ARM 3 above. |
| 4 — policy of another School | **No** | Three paths, all closed earlier — see below. |
| 5 — charge line with a policy | **Yes** | ARM 5 above. |

### Arm 4 is not reachable, and no pre-check arm was written for it

1. **With an active School.** `SchoolScope` hides School B's policy under School A's context, so the
   uuid fails the existence rule in `GenerateInvoiceRequest.php:192-196` and never reaches the
   Action. Measured: ARM 4 above, `errors: {"lines.1.discount_policy_id": ["The selected
   lines.1.discount_policy_id is invalid."]}`. This is also what `ReductionEnforcementTest`'s
   proof 14 (HTTP) already pinned.
2. **`super_admin` with NO School selected.** `FeeItem` and `DiscountPolicy` are not in
   `config/rbac.php` `fail_closed_models`, so `SchoolScope` adds no predicate and the foreign uuid
   *does* resolve. But `GenerateInvoice.php:98-102` throws `No active School context: an invoice
   cannot be raised.` at the top of `handle()`, before `DB::transaction` at `:174`. Measured:
   ARM 4b above.
3. **Any case where the policy does resolve.** It resolved under the active School; the line's
   `school_id` is the enrollment's; and `GenerateInvoice.php:110-112` has already refused a foreign
   enrollment. So `v_school <> NEW.school_id` is false by the time the insert runs.

Rather than write an arm that cannot fire, I wrote down why — in
`GenerateInvoiceRequest::assertDiscountPoliciesUsable`'s docblock, and as two test arms
(`arm 4 — … refused EARLIER than the pre-check, by SchoolScope` and `arm 4 — a super_admin with NO
School context …`) that pin the ordering. **Those two arms pass with and without the pre-check, by
construction — they prove the pre-check is NOT answering this case, not that it is.** Named here
explicitly because the brief asked.

The trigger keeps arm 4, because a raw write is bound by none of the above;
`ReductionEnforcementTest`'s proof 14 (DB) still reaches it and asserts errorInfo 1644.

---

## 4. What was built

**`app/Finance/Http/Requests/GenerateInvoiceRequest.php`** — new public method
`assertDiscountPoliciesUsable()` (+110 lines, most of it the docblock). Walks the resolved line
specs, refuses arms 1, 2, 3 and 5 with `ValidationException::withMessages()` keyed to
`lines.N.discount_policy_id`, in operator wording:

- arm 1 — `Select the discount policy that authorises this reduction. A reduction with no policy has to go through a credit note instead.`
- arm 2 — `That discount policy is no longer active, so it cannot back a new reduction. Choose a current one.`
- arm 3 — `That discount policy needs approval for each use. Raise it as a credit note rather than an invoice line.`
- arm 5 — `A charge line cannot carry a discount policy. Clear it, or change this line to a waiver or discount.`

**`app/Finance/Http/Controllers/InvoiceController.php`** — one call in each of `generate()` and
`generateForStudent()`, **after** `assertMayReduce()`.

### Decisions worth a second pair of eyes

**Placement: controller, not `rules()`.** A rule in `rules()` runs before the controller's 403, so a
principal without `finance.invoice.reduction.apply` would learn a policy's status from a refusal they
were never entitled to reach. Calling it after `assertMayReduce` keeps the 403 first. It also mirrors
`assertMayReduce`'s own stated rationale — refused before the Action's transaction, so nothing is
written.

**Error key for arm 5 is `discount_policy_id`, not `kind`.** Clearing the policy is the operator's
fix, and it is the field carrying the offending value. `kind` was the alternative.

**Original wire keys, not `0..n-1`.** `lineSpecs()` `array_values()`s its result, so a payload posting
`lines` as a keyed object would get an error keyed to an index that does not exist on the wire. The
method reads `array_keys($this->input('lines'))` and uses those. Not exercised by a test — I could not
construct a realistic client that does this, and did not want an arm asserting a payload no caller
sends. Flagged as unproven.

**A cited id that does not resolve in the batch load falls through to the trigger, with no message of
its own.** Should be unreachable (the existence rule already resolved it through the same scoped
query), and inventing a message there would be a new way to distinguish "does not exist" from "not
yours" — the byte-identical property U8 commit 1 exists to hold.

### What was NOT touched

`bootstrap/app.php` (1644 stays unmapped), the trigger, `GenerateInvoice`'s 1644 catch,
`isReductionGuardViolation`. No new table. `git diff --stat` below confirms.

---

## 5. Query count — measured, with method

Method: a `DB::listen` filtering statements whose SQL contains `finance_discount_policies`, over one
`POST /api/v1/finance/invoices` carrying **four reduction lines citing three distinct policies** plus
one charge line. Run twice — pre-check disabled, then enabled.

**Without the pre-check: 8.** **With the pre-check: 9.** The nine, raw:

```
select exists(select * from `finance_discount_policies` where `uuid` = ? and `finance_discount_policies`.`school_id` = ?) as `exists`
select exists(select * from `finance_discount_policies` where `uuid` = ? and `finance_discount_policies`.`school_id` = ?) as `exists`
select exists(select * from `finance_discount_policies` where `uuid` = ? and `finance_discount_policies`.`school_id` = ?) as `exists`
select exists(select * from `finance_discount_policies` where `uuid` = ? and `finance_discount_policies`.`school_id` = ?) as `exists`
select `id` from `finance_discount_policies` where `uuid` = ? and `finance_discount_policies`.`school_id` = ? limit 1
select `id` from `finance_discount_policies` where `uuid` = ? and `finance_discount_policies`.`school_id` = ? limit 1
select `id` from `finance_discount_policies` where `uuid` = ? and `finance_discount_policies`.`school_id` = ? limit 1
select `id` from `finance_discount_policies` where `uuid` = ? and `finance_discount_policies`.`school_id` = ? limit 1
select * from `finance_discount_policies` where `id` in (?, ?, ?) and `finance_discount_policies`.`school_id` = ?
```

Reading of the eight pre-existing statements: four `exists` from the validation rule (one per line,
unchanged by this commit), four `id` lookups from `lineSpecs()`. **That there are four and not eight
is `lineSpecs()`' memoization holding** — the controller resolves twice, once via
`assertMayReduce → hasReductionLine → lineSpecs`, once for the Action. **I added no second resolution
pass; I reused that one.**

The ninth is mine: one `whereIn`, **three** placeholders for **four** lines (ids deduped), and one
statement whatever the line count. The `school_id` term is `SchoolScope` on the model query, not a
hand-rolled predicate — the pre-check is not a second implementation of the isolation boundary.

Pinned by the test arm `loads every cited policy in ONE query, however many reduction lines there
are`, which asserts exactly one batched statement.

---

## 6. Watched reds

**Method:** both `$request->assertDiscountPoliciesUsable();` call sites commented out in
`InvoiceController.php`; suites run; call sites restored; suites re-run.

### Red — pre-check disabled — `ReductionPreCheckTest`

`8 failed, 4 passed of 12`:

```
arm 1 — a reduction line with NO discount policy is a field error on that line
  Failed to find a validation error in the response for key: 'lines.1.discount_policy_id'
  Response does not have JSON validation errors.

arm 1 — the same refusal for an EMPTY-STRING policy, keyed to the same field
  Failed to find a validation error in the response for key: 'lines.1.discount_policy_id'
  Response does not have JSON validation errors.

arm 2 — a reduction citing a RETIRED policy is a field error on that line
  Failed to find a validation error in the response for key: 'lines.1.discount_policy_id'
  Response does not have JSON validation errors.

arm 2 — a SUPERSEDED policy is refused the same way (status is not a two-value column)
  Failed to find a validation error in the response for key: 'lines.1.discount_policy_id'
  Response does not have JSON validation errors.

arm 3 — a reduction citing a requires_approval policy is a field error on that line
  Failed to find a validation error in the response for key: 'lines.1.discount_policy_id'
  Response does not have JSON validation errors.

arm 5 — a charge line carrying a discount policy is a field error on that line
  Failed to find a validation error in the response for key: 'lines.0.discount_policy_id'
  Response does not have JSON validation errors.

names EVERY offending line, not just the first
  Failed to find a validation error in the response for key: 'lines.0.discount_policy_id'
  Response does not have JSON validation errors.

loads every cited policy in ONE query, however many reduction lines there are
  The pre-check must batch-load the cited policies, not resolve one per line.
  Statements seen against finance_discount_policies: 8
  Failed asserting that actual size 0 matches expected size 1.
```

### Red — pre-check disabled — the two rewritten existing arms

`2 failed, 16 passed of 18`:

```
InvoiceWireIdsTest::still refuses a REDUCTION line whose discount policy went empty — now as a FIELD error
  Failed to find a validation error in the response for key: 'lines.1.discount_policy_id'
  Response does not have JSON validation errors.

ReductionEnforcementTest::proof 12 (HTTP) — a reduction citing a requires_approval=true policy is refused (422)
  Failed to find a validation error in the response for key: 'lines.1.discount_policy_id'
  Response does not have JSON validation errors.
```

### Green — pre-check restored

```
$ DB_DATABASE=portal_testing ./vendor/bin/pest tests/Feature/Finance/ReductionPreCheckTest.php \
    tests/Feature/Finance/InvoiceWireIdsTest.php tests/Feature/Finance/ReductionEnforcementTest.php
{"tool":"pest","result":"passed","tests":30,"passed":30,"assertions":140,"duration_ms":22773}
```

### Arms that pass BOTH ways — stated because the brief asked

Four, all deliberate, none proving the pre-check works:

- `arm 4 — a foreign School's policy is refused EARLIER than the pre-check, by SchoolScope`
- `arm 4 — a super_admin with NO School context is refused for want of a context, not by the pre-check`
  Both pin that the pre-check is *not* answering arm 4. They cannot red on its removal, by design.
- `a reduction citing an ACTIVE, no-approval policy of this School still generates the invoice`
- `a charge-only invoice with no policy anywhere still generates`
  The other direction. These red if the pre-check refuses everything — a `throw` at the top of the
  method — which is the failure mode the eight refusal arms are blind to. They cannot red on
  *removal*, only on over-refusal, which is what they are for.

---

## 7. Existing tests this commit moved

Two arms asserted the trigger's own sentence arriving over HTTP — precisely what this commit
relocates. Both were **rewritten to assert more, not less**:

- `InvoiceWireIdsTest` — `still refuses a REDUCTION line whose discount policy went empty`. Was:
  `assertStatus(422)` + `message` contains `must reference an active discount policy`. Now:
  `assertStatus(422)` + `assertJsonValidationErrors('lines.1.discount_policy_id')` + the message on
  that key + the two unchanged count assertions. Title's `— at the DB guard` corrected to
  `— now as a FIELD error`, since the old title had become false.
- `ReductionEnforcementTest` — `proof 12 (HTTP)`. Was: `assertStatus(422)` + exact trigger message.
  Now: `assertStatus(422)` + the error key + the exact pre-check message on it + the unchanged
  invoice-count assertion.

Two more had comments that would have become false and were corrected in place, with no assertion
change: `proof 13`'s PLANT note (the plant now needs both layers neutered) and `proof 7`'s
retired-policy note (the refusing layer moved).

**The byte-identical property is intact.** `InvoiceWireIdsTest`'s arm asserting that a foreign uuid
and a nonexistent one produce identical response bytes was not touched and passes. The pre-check adds
no message that could distinguish them: an id that fails to resolve in the batch load is passed
through to the trigger without comment (see §4).

---

## 8. `git diff --stat`, raw

```
$ git diff --stat 833ba97..HEAD
 app/Finance/Http/Controllers/InvoiceController.php |   7 +
 .../Http/Requests/GenerateInvoiceRequest.php       | 110 +++++++
 ...discount-policy-status-unguarded-at-the-edge.md |  54 ++++
 tests/Feature/Finance/InvoiceWireIdsTest.php       |  29 +-
 tests/Feature/Finance/ReductionEnforcementTest.php |  26 +-
 tests/Feature/Finance/ReductionPreCheckTest.php    | 345 +++++++++++++++++++++
 6 files changed, 557 insertions(+), 14 deletions(-)
```

(This report adds a seventh file in a follow-up commit.)

---

## 9. `bin/quality`, raw — ONE run, PASS

Step count re-derived, not carried: `grep -c '^\s*step "' bin/quality` → **15**, and `bin/quality:59`
prints `[%d/15]`. Consistent.

```
quality gate — base 833ba97

[1/15] dependency integrity (composer.lock vs composer.json vs vendor/)
   ✓ dependency-integrity-lint
[2/15] wayfinder:generate --with-form (must match vite.config.ts formVariants)
   ✓ wayfinder:generate
[3/15] lint changed files (Pint / Prettier / ESLint, check mode)
   ✓ lint-changed
       Pint (check) on 5 changed PHP file(s)
       Prettier: no changed frontend files
       ESLint: no changed JS/TS files
[4/15] types (tsc ratchet vs tsc-baseline)
   ✓ tsc-ratchet
[5/15] frontend build (vite — catches what the tsc ratchet structurally cannot)
   ✓ build
[6/15] authorization guard (no new commented-out checks)
   ✓ authz-lint
[7/15] boundary lint (§17.2)
   ✓ boundary-lint
[8/15] grants-convergence lint (a pre-existing permission added to grantsMap() ships a migration)
   ✓ grants-convergence-lint
[9/15] money lint (UI: money via formatNaira, no JS money math)
   ✓ money-lint
[10/15] runtime-zero lint (S7 legacy access sources)
   ✓ runtime-zero-lint
[11/15] identifier-generation bypass guard (1.4b)
   ✓ identifier-generation-lint
[12/15] sql-clock lint (no MySQL clock functions in raw SQL — two frames, one table)
   ✓ sql-clock-lint
[13/15] architecture tests (§17.1)
   ✓ arch
[14/15] static analysis (Larastan level 5 vs baseline)
   ✓ larastan
[15/15] tests (failure ratchet vs tests/ratchet-baseline.txt)
   ✓ test-ratchet

✓ quality: PASS — per-push floor. Promoting to main? run bin/quality-promote.
```

**One run. No red runs to report.** Steps that read this branch's files: **[3/15]** (Pint on the 5
changed PHP files — the report is the 6th, markdown), **[6/15]**, **[7/15]**, **[13/15]**,
**[14/15]**, **[15/15]**.

Gates also run individually before the commit, all clean:

```
$ ./vendor/bin/pint --test <5 explicit files>
{"tool":"pint","result":"passed"}

$ php bin/ci-authz-lint.php
authz-lint: OK — no new commented-out authorization checks (0 known).

$ php bin/ci-boundary-lint.php
boundary-lint: OK — no new boundary violations (4 known temporary exceptions).

$ DB_DATABASE=portal_testing ./vendor/bin/pest --group=arch
{"tool":"pest","result":"passed","tests":32,"passed":32,"assertions":181,"duration_ms":6127}

$ composer analyse
{"tool":"phpstan","result":"passed","errors":0}
```

Full finance feature suite after the change:

```
$ DB_DATABASE=portal_testing ./vendor/bin/pest tests/Feature/Finance/
```

534 tests. Before the two rewrites: 532 passed, 2 failed — the two arms named in §7, both failing on
the trigger message they were written to assert. After the rewrites: green (and the whole suite is
green inside `bin/quality` step 15).

---

## 10. The ticket correction

`docs/handoff/tickets/discount-policy-status-unguarded-at-the-edge.md` now opens with a **CLOSED**
notice and a *Correction — measured, not reasoned* section carrying the table from §2, the
explanation of why the 500 never happened (the `discount policy` substring in all five messages), and
the arm-4 unreachability finding. The original text is preserved below it, marked as containing the
corrected claim. I also flagged that the ticket's proposed remedy (2) — "a pre-check in
`GenerateInvoice` that resolves the policy and refuses through `BusinessRuleException`" — describes
machinery that **already existed** at `GenerateInvoice.php:271-273`.

---

## 11. What I could not verify

- **The keyed-object payload path.** The pre-check reads the original wire keys so
  `{"lines": {"5": {…}}}` would be keyed `lines.5.discount_policy_id`. No test covers it; I could not
  identify a client that posts that shape and did not want an arm asserting a payload nobody sends.
- **No screen was driven.** This commit touches no frontend file — the form it anticipates does not
  exist yet (that is what makes arm 1 "the likeliest mistake the *coming* form can make"). There was
  nothing to drive, so `finance-drive` was not exercised.
- **`bin/quality-promote` / `bin/quality-clean-db` were not run.** Those are the release gate
  (`staging → main`), not the per-push floor, and this is a branch commit.
- **The batch load returning null for a resolved id** is treated as unreachable and falls through to
  the trigger. I reason it cannot happen (same scoped query resolved the id moments earlier) but did
  not construct a case that produces it, so that branch is untested.
- **Determinism.** One `bin/quality` run, PASS. Per ADR 0053 a single green cannot be distinguished
  from a lucky one; I did not re-run, because re-running until green is the failure mode that note
  exists to name.

---

## 12. Deviations from the brief

1. **The brief said "cover the arms reachable from the edge" and listed arms 2, 3 and 5 as reachable
   and arm 4 as doubtful.** Confirmed exactly: 1, 2, 3, 5 reachable; 4 not. No deviation, but the
   brief also said *"If I am wrong, say so and stop — the commit changes shape."* about the 500
   claim. The 500 claim was the brief's own hypothesis that the ticket was wrong, and it was right,
   so the commit kept its shape. I did not stop.
2. **I rewrote two existing test arms** the brief did not name. They asserted the trigger's message
   arriving over HTTP and could not survive this commit. Rewritten to assert strictly more (§7), not
   relaxed.
3. **I corrected comments in two further test arms** (`proof 13`, `proof 7`) that had become false.
   No assertion changed.
4. **Arm 2 got two test arms, not one** (retired and superseded). The trigger tests `<> 'active'`,
   not `= 'retired'`, and a single-status arm would not see a pre-check that only checked one.
