# U8 commit 3 — the reduction guard's refusals become field errors

**Branch:** `fix/u8-reduction-guard-field-errors`
**Base:** `origin/staging` @ `833ba97de0b3c594bc58687ee48d4284555e9e9a`
(`833ba97 Merge pull request #248 from notOluwayimika/feat/u8-wire-ids-uuid`)
**Commits:** `cb14240` (implementation) · `2aa4559` (this report, round 1) · **round 2 — three fixes
from the cold review**

---

## Round 2 — what round 1 got wrong

Three findings from the cold review, all confirmed against the repo and all fixed. **Round 1's §7 and
two of its §1 claims were wrong and are corrected in place below**; this section is the index, and
§§13–16 carry the evidence.

1. **The trigger was left almost untested and round 1 said otherwise.** The pre-check displaced every
   HTTP arm that reached the guard, leaving arms 1, 2, 3 and 5 with nothing — `proof 12 (DB)`, which
   round 1 cited as surviving coverage, has been vacuous since it was written. Repaired, and three new
   raw-insert arms added. §13.
2. **The route the running UI actually uses had no coverage at all.** `InvoiceController.php:83` could
   be deleted with the whole suite green. Five arms added over it, and the watched red redone
   **per call site**. §14.
3. **The pre-check falsified its own docblock, and the test written to pin that docblock planted the
   one policy that hid it.** Docblock corrected to the measured ordering; a second arm added that
   exercises it. §15.

Round 1 claims now known false, corrected where they appear:

- §7's *"`ReductionEnforcementTest`'s proof 12 (DB) / proof 14 (DB) still reach it by raw insert"* —
  proof 14 (DB) did; proof 12 (DB) did not.
- §4's *"every arm it refuses, the trigger refuses again one layer down"* was true of the trigger and
  **not** of anything testing it.
- §1's *"Arm 4 … `GenerateInvoice:100` refuses the request for want of a context"* — true only when
  the policy is one the pre-check accepts.

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
   > **CORRECTED IN ROUND 2 — this bullet is true only of a policy the pre-check accepts.** ARM 4b
   > planted an active, no-approval policy. With a retired one the pre-check answers first, because it
   > runs at `InvoiceController.php:39` and the context refusal at `GenerateInvoice.php:100`. Nothing
   > is written either way and arm 4 of the trigger stays unreachable, but the sentence the operator
   > gets is different and a policy's lifecycle state is now visible to a principal holding no School
   > context. Measured in §15; both cases now have an arm.
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

> **CORRECTED IN ROUND 2.** The safety argument stated here and in the method's docblock — *"every arm
> it refuses, the trigger refuses again one layer down"* — is **true of the trigger and was false of
> everything testing it**. Round 1 moved arms 1, 2, 3 and 5 off the only path that reached the guard
> and left them with no coverage, while asserting in two test comments that coverage survived. The
> claim now has a mechanism behind it: five DB arms, one per trigger branch, each bite-proved. §13.

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

> **CORRECTED IN ROUND 2.** This section originally ended by claiming the trigger's half of the
> guarantee survived in *"`ReductionEnforcementTest`'s proof 12 (DB) / proof 14 (DB), which reach it by
> raw insert"*. **False.** `proof 14 (DB)` reached the trigger; `proof 12 (DB)` never did — it passed a
> `bank_account_id` key into a table with no such column, dying at 1054 before any trigger fired. So
> when this commit moved arms 1, 2, 3 and 5 off the HTTP path, it left them with **no** test that
> reaches the guard, and arm 1 with none at all. §13 is the repair.

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

**CORRECTED IN ROUND 3 — the stat above is round 1's only.** The branch total, re-derived rather than
copied from the review (which measured 10 / 1843 / 196 before round 3 existed):

```
$ git diff --stat 833ba97..HEAD
 app/Finance/Http/Controllers/InvoiceController.php |  34 +
 .../Http/Requests/GenerateInvoiceRequest.php       | 119 +++
 .../reports/fix-u8-reduction-guard-field-errors.md | 896 +++++++++++++++++++++
 ...block-describes-a-commit-that-did-not-happen.md |  44 +
 ...are-query-exception-assertions-prove-nothing.md |  41 +-
 ...across-schools-for-a-contextless-super-admin.md |  94 +++
 ...discount-policy-status-unguarded-at-the-edge.md |  54 ++
 .../reduction-guard-proof-12-db-is-vacuous.md      | 132 ---
 .../js/components/finance/new-invoice-modal.tsx    | 108 ++-
 tests/Feature/Finance/InvoiceWireIdsTest.php       |  29 +-
 tests/Feature/Finance/ReductionEnforcementTest.php | 219 +++--
 tests/Feature/Finance/ReductionPreCheckTest.php    | 569 +++++++++++++
 12 files changed, 2127 insertions(+), 212 deletions(-)
```

Four of the twelve are markdown (this report, two new tickets, one edited ticket) and one is a
deletion. Production code across the whole branch is two files: `InvoiceController.php` (+34) and
`GenerateInvoiceRequest.php` (+119), plus `new-invoice-modal.tsx` (+108) from round 3.

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

**One run. No red runs to report.**

