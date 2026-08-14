# `feat/u8-wire-ids-uuid`

**Base:** `origin/staging` @ `c570dc4e3f5e156edf51de2d4f8118f210f5ff75`.
**Shape:** three commits — `a8db9ac`, `79b2658`, and this one. Two files of app code changed
(`GenerateInvoiceRequest`, `FeeScheduleController`); everything else is tests, five tickets and this
report.
**Gate:** `bin/quality`, 15 steps — step count re-derived per run (`grep -c '^\s*step "' bin/quality`,
and the `[%d/15]` literal at `bin/quality:59`). Commits 1 and 2 each passed 15/15. Commit 3's gate runs
are recorded in full under "The gate on commit 3" below, including two reds and what each turned out to
be; neither was the diff.

U8 executes one ruling: **every id this platform puts on the wire is a uuid; the integer primary key
stays server-side.** Two fields did not follow it. This branch moves those two, moves the endpoint
that feeds them, and then — after a cold review — repairs what the first two commits got wrong.

**This report exists because the first two commits shipped without one, and the cold reviewer had
nothing to test claims against.** Every prior branch committed one. That absence is the first
deviation on the list below, not a footnote.

---

## What changed

### Commit 1 — `a8db9ac` — the wire becomes uuid-only

`lines.*.fee_item_id` and `lines.*.discount_policy_id` on `POST /v1/finance/invoices` accepted an
integer PRIMARY KEY from a client. Both now accept a uuid and nothing else; an integer is refused,
with no accept-either period, because no caller of either field existed under `resources/js/`.

`GenerateInvoiceRequest::lineSpecs()` resolves uuid → integer id through the scoped model, so
`InvoiceLineSpec` keeps `?int feeItemId` / `?int discountPolicyId`, `GenerateInvoice:218/221` keeps
writing integers into `finance_invoice_lines`, and neither the Action, the DTO nor the reduction
trigger changed. **No Resource gained the integer** — that is the thing the ruling exists to prevent.

`discount_policy_id` gained an EXISTENCE check it did not have as an integer. Forced, not chosen: the
wire id is no longer the stored id, so an unresolvable uuid would otherwise become a silent NULL. It
gained nothing else — `active` / `requires_approval` / same-School remain the DB `reduction_guard`'s.

18 wire call sites moved from `->id` to `->uuid`, across six test files (`PercentageReductionTest` 1,
`FixedAmountReductionTest` 1, `InvoiceReductionAuditTest` 2, `FinanceApiAcceptanceTest` 1,
`ReductionEnforcementTest` 11, `EditFeeScheduleDraftTest` 2). New file
`tests/Feature/Finance/InvoiceWireIdsTest.php`.

### Commit 2 — `79b2658` — prefill's half of the same wire

`GET /v1/finance/fee-schedules/prefill` returns `lines` for exactly one purpose —
`routes/endpoints/finance.php:88`, "prefilled charge lines for the bursar's generate form" — so its
output is a request body for the endpoint commit 1 had just made uuid-only. It was emitting the
integer. Nothing caught it: every generate test hand-built its payload, so the one body a real screen
would send was the only untested one.

`FeeScheduleController::prefill` now emits `$item->uuid`. `FinancePrefillRoundTripTest` GETs the
prefill payload and POSTs its `lines` array **verbatim** — no key added, removed or rewritten — then
asserts the stored `finance_invoice_lines.fee_item_id` integers are the ids of the items prefill
named.

### Commit 3 — this one — the cold review

Six repairs, four tickets, this report. Detailed below.

---

## Commit 3, item by item

### A — `lineSpecs()` resolved every uuid twice per request

Confirmed against the tree: `InvoiceController::generate:33` calls `assertMayReduce()` →
`hasReductionLine()` → `lineSpecs()`, and `:38` calls `lineSpecs()` again for the Action.
`generateForStudent:76` and `:83` do the same, through `GenerateInvoiceForStudentRequest` which
extends this class. Nothing memoized the result.

`lineSpecs()` is now memoized in a `private ?array $lineSpecs` field.

**The query counts, method first.** A temporary Pest probe registered `DB::listen` around one
`POST /v1/finance/invoices` and counted statements whose SQL names `finance_fee_items`,
`finance_discount_policies` or `finance_fee_schedules`. Payload: three fee-item charge lines (C=3) and
one policy-backed reduction line (D=1). Every figure is a full statement list, not a total — the probe
dumped the SQL of each.

