# Database backstop reachability

Which trigger/CHECK backstops in this schema can a **user actually reach** through an HTTP route with
no application guard in front — so the DB rejection surfaces as a dead-end HTTP 500 (since `a4d12cc`,
DB errors render 500) and pages someone for a button click?

Audited route-outward (start at the route, ask what can arrive), base `origin/staging` @ `6d6686a`.

**Reachability values** — `UNREACHABLE` (no route writes it, or violating input can't be supplied →
500 correct) · `GUARDED` (an app check refuses violating input first → 500 means the guard failed) ·
`REACHABLE` (a user supplies violating input and nothing refuses it before the DB → **bug**).

## Counts (Part 0)

| # | Count | Prediction |
|---|---|---|
| P1 migration files with `SIGNAL SQLSTATE` | 18 | 18 ✓ |
| P2 total `SIGNAL SQLSTATE` statements | 53 | 53 ✓ |
| P3 `ADD CONSTRAINT … CHECK` (multi-line) | 11 | 12 (−1, tolerance) |
| P3b inline/builder CHECK | 4 (15 total CHECK − 11) | — |
| P4 `app/` catches of 1644/3819 | 1 (`GenerateInvoice`) | 1 ✓ |

Single-line grep first gave P3 = 9 — several `ADD CONSTRAINT … CHECK` span lines; the true count is 11.

**These five numbers are frozen at base `6d6686a` and are counts of DECLARATIONS IN THE SOURCE.** They
are not the schema and they have not been recomputed since. Do not reconcile them against the object
counts under Result, which are read from `information_schema` on a migrated database — a migration
that loops one declaration over ten columns makes the two diverge by construction, and that is exactly
what `2026_08_01_120000` does.

## Result

46 backstops audited here: **40 SIGNAL trigger objects in 35 rows, plus 6 CHECK constraints in 6
rows**. Rows and objects differ for three reasons and always have — a `_bi`/`_bu` pair is one row,
`finance_allocation_not_over_invoice_total` is one trigger written as two rows because its two arms
classify differently, and `guardian_student_same_school_bi/bu` is one row for two objects. That last
one is why the previous total read 40 rather than 41; it was off by one before this branch touched
it.

Classification is stated **by row**, because two of the three cases above cannot be classified per
object at all (one trigger, two verdicts). 41 rows: **REACHABLE = 2** · REACHABLE→guarded = 1 ·
GUARDED = 10 · UNREACHABLE = 28 · UNKNOWN = 0.

> **This table has never been complete, and it is less complete than it looks.** A fully migrated
> database holds **49 triggers and 19 CHECK constraints — 68 database-level backstops**, measured on
> `portal_testing` after `2026_08_17_100000`. The 46 above are what this audit ever covered. The 22 it
> does not are named in "What is missing from this table" below. Nothing here derives itself from
> `information_schema`, so nothing keeps it in step with the schema; treat it as a dated reading, not
> as an inventory.
>
> **Changed by `2026_08_17_100000_maker_checker_and_payment_origin_as_triggers`.** Five rules that
> were `CHECK` constraints are now `BEFORE INSERT` / `BEFORE UPDATE` trigger pairs, because production
> is MySQL 5.7.23 and enforces `CHECK` only from 8.0.16 — so every `CHECK` row in this table was
> enforced on the dev machine and ignored on the server that holds the money. Those five rows have
> **moved into the Triggers section and been re-keyed to the object names that actually exist**
> (`…_bi` / `…_bu`); the constraint names they used to carry name nothing in the database now. Their
> reachability and evidence are unchanged. One row per pair, following the
> `guardian_student_same_school_bi/bu` precedent, so the row count moves by five and the object count
> by ten. Full audit: [check-constraints-on-mysql-5-7.md](check-constraints-on-mysql-5-7.md).

### What is missing from this table

Named rather than added: filling these in is a fresh reachability audit of each one, which is a
different piece of work from correcting a count.

**Nine trigger objects.** Five predate this branch — `finance_bank_accounts_identity_immutable`,
`finance_opening_balance_batches_no_unpost`, `finance_opening_balance_batches_no_delete_posted`,
`finance_opening_balance_rows_no_update_when_posted`,
`finance_opening_balance_rows_no_delete_when_posted` — and four arrived with it:
`finance_opening_balance_batches_maker_ne_checker_bi` / `_bu` (the sixth approval table, whose columns
are `submitted_by_user_id` / `decided_by_user_id`) and `finance_payments_origin_pairing_bi` / `_bu`
(the origin/bank-account pairing, which replaced two CHECKs — `finance_payments_origin_shape` and
`finance_payments_bank_account_origin_shape` — that were likewise never in this table).