**CORRECTED IN ROUND 3 — this list was wrong in both rounds.** It omitted three lints that walk
`app/` recursively and therefore read both changed app files, and it was derived by reading step
names rather than the scripts. Re-derived from the scripts themselves:

| Step | Reads this branch's files? | Why — derived from the script |
| --- | --- | --- |
| [1] dependency-integrity | no | composer.lock / composer.json / vendor only |
| [2] wayfinder:generate | generates, output unchanged | reflects over controllers to regenerate `resources/js/actions/`; this branch changed no route or controller signature, and the working tree was clean after every run |
| [3] lint-changed | **yes** | Pint / Prettier / ESLint over the branch diff |
| [4] tsc-ratchet | **yes, from round 3 only** | typechecks all TS incl. the modal — blind in rounds 1–2, which touched no frontend file |
| [5] build (vite) | **yes, from round 3 only** | bundles the modal — same |
| [6] authz-lint | **yes** | `RecursiveDirectoryIterator($appDir)` (`bin/ci-authz-lint.php:55`) |
| [7] boundary-lint | **yes** | walks and filters on `app/Finance/` (`bin/ci-boundary-lint.php:127,135`) |
| [8] grants-convergence-lint | no | `RbacSeeder::grantsMap()` and migrations |
| [9] money-lint | **yes, from round 3 only** | walks `resources/js` and treats `resources/js/components/finance/` as the strict zone (`bin/ci-money-lint.php:42-43,78`) — the modal is in it |
| [10] runtime-zero-lint | **yes — omitted before** | `RecursiveDirectoryIterator($appDir)` (`bin/ci-runtime-zero-lint.php:64`) |
| [11] identifier-generation-lint | **yes — omitted before** | `RecursiveDirectoryIterator($appDir)` (`bin/ci-identifier-generation-lint.php:39`) |
| [12] sql-clock-lint | **yes — omitted before** | `SCANNED_DIRS = ['app','database','routes','bin']` (`bin/ci-sql-clock-lint.php:108`) |
| [13] arch | **yes** | Pest arch tests over `app/` |
| [14] larastan | **yes** | analyses `app/` and `tests/` |
| [15] test-ratchet | **yes** | full suite |


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

- ~~**The keyed-object payload path.**~~ **VERIFIED IN ROUND 3 — it took one request, and listing it
  as unverified twice was the wrong call.** Posting `lines` as a keyed object with entries at keys 3
  and 7 returns the error on `lines.7.discount_policy_id`, and `errors` has exactly that one key — the
  caller's key, not a 0-based position. Pinned by `keys the error by the CALLER's key, not by
  position, for a keyed-object payload`. Nothing this platform ships sends that shape (the modal
  builds a JS array), but indexing by position would have put the error on a line number the caller
  never used, which is the same defect the commit exists to remove.
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

---

# ROUND 2 — the three fixes

## 13. The trigger's arms, now actually reached

### What was true

`grep -rn "finance_invoice_lines')->insert" tests/` returned exactly two raw inserts in the whole
suite: `ReductionEnforcementTest:160` (`proof 12 (DB)`) and `:250` (`proof 14 (DB)`). Only the second
was real. `Schema::getColumnListing('finance_invoice_lines')` on the migrated test database, derived
not copied:

```
id,uuid,school_id,invoice_id,description,kind,note,amount_minor,amount_currency,fee_item_id,discount_policy_id,created_by_user_id,created_at,updated_at
```

No `bank_account_id`. `proof 12 (DB)` passed one, so MySQL rejected the statement at 1054 during
preparation and `toThrow(QueryException::class)` accepted that as the guard working.

### What was done

A single helper, `reRawLine()`, now writes the column list **once**, so an arm can get the subject of
its insert wrong but not the shape — that whole class of false green is unreachable. `reInvoiceId()`
builds the invoice to hang a line off; `reExpectGuardRefusal()` carries the shared assertion
(`errorInfo[1] === 1644` plus the arm's own `MESSAGE_TEXT`).

Five DB arms, one per trigger branch:

| Arm | Test | Trigger branch |
| --- | --- | --- |
| 1 | `proof 12b (DB)` — **new** | `discount_policy_id IS NULL` |
| 2 | `proof 12c (DB)` — **new** | `v_status <> 'active'` |
| 3 | `proof 12 (DB)` — **repaired** | `requires_approval = 1` |
| 4 | `proof 14 (DB)` — moved onto the helper | `v_school <> NEW.school_id` |
| 5 | `proof 12d (DB)` — **new** | `kind = 'charge'` with a policy |

`proof 14 (DB)` was folded onto the helper too. It was the one correct arm, so its mutation was re-run
afterwards to prove the move did not weaken it.

### Bite-proofs — every arm, raw

Method: substitute a row the guard has no reason to refuse. The insert then **succeeds**, `$code` is
null, and the arm reds. All five, verbatim:

```
##### MUTATION: proof 12 (DB) — policy swapped to active/no-approval #####
The insert was not refused by finance_invoice_lines_reduction_guard's requires_approval branch. A
different error code means the row died before the trigger ran; a null means it was WRITTEN. Either
way this arm proves nothing.
Failed asserting that null is identical to 1644.