| Tree | Total | Attributable to these two fields | Composition |
|---|---|---|---|
| `c570dc4` (before the branch) | 7 | **6** = 2C | per C: `find()` + the `$item->schedule?->status` lazy load. D contributed nothing — there was no policy query at all. |
| `79b2658` (after commit 2, before memoization) | 16 | **15** = 4C + 3D | per C: validation `where('uuid')` + schedule lazy load + `idForUuid` ×2. Per D: `doesntExist()` + `idForUuid` ×2. |
| this commit | 12 | **11** = 3C + 2D | one `idForUuid` per field instead of two. |

The remaining one query in each column is `GenerateInvoice:301`'s `is_discountable` pluck — the
Action's own, not attributable to the wire fields. The 15 figure reproduces the cold reviewer's
measurement exactly.

The comment on the memo states the second consequence, which is the one worth having: the two passes
are separated by a permission check, not a lock or a transaction, so an unmemoized resolution can
describe one set of lines to the reduction guard and a different set to the Action.

### B — an empty string became a silent null

Confirmed. `Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull` is in the global stack —
verified by reflecting the HTTP kernel's `$middleware` property, which lists it between `TrimStrings`
and Inertia's middleware; `bootstrap/app.php:47` removes nothing. So `""` is already null before any
rule in this class runs.

**Decision: `""` is an explicit "no provenance", identical to null.** Stated, tested, and commented.

Why that and not "refuse it as a malformed uuid": the refusing option is not implementable at this
layer at all. The value is null by the time a rule can look at it, so refusing `""` would mean removing
a global middleware every form on the platform depends on. And semantically it is right — an
unselected `<select>` posts `""`, charge lines legitimately carry no fee item (free-text entry is why
`finance_invoice_lines.fee_item_id` is nullable with no foreign key), and "the operator picked nothing"
and "there is nothing to pick" produce the same row by design.

The cost is named rather than assumed, and both halves are pinned by `InvoiceWireIdsTest`:

- a REDUCTION line whose policy went empty is still refused, by `finance_invoice_lines_reduction_guard`
  ("A reduction line must reference an active discount policy") — asserted on the message, not the
  status, so the arm names the layer;
- a CHARGE line whose fee item went empty is written with null provenance, which is the same row a
  hand-entered line produces.

Explicit null and an absent key both keep working; one arm asserts all three spellings together,
because the claim is that they are the same thing and a single-value arm cannot say that.

### C1 — two comments claimed more than the code does

Confirmed, and the behaviour is left exactly as it was.

`SetSchoolContext:51` reads `if (! $isSuperAdmin && ! $activeSchoolId)`, so a super_admin with no
School selected proceeds. `ActiveSchool::id()` is then null. `SchoolScope`'s null-context branch throws
only for models on `config/rbac.php`'s `fail_closed_models` — ten entries, re-read for this report, and
neither `FeeItem` nor `DiscountPolicy` is among them. Both lookups run unscoped, and a foreign row
resolves to its real integer id.

The write is refused by `GenerateInvoice`, confirmed over HTTP:

```
Failed asserting that two strings are identical.
--- Expected
+++ Actual
@@ @@
-'XX-SHOW-ME-THE-RAW-REFUSAL-XX'
+'No active School context: an invoice cannot be raised.'
```

Both comments — the fee_item_id rule's and `idForUuid`'s docblock — now state the exception and name
`GenerateInvoice` as the layer that refuses. A new `InvoiceWireIdsTest` arm pins it, and asserts that
the edge did **not** refuse, so if a `fail_closed_models` entry ever closes this the arm fails and the
comments are flagged for rewriting.

### C2 — a test comment claimed a property its fixture cannot have

Confirmed by reproduction. `efsdContext()` creates one School and `efsdDraft()` seeds one item, so the
right row and the first row are the same row. With `where('uuid', …)` removed from `idForUuid`:

- `EditFeeScheduleDraftTest`'s superseded arm → **green** (`"tests":1,"passed":1,"assertions":5`)
- `InvoiceWireIdsTest`'s resolution arm → **red**:
  `The fee item uuid on the wire resolved to the wrong integer id. … Failed asserting that 1 is identical to 2.`

The comment now says what the arm does prove (the provenance is not dropped) and points at
`InvoiceWireIdsTest` for the right-row property. `InvoiceWireIdsTest`'s own docblock, which stated this
correctly, was tightened to name the measurement rather than the reasoning.

### D — two arms passed for a new reason

Confirmed by reproducing the mutation: replacing `$policy->uuid` in both helpers with
`00000000-0000-4000-8000-000000000000`.