**Thirteen CHECK constraints**, all of them the currency-shape rules
(`CHECK (col IS NULL OR col COLLATE utf8mb4_bin REGEXP '^[A-Z]{3}$')`). They are deliberately NOT
converted to triggers — the columns are `CHAR(3)`, so length is enforced by the column type on 5.7
regardless of version, and only the case-and-alphabet half is lost. Reasoning in the audit's Group C.

## Triggers (35 rows, 40 objects)

| Name | Table | Forbids | Code | Reachability | Evidence |
|---|---|---|---|---|---|
| activity_log_no_delete | activity_log | DELETE a log row | 1644 | UNREACHABLE | No route DELETEs activity_log. |
| activity_log_no_update | activity_log | UPDATE a log row | 1644 | UNREACHABLE | No route UPDATEs activity_log. |
| finance_credit_notes_no_delete | finance_credit_notes | DELETE credit note | 1644 | UNREACHABLE | No finance DELETE route (`routes/endpoints/finance.php`). |
| finance_credit_notes_insert_guard | finance_credit_notes | currency≠invoice; approved-vs-void; ceiling | 1644 | **REACHABLE** | Currency branch: `currency` is user-supplied (`SubmitCreditNoteRequest.php:32`), no app check it matches the invoice; `CreditNoteController.php:37-39` builds Money from it. B-2 below = 500. (Approved-vs-void + ceiling branches unreachable: `SubmitCreditNote.php:61` inserts status=Submitted.) |
| finance_credit_notes_update_guard | finance_credit_notes | mutate money/identity on UPDATE | 1644 | UNREACHABLE | Only `ApproveCreditNote`/`RejectCreditNote` UPDATE, status/decided_* only. |
| finance_credit_notes_maker_ne_checker_bi/bu | finance_credit_notes | submitter == decider (INSERT and UPDATE) | 1644 | GUARDED | `ApproveCreditNote.php:41` refuses submitter==checker; Policy 403 too. Was the CHECK `finance_credit_notes_maker_ne_checker` until 2026_08_17_100000. |
| finance_discount_policies_no_delete | finance_discount_policies | DELETE policy | 1644 | UNREACHABLE | No route DELETEs policies (edit/remove are amend/retire changes). |
| finance_discount_policies_update_guard | finance_discount_policies | mutate policy terms | 1644 | UNREACHABLE | Only `ApproveDiscountPolicyChange` writes; status-only moves; no route edits terms. |
| finance_discount_policy_changes_no_delete | finance_discount_policy_changes | DELETE change | 1644 | UNREACHABLE | No DELETE route. |
| finance_discount_policy_changes_update_guard | finance_discount_policy_changes | mutate frozen change columns | 1644 | UNREACHABLE | Only Approve/Reject UPDATE, status/decided_* only. |
| finance_discount_policy_changes_maker_ne_checker_bi/bu | finance_discount_policy_changes | submitter == decider (INSERT and UPDATE) | 1644 | GUARDED | `ApproveDiscountPolicyChange.php:31` refuses submitter==checker; Policy 403. Was a CHECK of the same stem until 2026_08_17_100000. |
| finance_fee_items_parent_state_guard_ins | finance_fee_items | INSERT item onto non-draft schedule | 1644 | UNREACHABLE | Items inserted only into a fresh draft by `CreateFeeSchedule.php:47-63`; no route inserts onto active/pending. |
| finance_fee_items_parent_state_guard_upd | finance_fee_items | UPDATE item on non-draft schedule | 1644 | UNREACHABLE | No route UPDATEs items. |
| finance_fee_items_parent_state_guard_del | finance_fee_items | DELETE item on non-draft schedule | 1644 | UNREACHABLE | No route DELETEs items. |
| finance_fee_schedule_changes_no_delete | finance_fee_schedule_changes | DELETE change | 1644 | UNREACHABLE | No DELETE route. |
| finance_fee_schedule_changes_update_guard | finance_fee_schedule_changes | mutate frozen change columns | 1644 | UNREACHABLE | Only Approve/Reject UPDATE. |
| finance_fee_schedule_changes_maker_ne_checker_bi/bu | finance_fee_schedule_changes | submitter == decider (INSERT and UPDATE) | 1644 | GUARDED | `ApproveFeeScheduleChange.php:30` refuses submitter==checker; Policy 403. Was a CHECK of the same stem until 2026_08_17_100000. |
| finance_invoice_lines_no_delete | finance_invoice_lines | DELETE a line | 1644 | UNREACHABLE | No route DELETEs lines. |
| finance_invoice_lines_no_update | finance_invoice_lines | UPDATE a line | 1644 | UNREACHABLE | No route UPDATEs lines. |
| finance_invoice_lines_reduction_guard | finance_invoice_lines | reduction line w/o active/same-school/no-approval policy; charge w/ policy | 1644 | GUARDED | `GenerateInvoice.php:55` catches 1644 and re-throws BusinessRuleException → 422 (the one place that catches 1644). |
| finance_invoices_no_delete | finance_invoices | DELETE invoice | 1644 | UNREACHABLE | No DELETE route. |
| finance_invoices_total_immutable | finance_invoices | change total on UPDATE | 1644 | UNREACHABLE | Void UPDATEs status only (`ApproveVoidRequest.php:70`); no route writes total. |
| finance_ledger_transactions_no_delete | finance_ledger_transactions | DELETE ledger row | 1644 | UNREACHABLE | No route mutates the ledger. |
| finance_ledger_transactions_no_update | finance_ledger_transactions | UPDATE ledger row | 1644 | UNREACHABLE | No route mutates the ledger. |
| finance_allocation_not_over_invoice_total (ceiling arm) | finance_payment_allocations | allocation > invoice total | 1644 | GUARDED | Amount capped before insert: `RecordPayment.php:84` `min(amount, outstanding)`; `GenerateInvoice.php:416` `min(remaining, unallocated)`, `:245` `min(credit, total)`. |
| finance_allocation_not_over_invoice_total (currency arm) | finance_payment_allocations | allocation currency ≠ invoice | 1644 | REACHABLE→guarded (647d419+1) | **Was misclassified GUARDED — the compound row was classified by its loud ceiling arm.** The currency arm had NO app guard: a "USD" payment reached the DB unchecked via the account-payment route unconditionally (no allocation row → this trigger structurally unreachable there → silent balance corruption), and via the invoice route against a fully-allocated invoice (`allocateKobo === 0` → no allocation row → same silent path). Now guarded three layers: `RecordPaymentRequest`/`RecordAccountPaymentRequest` `Rule::in([DEFAULT_CURRENCY])`, currency checks in `RecordPayment`/`RecordAccountPayment`, and a currency invariant in `SubledgerPoster::applyToAccount`. **Lesson: a compound trigger written as one audit row gets classified by its loudest arm — every other multi-arm row in this table should be re-read on that suspicion (open check, not done here).** |
| finance_payment_allocations_no_delete | finance_payment_allocations | DELETE allocation | 1644 | UNREACHABLE | No route DELETEs allocations. |
| finance_payment_allocations_no_update | finance_payment_allocations | UPDATE allocation | 1644 | UNREACHABLE | No route UPDATEs allocations. |
| finance_payments_no_delete | finance_payments | DELETE payment | 1644 | UNREACHABLE | No route DELETEs payments. |
| finance_payments_no_update | finance_payments | UPDATE payment | 1644 | UNREACHABLE | No route UPDATEs payments. |
| finance_void_requests_no_delete | finance_void_requests | DELETE void request | 1644 | UNREACHABLE | No DELETE route. |
| finance_void_requests_update_guard | finance_void_requests | mutate frozen columns | 1644 | UNREACHABLE | Only Approve/Reject UPDATE, status/decided_* only. |
| finance_void_requests_maker_ne_checker_bi/bu | finance_void_requests | submitter == decider (INSERT and UPDATE) | 1644 | GUARDED | `ApproveVoidRequest.php:46` refuses submitter==checker; Policy 403. Was a CHECK of the same stem until 2026_08_17_100000. |
| guardian_student_same_school_bi/bu | guardian_student | attach/re-link guardian & student of different schools | 1644 | UNREACHABLE | Attach guarded (`GuardianService` resolveExistingGuardianForAttachment creates guardian in target school on mismatch); `updatePivot` requires an EXISTING pivot (`GuardianService.php:399` — already same-school; ValidationException if absent); DELETE fires no same-school trigger. B-2 below = 404, not 500. (Routes carry `withoutScopedBindings()` — a separate cross-school-IDOR concern, but not a path to THIS trigger.) |
| subject_result_statuses_maker_ne_checker_bi/bu | subject_result_statuses | submitter == decider (INSERT and UPDATE) | 1644 | GUARDED | `SubjectResultPolicy.php:40,45` isNotTheMaker on approve/reject. Was a CHECK of the same stem until 2026_08_17_100000. |