##### MUTATION: proof 12b (DB) — null swapped for an active policy #####
The insert was not refused by finance_invoice_lines_reduction_guard's IS NULL branch. A different
error code means the row died before the trigger ran; a null means it was WRITTEN. Either way this
arm proves nothing.
Failed asserting that null is identical to 1644.

##### MUTATION: proof 12c (DB) — status swapped retired->active #####
The insert was not refused by finance_invoice_lines_reduction_guard's v_status <> 'active' branch. A
different error code means the row died before the trigger ran; a null means it was WRITTEN. Either
way this arm proves nothing.
Failed asserting that null is identical to 1644.

##### MUTATION: proof 12d (DB) — policy dropped from the charge line #####
The insert was not refused by finance_invoice_lines_reduction_guard's kind = 'charge' branch. A
different error code means the row died before the trigger ran; a null means it was WRITTEN. Either
way this arm proves nothing.
Failed asserting that null is identical to 1644.

##### MUTATION: proof 14 (DB) — foreign policy swapped for School A's own #####
The insert was not refused by finance_invoice_lines_reduction_guard's v_school <> NEW.school_id
branch. A different error code means the row died before the trigger ran; a null means it was
WRITTEN. Either way this arm proves nothing.
Failed asserting that null is identical to 1644.
```

Restored and green:

```
$ DB_DATABASE=portal_testing ./vendor/bin/pest tests/Feature/Finance/ReductionEnforcementTest.php
{"tool":"pest","result":"passed","tests":12,"passed":12,"assertions":41,"duration_ms":15408}
```

`ReductionEnforcementTest` now contains **zero** bare `toThrow(QueryException::class)` assertions
(the three remaining textual matches are comment lines explaining why the form is wrong).

### The two comments round 1 got wrong

- `ReductionEnforcementTest.php:136` — claimed *"THE DB HALF DID NOT MOVE and is proof 12 (DB) directly
  below"*. It named an arm that was vacuous at the moment the sentence was written. Corrected to say so
  and to record that the repair ships in the same commit.
- `ReductionEnforcementTest.php:184-188` — cited proof 12 (DB) **and** proof 14 (DB) as covering the
  null-policy guarantee. proof 12 (DB) was vacuous and proof 14 (DB) is the cross-School branch, a
  different arm entirely. Corrected to point at `proof 12b (DB)`, which is the arm that actually holds
  it.

### The ticket, and a deviation

`docs/handoff/tickets/reduction-guard-proof-12-db-is-vacuous.md` is **deleted** — this commit closes
what it documents: the stray column is gone, the assertion reads `errorInfo[1]`, and the bite-proof it
prescribed is above. It also asked *"check the other raw-insert arms in the same file while you are
there"*, which is what the three new arms and the helper are.

**DEVIATION — I did not delete all of it.** The ticket carried a *"Separate observation"* section
marked explicitly *"Do not fix it as part of closing the above; a migration comment correction is its
own commit"*: `2026_08_10_100000_create_finance_bank_accounts_table.php:10` claims commit 2 makes
`bank_account_id` NOT NULL on payments, fee items **and invoice lines**, and it is wrong about two of
those three (`…120000:92` makes payments nullable; `:49` puts invoice lines deliberately out of scope).
Re-verified against both migrations before moving it. Deleting the file as instructed would have
destroyed a live, unfixed finding — and one that is causally upstream of the very defect being closed
here, since that comment is the belief the broken test encoded. It is moved verbatim to
`docs/handoff/tickets/bank-accounts-migration-docblock-describes-a-commit-that-did-not-happen.md`, with
its provenance recorded. No migration was touched.

`docs/handoff/tickets/bare-query-exception-assertions-prove-nothing.md` referenced the deleted ticket
and would have been left with a dangling link. Its worked example is now marked FIXED, pointed at
`ReductionEnforcementTest` as the in-repo precedent, and its counts re-derived — **the sweep itself
stays open, 73 bare assertions across 25 files**. Its original greps counted comment lines: unfiltered
they now return 76/26/86, *higher* than the 75/26/85 recorded when it was written, despite one real
assertion having been removed, because this commit added prose explaining the defect. A discriminator
that counts documentation of a defect as instances of it drifts upward every time someone writes about
it; the ticket now carries the filtered form.

---

## 14. The live route — the two facts, verified, and the per-call-site red

### Fact 1 — the modal posts to `generateForStudent`

```
resources/js/components/finance/new-invoice-modal.tsx:133
            await axios.post(generateForStudent.url(student.uuid), {
```

### Fact 2 — it is the only invoice-generation route the running UI uses

```
$ grep -rn "generateForStudent\|InvoiceController" resources/js/ --include="*.tsx"
resources/js/components/finance/new-invoice-modal.tsx:7:    generateForStudent,
resources/js/components/finance/new-invoice-modal.tsx:8:} from '@/actions/App/Finance/Http/Controllers/InvoiceController';
resources/js/components/finance/new-invoice-modal.tsx:133:            await axios.post(generateForStudent.url(student.uuid), {
resources/js/pages/admin/finance/statement.tsx:13:import { forStudent } from '@/actions/App/Finance/Http/Controllers/InvoiceController';
```

`generate` (`POST /api/v1/finance/invoices`) exists in the wayfinder-generated
`resources/js/actions/.../InvoiceController.ts` but is imported by no hand-written file. `statement.tsx`
imports only `forStudent`, a read. `routes/endpoints/finance.php:222-225` says the same from the server
side: the student POST is *"the bursar UI's path"*, the enrollment-id POST *"stays for the harness"*.

**A third fact, found while verifying the first two.** The modal offers `waiver` and `discount` in its
kind select (`new-invoice-modal.tsx:229-232`) but sends only `description`, `amount_minor` and `kind`
(`:135-138`) — **no `discount_policy_id` at any point**. So every reduction the running UI can currently
submit is arm 1, on the route that had no coverage. That is recorded in the new arm's comment.

### Why round 1's watched red could not see it

Round 1 disabled both call sites at once, so all ten reds attributed to neither. The four existing
posts to the student route (`FinanceApiAcceptanceTest.php:177,197,212,224`) all carry payloads the
pre-check accepts, so deleting `InvoiceController.php:83` left the suite green.

### Coverage added

Five arms over `POST /v1/finance/students/{student:uuid}/invoices` — one per reachable guard arm
(1, 2, 3, 5) plus a success arm.

### Per-call-site watched red, raw

**Line 39 disabled alone** (`generate`) — 9 of 18 red, **none of them student-route**:

```
39:        // PLANT $request->assertDiscountPoliciesUsable();
83:        $request->assertDiscountPoliciesUsable();

RESULT: failed | tests 18 passed 9 failed 9
 RED: arm_1_—_a_reduction_line_with_NO_discount_policy_is_a_field_error_on_that_line
 RED: arm_1_—_the_same_refusal_for_an_EMPTY_STRING_policy__keyed_to_the_same_field
 RED: arm_2_—_a_reduction_citing_a_RETIRED_policy_is_a_field_error_on_that_line
 RED: arm_2_—_a_SUPERSEDED_policy_is_refused_the_same_way__status_is_not_a_two_value_column_
 RED: arm_3_—_a_reduction_citing_a_requires__approval_policy_is_a_field_error_on_that_line
 RED: arm_5_—_a_charge_line_carrying_a_discount_policy_is_a_field_error_on_that_line
 RED: arm_4_—_a_super__admin_with_NO_School_context__a_RETIRED_policy_is_answered_by_the_PRE_CHECK_first
 RED: names_EVERY_offending_line__not_just_the_first
 RED: loads_every_cited_policy_in_ONE_query__however_many_reduction_lines_there_are
```

**Line 83 disabled alone** (`generateForStudent`) — exactly the 4 student-route refusal arms, nothing
else:

```
39:        $request->assertDiscountPoliciesUsable();
83:        // PLANT $request->assertDiscountPoliciesUsable();

RESULT: failed | tests 18 passed 14 failed 4
 RED: student_route_—_arm_1__a_reduction_with_NO_policy_is_a_field_error_on_that_line
 RED: student_route_—_arm_2__a_reduction_citing_a_RETIRED_policy_is_a_field_error_on_that_line
 RED: student_route_—_arm_3__a_reduction_citing_a_requires__approval_policy_is_a_field_error_on_that_line
 RED: student_route_—_arm_5__a_charge_line_carrying_a_discount_policy_is_a_field_error_on_that_line
```

Every refusal arm attributes to exactly one call site. Both restored; 18/18 green.

The two success arms (`student route — an ACTIVE, no-approval policy still generates` and its
enrollment-route twin) red under neither, correctly — they detect over-refusal, not removal, and §6
already names that.

---

## 15. The ordering the commit created, measured

The pre-check runs at `InvoiceController.php:39`; the context refusal is inside the Action at
`GenerateInvoice.php:100`. So once the pre-check has something to say, it says it first. Round 1's
docblock claimed the opposite, and the arm written to pin it planted an **active** policy — the one
case where the old ordering still holds.

A `super_admin` with no School selected (the only principal who reaches this —
`SetSchoolContext.php:46`), citing a **retired** policy. Status and body, raw:

```
STATUS: 422
BODY: {"message":"There are validation errors","errors":{"lines.1.discount_policy_id":["That discount policy is no longer active, so it cannot back a new reduction. Choose a current one."]}}
```

Not `No active School context: an invoice cannot be raised.` The docblock bullet is corrected to the
measured behaviour, and names the consequence rather than burying it: **that principal can now learn a
policy's lifecycle state while holding no School context.** They can also select the School and read
the row directly, which is why it is recorded rather than closed with an `ActiveSchool::id()` early
return — but it is a behaviour change, not a no-op.

Two arms now, and the first is retitled so its qualifier is visible:

- `arm 4 — a super_admin with NO School context: an ACCEPTABLE policy leaves the context refusal first`
- `arm 4 — a super_admin with NO School context: a RETIRED policy is answered by the PRE-CHECK first`

The second asserts the error key, the exact message, **and** that `message` is *not* the Action's — a
bare 422 cannot distinguish which layer answered, which is the entire subject of the arm.

---

## 16. Round 2 — gates and evidence

### `git diff --stat`, raw

Round 2 alone:

```
$ git diff --stat 2aa4559..HEAD
 .../Http/Requests/GenerateInvoiceRequest.php       |  13 +-
 ...block-describes-a-commit-that-did-not-happen.md |  44 +++++
 ...are-query-exception-assertions-prove-nothing.md |  41 ++++-
 .../reduction-guard-proof-12-db-is-vacuous.md      | 132 -------------
 tests/Feature/Finance/ReductionEnforcementTest.php | 205 ++++++++++++++++-----
 tests/Feature/Finance/ReductionPreCheckTest.php    | 161 +++++++++++++++-
 6 files changed, 402 insertions(+), 194 deletions(-)
```

(Plus this report file, folded into the same commit.)

**No production code changed in round 2 except a docblock.** The 13 lines in
`GenerateInvoiceRequest.php` are the arm-4 comment correction; `InvoiceController.php` is byte-identical
to `cb14240`.

### Full finance suite

```
$ DB_DATABASE=portal_testing ./vendor/bin/pest tests/Feature/Finance/
{"tool":"pest","result":"passed","tests":543,"passed":543,"assertions":2456,"duration_ms":197065}
```

543, up from 534 — nine new arms (three DB, five student-route, one ordering).

### `bin/quality`

Step count re-derived for this round: `grep -c '^\s*step "' bin/quality` → **15**; `bin/quality:59`
prints `[%d/15]`. Unchanged.

Note on sequencing: `bin/lint-changed.sh:51` diffs `"$BASE"...HEAD`, so it cannot see uncommitted work
(the known ticket). Round 2 was therefore committed first and the gate run against that commit; the
run below is that commit's, and the report text was folded into the same commit afterwards so the
branch carries ONE commit on top of `2aa4559` as asked. `cb14240` and `2aa4559` were not amended.

**ONE run. PASS. No red runs to report for round 2.**

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

Steps that read this branch's files: see the corrected table in §9 — the list originally given here
was the same wrong one. Note that `lint-changed` reports 5 changed PHP files against base `833ba97`,
i.e. the whole branch, not round 2's three — it diffs the branch, so round 1's files are re-linted
every run.

Pint run separately on round 2's three PHP files before committing:

```
$ ./vendor/bin/pint --test app/Finance/Http/Requests/GenerateInvoiceRequest.php \
    tests/Feature/Finance/ReductionEnforcementTest.php tests/Feature/Finance/ReductionPreCheckTest.php
{"tool":"pint","result":"passed"}
```

---

## 17. Round 2 — what I could not verify

- **The keyed-object payload path** is still untested, unchanged from round 1 §11.
- **Still no screen driven.** Round 2 touched no frontend file. I read `new-invoice-modal.tsx` to
  verify the two facts in §14 but did not run the app; the modal's behaviour on receiving an `errors`
  key is therefore unverified. Worth naming, because `new-invoice-modal.tsx:145-149` reads
  `err.response.data?.message` and nothing else — so on today's frontend the field errors this commit
  produces would render as the generic `There are validation errors` string until the form is built to
  read `errors`. That is the next commit's work, not this one's, but it means the *user-visible*
  improvement is not yet realised anywhere.
  > **CORRECTED IN ROUND 3, and this understated it badly.** "The improvement is not yet realised" was
  > only half of it: the parent commit put the trigger's own actionable sentence in `message`, and the
  > modal displayed it. So for the four refusals this branch moved, head showed the operator **less**
  > than `833ba97` did — a regression, not a deferred benefit. Measured on both commits in §19, and
  > fixed there.
- **`bin/quality-promote` / `bin/quality-clean-db`** not run — release gate, not the per-push floor.
- **The batch load returning null for a resolved id** remains reasoned-unreachable and untested.
- **Determinism.** One `bin/quality` run per round, both PASS. Per ADR 0053 a single green cannot be
  told from a lucky one, and I did not re-run.
- **The other 25 files** in the bare-`QueryException` sweep are untouched; I re-derived the count and
  fixed the one file, nothing more.

## 18. Round 2 — deviations

1. **I did not delete all of `reduction-guard-proof-12-db-is-vacuous.md`.** Its "Separate observation"
   section — explicitly marked as a different commit's work — was moved to its own ticket rather than
   destroyed. Full reasoning in §13. This is the one instruction I did not follow literally.
2. **I touched `bare-query-exception-assertions-prove-nothing.md`,** which was not in scope, because
   deleting the parent ticket left it with a dangling reference. Its worked example is marked fixed and
   its counts re-derived; the sweep stays open.
3. **I moved `proof 14 (DB)` onto the shared helper.** It was the one correct arm and was not asked to
   change. Doing so is what makes the stray-column defect unreachable rather than merely repaired in
   the arms that had it; its own mutation was re-run afterwards and still reds, which is the evidence
   the move did not weaken it.
4. **`proof 12d (DB)` uses a positive amount** where the other DB arms use a negative one. The guard
   does not read the sign, but the arm is about a *charge* line and a fixture contradicting its own
   title is unreadable.
5. **The commit sequencing.** `bin/lint-changed.sh:51` diffs `"$BASE"...HEAD`, so the gate cannot see
   uncommitted work. Round 2 was committed, `bin/quality` run against it, and this report folded into
   that same commit — leaving ONE commit on top of `2aa4559`, as asked. `cb14240` and `2aa4559` were
   not amended.

---

# ROUND 3 — the modal, the ordering, one ticket

## 19. The modal rendered LESS than before the branch

### Measured on both commits, same request

`POST /v1/finance/students/{uuid}/invoices`, a reduction with no policy — the case the running UI
produces, since the modal sends no `discount_policy_id` at all.

**At `833ba97` (parent).** Measured in a detached worktree at that commit, with its own `vendor/` and a
fresh `composer dump-autoload` — a symlinked `vendor` maps the autoload back to the main project and
would have measured head's code, so it was copied:

```
STATUS: 422
BODY: {"message":"A reduction line must reference an active discount policy; discretionary reductions go through a credit note."}
WHAT new-invoice-modal.tsx:145-148 SETS AS formError: 'A reduction line must reference an active discount policy; discretionary reductions go through a credit note.'
```

**At `c66c711` (head before this round).**

```
STATUS: 422
BODY: {"message":"There are validation errors","errors":{"lines.1.discount_policy_id":["Select the discount policy that authorises this reduction. A reduction with no policy has to go through a credit note instead."]}}
WHAT new-invoice-modal.tsx:145-148 SETS AS formError: 'There are validation errors'
```

**Confirmed exactly as the review put it.** The parent showed the bursar an actionable sentence; head
showed them "There are validation errors" and nothing else, because `:145-148` read `message` and never
`errors`. The server got more specific and the screen got less. A regression introduced by this branch.

### The fix, and where the text lands

**A summary above the form, listing every message — not a per-line note beside the offending row.**
Stated as a decision, with the reasons:

- The offending field has **no control on the form yet**. `discount_policy_id` is the next commit's
  picker; a message pinned beside a row would point at a row with no policy input on it.
- The modal already has one error slot above the form, and this round is scoped to the error path. A
  per-line placement means threading errors into each row's render — a change to the form's structure,
  which is exactly what mixing the two commits would look like.
- **Every** message, not the first. `Object.values(errors)[0]?.[0]` is the established in-repo shape
  (`edit-pivot-modal.tsx:97-98`, `student-guardians-panel.tsx:268`) and is wrong here: the pre-check
  names every offending line in one response, so taking the first hides the rest and costs the bursar
  a round trip per bad line.

`formError: string | null` became `formErrors: string[]`; the render loops. A single message looks
exactly as it did.

**Key shape.** The row prefix is best-effort and never required. `errorLinesFrom` uses the index
segment only when it parses as a non-negative integer, so a keyed-object payload at key 7 renders
"Line 8 — …" and a non-integer key, or a key that is not `lines.*.*` at all, renders the bare message
rather than "Line NaN".

**Fallback kept.** `GenerateInvoice` still answers a plain `{"message": …}` with no `errors` key for
every `BusinessRuleException` — F7 duplicate, negative total, no discountable base, no School context,
and the no-enrollment refusal below. Those must keep rendering, and do.

### Verification of the fix

**There is no JS test runner in this repository** — `package.json` has `build`, `dev`, `format`,
`lint`, `types:check` and no vitest/jest/testing-library dependency of any kind. Adding one is out of
scope for an error-path commit, so `errorLinesFrom` has **no committed test**. Stated plainly rather
than implied.

What was done instead: the function was extracted from the file, type-erased (TS annotations have no
runtime semantics, so the erased form is behaviourally what `tsc` emits) and executed under node
against the **real measured server bodies**. Output raw:

```
--- HEAD, student route, reduction with no policy (the measured body)
    Line 2 — Select the discount policy that authorises this reduction. A reduction with no policy has to go through a credit note instead.
--- PARENT shape / BusinessRuleException fallback (no errors key)
    A reduction line must reference an active discount policy; discretionary reductions go through a credit note.
--- no-enrollment refusal (no errors key)
    This student has no active enrollment to bill.
--- four bad lines at once (the measured multi-line body)
    Line 1 — A charge line cannot carry a discount policy. Clear it, or change this line to a waiver or discount.
    Line 2 — That discount policy is no longer active, so it cannot back a new reduction. Choose a current one.
    Line 3 — That discount policy needs approval for each use. Raise it as a credit note rather than an invoice line.
    Line 4 — Select the discount policy that authorises this reduction. A reduction with no policy has to go through a credit note instead.
--- KEYED-OBJECT payload — key 7, not a small sequential index
    Line 8 — That discount policy is no longer active, so it cannot back a new reduction. Choose a current one.
--- non-integer key (defensive)
    Something about a line.
--- non-lines key entirely
    The enrollment id field is required.
--- empty body
    The invoice could not be created.
```

The first two rows are the before/after of the regression: head's body now renders the same actionable
sentence the parent did, plus the line number the parent could not give.

**This is a one-off execution, not a regression test.** Nothing in the repository will catch it if
`errorLinesFrom` is broken later. That is the honest state and it is listed in §21.

**Scope held.** No policy picker, no `discount_policy_id` field, no change to the payload the modal
sends. `git diff --stat` confirms the modal's diff is the helper, the state type, the catch branch and
the render block.

---

## 20. The ordering on the student route

### Measured before the fix

A student with **no** billable episode, posting a reduction citing a **retired** policy — bad in both
ways at once, which is the only shape that can tell the two orderings apart:

```
STATUS: 422
BODY: {"message":"There are validation errors","errors":{"lines.1.discount_policy_id":["That discount policy is no longer active, so it cannot back a new reduction. Choose a current one."]}}
```

The bursar is told to pick a different discount policy for an invoice that cannot exist.

### The fix

`assertDiscountPoliciesUsable()` moved **below** the `$enrollment === null` check in
`generateForStudent`. `generate()` is unchanged — the enrollment is not validated there until inside
`GenerateInvoice`, so its ordering cannot be fixed without moving the pre-check into the Action, and
the Action throws `BusinessRuleException`, whose handler answers a plain message with no `errors` key.
That would cost the field keys the whole branch exists to produce, so the asymmetry is taken knowingly
and recorded in the controller docblock: **the student route reports enrollment problems first, the
harness route reports policy problems first, and why.**

**Not written as a disclosure fix, and the docblock says so explicitly.** Verified independently while
writing §21's ticket: `GET /v1/finance/discount-policies` (`routes/endpoints/finance.php:134`) sits in
the `permission:finance.access` group at `routes/api.php:237` and carries no further permission, and
its resource returns `status` and `requires_approval` for every policy the principal can see. The
pre-check's messages tell an authorised caller nothing they could not already read. The reorder is
about which refusal is *useful*, not about what anyone may know.

### Bite-proof

**Both directions, as asked.** Reverting the order (pre-check back above the enrollment check) reds
exactly one arm:

```
RESULT: failed | tests 19 passed 18 failed 1
 RED: student_route_—_a_student_with_NO_enrollment_gets_the_ENROLLMENT_refusal__not_the_policy_one
       Failed asserting that two strings are identical. | --- Expected | +++ Actual |
       -'This student has no active enrollment to bill.' | +'There are validation errors'
```

The other direction — a reduction citing a retired policy on a **valid** enrollment still returns the
field error — is `student route — arm 2`, and it stayed **green under both orderings**, which is what
proves the reorder did not simply disable the pre-check on that route. Restored, 19/19 green.

The new arm asserts the message **and** that `errors` is null: a bare 422 passes under either ordering,
so the absence of the `errors` key is what actually pins which refusal won.

---

## 21. The ticket

`docs/handoff/tickets/discount-policy-catalog-reads-across-schools-for-a-contextless-super-admin.md`.

**Verified myself before writing it.** Two Schools seeded — `school#1` with two policies, `school#2`
with one — and a `super_admin` with no `school_id` in session:

```
GET /api/v1/finance/discount-policies
STATUS: 200
ROWS RETURNED: 3
SCHOOLS SEEDED: school#1 (2 policies), school#2 (1 policy)
FIELDS ON ROW 0: id,name,description,basis,value_minor,value_currency,percent,requires_approval,status
```

Three rows for a principal in no School — the whole table, across both. The ticket records the route's
permission (`finance.access` group at `routes/api.php:237`, nothing further at
`routes/endpoints/finance.php:134`), why that principal reaches it, and cross-references
`fee-item-and-discount-policy-not-fail-closed.md` (the write half of the same root cause) and
`fail-closed-catalog-batch.md` rather than restating either.

**On `RBAC_FAIL_CLOSED_MODELS`**, the ticket records two things, the second of which is sharper than
"it is overridable". `config/rbac.php:156-170` is
`explode(',', trim(env('RBAC_FAIL_CLOSED_MODELS', '')) ?: implode(',', [...ten defaults...]))` — so
(1) whether `DiscountPolicy` fails closed is a deployment fact rather than a repository fact, and any
claim about it has to name the environment it was measured in; and (2) **the env value REPLACES the
default list, it does not extend it**, so setting it to add one model silently drops all ten
transactional ones unless every one is re-listed. That is why the ticket does not recommend a
config-only fix.

---

## 22. Round 3 — gates and evidence

### `git diff --stat`, raw

```
$ git diff --stat c66c711..HEAD
 app/Finance/Http/Controllers/InvoiceController.php |  29 +++++-
 ...across-schools-for-a-contextless-super-admin.md |  94 ++++++++++++++++++
 .../js/components/finance/new-invoice-modal.tsx    | 108 ++++++++++++++++++---
 tests/Feature/Finance/ReductionPreCheckTest.php    |  71 ++++++++++++++
 4 files changed, 285 insertions(+), 17 deletions(-)
```

Branch total against `833ba97` is in the corrected §8.

### Full finance suite

```
$ DB_DATABASE=portal_testing ./vendor/bin/pest tests/Feature/Finance/
{"tool":"pest","result":"passed","tests":545,"passed":545,"assertions":2467,"duration_ms":135852}
```

545, up from 543 — two new arms (the ordering arm and the keyed-object arm).

### `bin/quality` — ONE run, PASS

Step count re-derived: `grep -c '^\s*step "' bin/quality` → **15**; `bin/quality:59` prints `[%d/15]`.
Unchanged.

```
quality gate — base 833ba97

[1/15] dependency integrity (composer.lock vs composer.json vs vendor/)
   ✓ dependency-integrity-lint
[2/15] wayfinder:generate --with-form (must match vite.config.ts formVariants)
   ✓ wayfinder:generate
[3/15] lint changed files (Pint / Prettier / ESLint, check mode)
   ✓ lint-changed
       Pint (check) on 5 changed PHP file(s)
       Prettier (check) on 1 changed file(s)
       ESLint on 1 changed file(s)
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

**One run. PASS. No red runs to report for round 3.**

**WHAT THE FRONTEND STEPS SAW, which is the point of the question.** Rounds 1 and 2 touched no file
under `resources/js`, so steps 4, 5 and 9 were structurally blind — they ran and could not have failed
on anything this branch did. Round 3 changed that, and the gate output shows it:

- **[3/15] lint-changed** — its line changed shape. Rounds 1–2 printed `Prettier: no changed frontend
  files` and `ESLint: no changed JS/TS files`; it now prints **`Prettier (check) on 1 changed file(s)`**
  and **`ESLint on 1 changed file(s)`**. Both pass. That is the first time either tool has read
  anything on this branch.
- **[4/15] tsc-ratchet** — typechecks the modal. `formErrors: string[]`, `errorLinesFrom`'s `unknown`
  narrowing and the `Object.entries` walk are all under strict TS. Ratchet not moved. (Per
  `tsc_ratchet_false_green`, the ratchet does not hard-block and is not a floor I am treating as one —
  `npx tsc --noEmit` was also run directly on the modal and reported nothing.)
- **[5/15] build (vite)** — bundles the modal, which is the step that catches what the ratchet cannot.
  Passes.
- **[9/15] money-lint** — this is the one worth naming. `bin/ci-money-lint.php:42-43` treats
  `resources/js/components/finance/` as the **strict zone**, and `new-invoice-modal.tsx` is in it. Run
  separately as well: `money-lint: OK — no money-rule violations (0 known exception(s)).` The change
  adds no money formatting and no JS arithmetic, which is why it passes rather than because it was not
  looked at.

Steps reading this branch's files: **3, 4, 5, 6, 7, 9, 10, 11, 12, 13, 14, 15** — the full table with
per-script derivation is in the corrected §9.

Individual gates run before committing:

```
$ ./vendor/bin/pint --test app/Finance/Http/Controllers/InvoiceController.php \
    tests/Feature/Finance/ReductionPreCheckTest.php
{"tool":"pint","result":"passed"}

$ npx prettier --check resources/js/components/finance/new-invoice-modal.tsx
All matched files use Prettier code style!

$ npx eslint resources/js/components/finance/new-invoice-modal.tsx
ESLint: No issues found

$ php bin/ci-money-lint.php
money-lint: OK — no money-rule violations (0 known exception(s)).
```

---

## 23. Round 3 — what I could not verify

- **`errorLinesFrom` has no committed test, and nothing in the repository will catch it if it breaks.**
  There is no JS test runner here at all — `package.json` carries `build`, `dev`, `format`, `lint`,
  `types:check` and no vitest/jest/testing-library dependency. The function was executed against the
  real measured bodies (§19) but that was a one-off, not a regression test. Adding a JS test runner is
  a decision with its own blast radius and is not this commit's to take.
- **Still no screen driven.** The modal's rendering was verified by executing its error-derivation
  function and by tsc/eslint/vite, not by opening the page. Whether the summary block *looks* right —
  four stacked messages in a `bg-destructive/10` box — is unverified.
- **The `generate` route's ordering is knowingly wrong** and left that way: a caller posting an
  enrollment-id payload with both a bad enrollment and a bad policy gets the policy error. Recorded in
  the docblock, not fixed, and no test asserts the bad ordering (asserting it would pin a behaviour I
  am saying is undesirable).
- **The ticket's scope.** Only the `super_admin`-with-no-context path was measured. Other finance
  catalog reads (`fee-schedules`, `fee-items`, `bank-accounts`) plausibly share the shape and were not
  measured; that sweep belongs to `fail-closed-catalog-batch.md`.
- **`bin/quality-promote` / `bin/quality-clean-db`** not run — release gate, not the per-push floor.
- **Determinism.** One `bin/quality` run per round, three rounds, all PASS. Per ADR 0053 a single green
  cannot be told from a lucky one.

## 24. Round 3 — deviations

1. **The parent measurement needed a full `vendor/` copy.** A symlinked `vendor` makes composer's
   autoload resolve back to the main project, so the parent worktree ran head's application code — the
   first attempt did exactly that and died in the container. `vendor/` was copied and
   `composer dump-autoload` run inside the worktree. The worktree was removed afterwards
   (`git worktree list` shows only the main checkout).
2. **`formError` became `formErrors: string[]`,** a state-shape change rather than a pure error-path
   patch. Necessary: the pre-check names every offending line in one response, and a single-string slot
   can only show one. All five existing `setFormError` call sites were converted; the two pre-submit
   validation messages and the non-422 fallback are unchanged in text.
2. **I added a committed arm for the keyed-object payload** (§4 item 3 asked only that it be verified).
   It cost one test and closes a gap listed as unverified in two consecutive reports.
3. **No test asserts the `generate` route's ordering** — see §23.