**12 of 17 red.** Of the five survivors, three are unaffected by the mutation because they post no
reduction line at all (`PercentageReductionTest`'s CHARGE-line-percent arm,
`FixedAmountReductionTest`'s `BILLING-TIME ONLY` static scan and its `kind defaults to charge` arm).
The two that survive *because of the defect* are exactly the two named:

- `PercentageReductionTest.php` "a percentage with no charge to reduce is rejected"
- `FixedAmountReductionTest.php` "a ZERO line is rejected for either kind"

Both are tightened to assert the failure they are named for, plus the absence of a
`discount_policy_id` validation error:

- the percentage arm asserts `message === 'A percentage reduction needs at least one charge line to reduce.'`
- the zero arm asserts `assertJsonValidationErrors('lines.0.amount_minor')` / `('lines.1.amount_minor')`

Both read the error bag with `array_key_exists('lines.N.discount_policy_id', …)` rather than a dot
path, because the bag is keyed by the literal string, dots included — a dot path traverses and reports
absence for the wrong reason.

Re-run under the same mutation, both now red:

```
-'A percentage reduction needs at least one charge line to reduce.'
+'There are validation errors'

The waiver line was refused over its discount policy rather than its zero amount, so this arm's
422 says nothing about the rule it is named for. … Failed asserting that true is false.
```

### E — `proof 14 (HTTP)` could not prove its title

Confirmed. `assertJsonValidationErrors('lines.1.discount_policy_id')` is satisfied by any unresolvable
uuid. That is forced by the branch's own design and must not be "fixed": the byte-identical property
means no HTTP response can distinguish foreign from nonexistent, because a caller who can distinguish
them has been told an id they may not see exists.

Renamed to **"a discount policy uuid this School cannot resolve is refused at the edge"**, with a
docblock that states why the isolation claim cannot live at this layer and points at `proof 14 (DB)`,
which reads the trigger's own `errorInfo[1] === 1644`.

**`proof 12 (HTTP)` had the same overclaim**, and it is repaired the same way. Its bare
`assertStatus(422)` was unambiguous while the policy id was an unvalidated integer — the only thing
that could refuse a reduction line was the DB guard. It now asserts the trigger's own message
(`'This discount policy requires per-application approval: apply it as a credit note, not an invoice
line.'`, captured by probe), so it still names the layer it always meant to.

### F — four tickets

| Ticket | What it records |
|---|---|
| `fee-item-and-discount-policy-not-fail-closed.md` | The C1 gap as a config decision: the ten-model list, the one principal, the measured HTTP refusal, and what adding them would cost the read paths. |
| `integer-primary-keys-still-on-the-wire.md` | The inventory — 5 Resources emitting an integer `id`, 4 Finance Resources emitting `invoice_id` as an integer PK beside a uuid `id`, 14 inbound rules, and the complete `term_id`/`class_level_id` round trip through `fee-schedules.tsx`. Method and limits stated; it is a floor, not a ceiling. |
| `bare-query-exception-assertions-prove-nothing.md` | 75 bare `toThrow(QueryException::class)` across 26 files (85 across 27 including the 10 that name a constraint). Framed as a work estimate, not a defect count. |
| `quality-gate-is-not-safe-to-run-from-two-trees.md` | The shared `portal_testing`, the shared artefact directory, the two fixed symlinks — framed as a harness question with four options and their costs. |

Every figure in each ticket was re-derived for it; none was copied from the review.

---

## The gate on commit 3

Recorded in full because both reds were artefacts, and a report that showed only the green run would be
hiding the more useful half.

**Run 1 — FAIL (2): larastan, test-ratchet.** 23 new test failures across `WalkingSkeletonTest`,
`WalletApplyForwardTest`, `WalletConcurrencyTest`, `WalletCreditTest`, `WalletW3ConcurrencyTest` and
`MakerCheckerSeparationTest` — not one of them a file this commit touches, and
`pest tests/Feature/Finance/` had passed 522/522 minutes earlier.

Cause, established from the artefact directory before anything was re-run: **two `bin/quality` runs
were alive at once**, launched by this session after the first was wrongly believed dead (its captured
stdout was 0 bytes and a `ps` grep found nothing). Both wrote full-size logs, so both reached the
suite:

```
pest-20260814-232332-63086.log  1.4M     ← the run that reported FAIL
pest-20260814-231232-60036.log  825.3K   ← the earlier run, still going
```

Both use `DB_DATABASE=portal_testing` and `RefreshDatabase`, so one suite was migrating and truncating
the schema the other was asserting against. Artefacts copied to the session scratchpad first, per the
capture-before-you-re-run rule.

**Run 2 — clean, nothing else running — FAIL (1): larastan.** `test-ratchet ✓`, which is what confirms
the 23 reds were the harness.

**The larastan red is real and is not the diff.** Run directly with `COMPOSER_PROCESS_TIMEOUT=0`:

```
{"tool":"phpstan","result":"passed","errors":0}
212.37s user 564.30s system 102% cpu 12:35.33 total
```

Zero errors, in 12m35s, against composer's 300-second process timeout. `build/phpstan` is a 100 MB
repo-local result cache (`phpstan.neon:24`); a warm run finishes inside the window, which is why this
step was green on commits 1 and 2 and red twice here, on a cache the concurrent runs had churned. The
step prints `✗ larastan` for a timeout and for a type error alike.

Both findings are recorded in `docs/handoff/tickets/quality-gate-is-not-safe-to-run-from-two-trees.md`.

**Run 3 — FAIL (1): larastan, and this one WAS the diff.** With the cache warm the step finished, and
immediately caught a change made during the investigation above. `idForUuid`'s `@param` reads
`Builder<covariant Model>`; an editor diagnostic called `covariant` an undefined type, and it was
"corrected" to a plain generic. Larastan disagreed, at both call sites:

```
Parameter #1 $query of static method GenerateInvoiceRequest::idForUuid() expects
Builder<Illuminate\Database\Eloquent\Model>, Builder<App\Finance\Models\FeeItem> given.
tip: Template type TModel on class Illuminate\Database\Eloquent\Builder is not covariant.
```

The marker is load-bearing: without it `Builder<FeeItem>` is not a `Builder<Model>`. Reverted, and the
docblock now says so with the measurement, so the next editor warning does not produce the same edit.
Direct re-run: `{"tool":"phpstan","result":"passed","errors":0}`.

Worth stating plainly: **the only real defect the three gate runs found was one I introduced while
investigating a red that was not a defect.**

**Run 4 — the recorded verdict.** Larastan finishes here because the cache is warm, not because the
analysis changed; runs 1 and 2 could not finish in time to report the clean result they had.

---

## Deviations

1. **No report was committed for commits 1 or 2.** This file is it, written after the fact. The cold
   review ran without one.
2. **The ruling's premise was partly false and the branch proceeded anyway.** "Every id this platform
   puts on the wire is a uuid" was untrue when commit 1 began — `FeeScheduleController:191` emitted an
   integer, and `term_id`/`class_level_id` still do. The four specific claims commit 1 was told to
   verify (four Resources, no JS caller) were all true, and the brief's own site survey named the
   controller and said report-not-fix, so it was reported and fixed in commit 2 rather than blocking.
3. **`discount_policy_id` gained an existence check** it was previously documented as deliberately not
   having. Forced by the uuid resolution; scoped to existence only.
4. **`proof 14` was split** into HTTP and DB arms in commit 1, then the HTTP arm renamed in commit 3.
   Commit 1 changed which layer refuses; the title lagged twice.
5. **`proof 12 (DB)` was found vacuous and left unfixed** (ticket
   `reduction-guard-proof-12-db-is-vacuous.md`). It inserts a `bank_account_id` key into a table with
   no such column, dying at 1054 before any trigger runs. Nothing currently proves the
   `requires_approval` branch of `finance_invoice_lines_reduction_guard` at the DB layer.
6. **One `bin/quality` red during commit 1** — `PestNegatedExpectationMessagesTest`, a custom failure
   message written under `->not->`. Real catch, fixed, commit amended before it was pushed.
7. **Commit 3's gate went red three times before the recorded green**, and the report keeps all four
   runs rather than only the last. Two reds were harness artefacts (concurrent runs; a phpstan process
   timeout). The third was a real type error, introduced by me while investigating the second — an
   editor warning about `covariant` taken at face value against Larastan's own contrary evidence.
   Reverted, and the docblock now carries the measurement.

## Could not verify

- **No screen was driven in a browser.** No client consumes prefill's `lines`: `grep -rn prefill
  resources/js/` returns only the wayfinder-generated route definition, which builds a URL and never
  reads the body. The prefill round trip is proven server-to-server only.
- **Both flags' stated purpose.** The comment saying `is_mandatory` / `is_discountable` are "for the
  form" comes from `FeeItem.php:14`, not from an existing screen. Nothing reads them today.
- **Schema facts are from `portal_testing`**, migrated from this branch — not from `information_schema`
  on the production copy.
- **The integer-PK inventory is a floor.** The Resource sweep is exhaustive (46 files, all read); the
  inbound sweep covers rule arrays in `app/` and cannot see an id read off the request without a rule,
  or one embedded in a hand-built controller payload that does not name a `*_id` key.
- **The 75 bare `QueryException` assertions were counted, not read.** Two were examined. Whether the
  other 73 are vacuous is unknown and the ticket says so.
- **Which tree produced the concurrent gate artefacts** is inferred from timestamps and a 0-byte pair,
  not from anything the gate records. No piece of the mechanism identifies its checkout.