## CHECK constraints (6)

> **Was 11. Five moved out to the Triggers section** — see the note under Result. The six that remain
> are still `CHECK` constraints and so are still **unenforced on production**, which runs MySQL
> 5.7.23. Every `3819` in the Code column below is therefore a code this server produces and
> production does not: on production these six rules are held by the application layer named in the
> Evidence column and by nothing else. That is a deliberate decision, not an oversight — the reasoning
> per rule is in [check-constraints-on-mysql-5-7.md](check-constraints-on-mysql-5-7.md), Groups C, D
> and E. The thirteen currency-shape constraints that share that decision were never in this table
> either; see "What is missing from this table" above.

| Name | Table | Forbids | Code | Reachability | Evidence |
|---|---|---|---|---|---|
| finance_discount_policies_basis_exclusive | finance_discount_policies | amount and percent both / neither | 3819 | UNREACHABLE | Only `ApproveDiscountPolicyChange` inserts, from a change row already gated by `..._changes_terms_shape`; a bad-terms change can't exist to approve. |
| finance_discount_policy_changes_target_shape | finance_discount_policy_changes | create-with-target / amend|retire-without-target | 3819 | GUARDED | `SubmitDiscountPolicyChangeRequest.php:27` `required_unless:kind,create`; controller resolves `target` only when kind≠create. |
| finance_discount_policy_changes_terms_shape | finance_discount_policy_changes | basis=amount with percent (or basis=percent with value_minor) | 3819 | **REACHABLE** | `SubmitDiscountPolicyChangeRequest.php:33,35` validate `required_if` but do NOT prohibit `percent` when amount / `value_minor` when percent; `terms()` passes both through. B-2 below = 500. |
| scores_range | scores | score outside 0–100 | 3819 | UNREACHABLE | `UpsertScoreRequest` marks `score` prohibited, accepts `score_percent` 0–100, converts server-side (`CurriculumSubjectController:325-350`). |
| student_curricula_promoted_requires_link | student_curricula | status=promoted with null promoted_to_id | 3819 | GUARDED | Status routes forbid 'promoted' (`UpdateStudentCurriculumStatusRequest.php:28` `Rule::in([active,repeated,withdrawn])`; `StudentController.php:136` `Rule::enum` excludes PROMOTED); promote action + jobs set both atomically (`StudentCurriculumController.php:202-205`). |
| terms_end_after_start_check | terms | end_date ≤ start_date | 3819 | GUARDED | `TermController.php` `validatedTerm`: `end_date => ['required','date','after:start_date']`. |

## The two REACHABLE findings — guards added (Part 2)

Both get an application guard **in front** of the untouched constraint: a house `BusinessRuleException`
(422 with an actionable sentence). The constraint stays as the backstop.

1. **`finance_discount_policy_changes_terms_shape`** — a discount-policy change with `basis=amount` and
   a stray `percent` (or `basis=percent` with a stray `value_minor`) validated fine and hit 3819 → 500.
   Guard: `SubmitDiscountPolicyChangeRequest` now prohibits the cross terms.
2. **`finance_credit_notes_insert_guard` (currency branch)** — a credit note with a currency ≠ the
   invoice's hit 1644 → 500. Guard: `SubmitCreditNote` refuses a currency mismatch before the insert.

### Bite-proofs

- **B-2 watched-red (before guards):**
  - discount terms_shape — `POST /v1/finance/discount-policy-changes {kind:create, basis:amount, value_minor:100000, value_currency:NGN, percent:50}` → **500**.
  - credit-note currency — `POST /v1/finance/invoices/{uuid}/credit-notes {amount_minor:1000, currency:USD}` on an NGN invoice → **500**.
  - guardian same-school — `PUT /students/{B}/guardians/{A}` cross-school → **404** (not 500) → confirms UNREACHABLE.
- **B-1 spot-checks** (audit is real):
  - UNREACHABLE `scores_range` — to violate you'd send `score:150`; refused at `UpsertScoreRequest` (`score` is `prohibited`; only `score_percent` 0–100 accepted).
  - GUARDED `terms_end_after_start_check` — to violate you'd send `end_date` before `start_date`; refused by `after:start_date` in `TermController::validatedTerm`.
- **B-3 / B-4** — after each guard: same request returns 422 with a readable message AND the constraint
  is still present in `information_schema`; removing the guard brings the 500 back. See the commit body.

## Addendum (faa868e+1) — a second population: value-object invariants

The audit above is scoped to **database** backstops (triggers + CHECKs). A second population produces
the identical failure — a dead-end HTTP 500 on ordinary input — and is **not** in the schema: value
objects that throw `InvalidArgumentException` (no `renderable()` in `bootstrap/app.php` → default 500).

Found instance: `App\Support\Money`'s constructor rejects a non-`/^[A-Z]{3}$/` currency. Three finance
requests accepted the currency as any 3 chars (`size:3`) and built Money from it in the controller —
so `currency:"ngn"` (right currency, wrong case) 500'd *before* even `SubmitCreditNote`'s currency
guard could run. Fixed by mirroring the invariant one layer up:
`'currency' => [...,'size:3','regex:/^[A-Z]{3}$/']` on `SubmitCreditNoteRequest`,
`RecordPaymentRequest`, `RecordAccountPaymentRequest`. The constructor stays as the backstop; input
never reaches it — the same argument this audit made about triggers. No `renderable()` was added for
`InvalidArgumentException` (that would hide real programming faults), and the input is refused, never
uppercased (`"usd"` is not a typo to repair).

**NOT swept:** this value-object population has not been audited the way the DB backstops were. Two
further sites build Money from unvalidated request currency and carry the same 500 —
`GenerateInvoiceRequest` (`lines.*.currency`) and `FeeScheduleRequest` (`items.*.currency`) — reported
here, deliberately left for their own commit. On the record, not in someone's head.

### Addendum 2 (f293358+1) — the value-object population has a SECOND failure mode

The three nested/nested-ish currency fields the first addendum reported as un-fixed are now closed
(`lines.*.currency`, `items.*.currency`, `value_currency`) with the same regex. Two of them 500'd
before a write. The third — `value_currency` on a discount-policy change — revealed a **second, worse
failure mode of the value-object population**: a shape `Money` would reject can be **persisted** when
the column is not cast through `Money`, and then fails far from its cause.

`DiscountPolicy.value_currency` / `DiscountPolicyChange.value_currency` are raw strings (neither model
casts them through `Money`). So `value_currency:"ngn"` passed validation, passed `terms_shape` (which
only tests NULL/NOT NULL), and was written — and copied into `finance_discount_policies` at approval —
denominated in a currency no `Money` will ever equal (`Money::plus/minus/equals` throw on mismatch).
Both rows are append-only (undeletable). A 500 is loud and stops the write; this was silent and
completed it — worse. The regex refuses it at the edge (proof asserts no row is created), but the cast
gap remains: **adding a `MoneyCast` to `DiscountPolicy`/`DiscountPolicyChange` is deliberately NOT done
here** (its own migration questions; the discount slice's call).

**The set is closed at two (1016f08+1).** It *was* enumerable and now is enumerated: there are exactly
**10** `*_currency` columns in the schema — `amount_currency` on `finance_invoice_lines`,
`finance_ledger_transactions`, `finance_payments`, `finance_payment_allocations`, `finance_credit_notes`,
`finance_fee_items`; `total_currency` on `finance_invoices`; `balance_currency` on
`finance_student_accounts` (8 cast through `Money`); and `value_currency` on `finance_discount_policies`
and `finance_discount_policy_changes` (2 **not** cast). `Money` is the **only** constructor-throwing value
object in the codebase (`grep -rl InvalidArgumentException app/Support app/Casts` → `Money.php`,
`MoneyCast.php`, nothing else). So the population is not open-ended, and the two `value_currency` columns
are the whole of the uncast half — not "found instances among unknown others".

**A bad `value_currency` already stored is not just undeletable — it is UNFIXABLE.** Both tables carry a
`BEFORE UPDATE` immutability guard that names `value_currency` (`NOT (NEW.value_currency <=> OLD.value_currency)
→ SIGNAL`), on top of the `BEFORE DELETE` no-delete trigger. So a stored `'ngn'` can be neither DELETEd nor
UPDATEd — not by the app, not by an operator at a MySQL prompt. Correcting one needs a migration that drops
the guard, updates, and recreates it verbatim. That is why the edge regex (1016f08) mattered more than the
500 symptom suggested: for `value_currency` the DB never threw, and once written the value is permanent.
The 8 **cast** columns carry the mirror risk — `MoneyCast` constructs a `Money` on **read**, so a bad
currency there is a permanent read-time 500 on every page touching the row until repaired.

**Sweep — 2026-07-31, clean.** All 10 columns checked for a value not matching `^[A-Z]{3}$`
(case-sensitive via `COLLATE utf8mb4_bin` — a plain `NOT REGEXP BINARY` errors 3995 on utf8mb4, and a
case-insensitive match would false-pass `'ngn'`; the regex was proven to bite: `'ngn'`→1, `'NGN'`→0).
Environment: **`brookstone_portal_db` (local dev)** — **0 bad rows** across all 10 columns. **NOT swept:
staging and production** — not reachable from the dev shell; they must be swept by someone with DB access
before the door is called closed on real data. A dated clean sweep is the evidence; the dev result does not
speak for the other two environments.

**Production sweep — 2026-08-01. Finance is EMPTY.** Database `portaa10_portal`: all ten `*_currency`
columns returned `bad = 0` **and `total = 0`**. There is nothing to repair — but **clean here means empty,
not correct**: a zero over a zero denominator says nothing about whether the writers are right, only that
no writer has run. Academics is live on that database (student_curricula and model_has_roles carry real
rows); Finance has not been used. This closes the sweep note only in the sense that there is no legacy data
to fix before adding a DB constraint — which is why the currency-shape CHECK (below) could land free, with
no backfill and no repair migration.

**The sweep tested SHAPE, not correctness — and would not have caught the payment corruption (647d419+1).**
`balance_currency` on `finance_student_accounts` is written on INSERT and **never on UPDATE** in
`SubledgerPoster::applyToAccount` (its `ON DUPLICATE KEY` omits the column), so a `'USD'` payment used to
add its kobo straight into an NGN `balance_minor` and leave `balance_currency` reading `'NGN'` — a balance
whose label does not reflect its contents. Every one of those values matches `^[A-Z]{3}$`, so the sweep's
zero was a zero about *shape*, not about a balance being denominated in what it claims. A correctness sweep
(does each account's `balance_currency` match the currency of the ledger rows that built it?) is a different
query and has not been run — noted here so "clean sweep" is not read as more than it proved.

**MoneyCast on the discount models — REVERSED (2026-08-01).** The earlier note here said the sweep *gated*
a `MoneyCast` on `DiscountPolicy` / `DiscountPolicyChange` `value_currency` — "eventually, not next, not
before the sweep is clean". The sweep is now clean (empty), so the gate is satisfied — and the answer has
changed to **no, and not later either, until a consumer exists.** Recording it as a reversal of my own
earlier steer, not as if I had always said it. Three reasons:

1. **Nothing consumes a Money-typed discount value.** `DiscountPolicyResource` /
   `DiscountPolicyChangeResource` emit `value_minor` + `value_currency` as raw scalars, and `GenerateInvoice`
   never reads a policy's value — discount lines arrive as negative line amounts from the wire and the
   policy id is only *recorded* on the line. A cast nobody reads is wallpaper.
2. **`MoneyCast`'s Case 3 (exactly one of the pair NULL) is already impossible** — the `basis` CHECK
   (`2026_07_26_140000:57-60`) forces `amount → both NOT NULL` and `percent → both NULL`. The cast would add
   nothing the DB is not already saying.
3. **It would add a 500 path.** `MoneyCast::get()` Case 1 throws `InvalidArgumentException` (no renderable →
   500) when a configured column is not selected. No partial select exists today; the cast would create a
   future 500 for no present benefit.
What the sweep actually pointed at was the **shape invariant**, missing on all ten columns — now closed below.

**Currency SHAPE is a DB fact on all ten `*_currency` columns (2026-08-01).**
`{table}_{column}_shape` CHECK: `col IS NULL OR col COLLATE utf8mb4_bin REGEXP '^[A-Z]{3}$'` (case-sensitive;
`REGEXP`-in-`CHECK` proven on MySQL 8.0.43). Shape only — **not** `= 'NGN'`: single-currency lives in
`Money::DEFAULT_CURRENCY` + the FormRequests, a DB constraint pinning NGN would have to be dropped the day a
second currency is real; shape is the permanent invariant. A violation is **3819 → 500** via
`bootstrap/app.php`'s generic branch — correct for a backstop, because the wire path already gets a **422**
from the FormRequest regex; this catches every *other* writer (Action, seeder, import, `tinker`, and the raw
`DB::insert` `SubledgerPoster` must use). The two discount tables' `BEFORE UPDATE` immutability triggers are
untouched — a CHECK and a trigger coexist.
