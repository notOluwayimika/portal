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

## Result

40 backstops (29 SIGNAL triggers + 11 CHECKs). **REACHABLE = 2** · GUARDED = 11 · UNREACHABLE = 27 ·
UNKNOWN = 0.

## Triggers (29)

| Name | Table | Forbids | Code | Reachability | Evidence |
|---|---|---|---|---|---|
| activity_log_no_delete | activity_log | DELETE a log row | 1644 | UNREACHABLE | No route DELETEs activity_log. |
| activity_log_no_update | activity_log | UPDATE a log row | 1644 | UNREACHABLE | No route UPDATEs activity_log. |
| finance_credit_notes_no_delete | finance_credit_notes | DELETE credit note | 1644 | UNREACHABLE | No finance DELETE route (`routes/endpoints/finance.php`). |
| finance_credit_notes_insert_guard | finance_credit_notes | currency≠invoice; approved-vs-void; ceiling | 1644 | **REACHABLE** | Currency branch: `currency` is user-supplied (`SubmitCreditNoteRequest.php:32`), no app check it matches the invoice; `CreditNoteController.php:37-39` builds Money from it. B-2 below = 500. (Approved-vs-void + ceiling branches unreachable: `SubmitCreditNote.php:61` inserts status=Submitted.) |
| finance_credit_notes_update_guard | finance_credit_notes | mutate money/identity on UPDATE | 1644 | UNREACHABLE | Only `ApproveCreditNote`/`RejectCreditNote` UPDATE, status/decided_* only. |
| finance_discount_policies_no_delete | finance_discount_policies | DELETE policy | 1644 | UNREACHABLE | No route DELETEs policies (edit/remove are amend/retire changes). |
| finance_discount_policies_update_guard | finance_discount_policies | mutate policy terms | 1644 | UNREACHABLE | Only `ApproveDiscountPolicyChange` writes; status-only moves; no route edits terms. |
| finance_discount_policy_changes_no_delete | finance_discount_policy_changes | DELETE change | 1644 | UNREACHABLE | No DELETE route. |
| finance_discount_policy_changes_update_guard | finance_discount_policy_changes | mutate frozen change columns | 1644 | UNREACHABLE | Only Approve/Reject UPDATE, status/decided_* only. |
| finance_fee_items_parent_state_guard_ins | finance_fee_items | INSERT item onto non-draft schedule | 1644 | UNREACHABLE | Items inserted only into a fresh draft by `CreateFeeSchedule.php:47-63`; no route inserts onto active/pending. |
| finance_fee_items_parent_state_guard_upd | finance_fee_items | UPDATE item on non-draft schedule | 1644 | UNREACHABLE | No route UPDATEs items. |
| finance_fee_items_parent_state_guard_del | finance_fee_items | DELETE item on non-draft schedule | 1644 | UNREACHABLE | No route DELETEs items. |
| finance_fee_schedule_changes_no_delete | finance_fee_schedule_changes | DELETE change | 1644 | UNREACHABLE | No DELETE route. |
| finance_fee_schedule_changes_update_guard | finance_fee_schedule_changes | mutate frozen change columns | 1644 | UNREACHABLE | Only Approve/Reject UPDATE. |
| finance_invoice_lines_no_delete | finance_invoice_lines | DELETE a line | 1644 | UNREACHABLE | No route DELETEs lines. |
| finance_invoice_lines_no_update | finance_invoice_lines | UPDATE a line | 1644 | UNREACHABLE | No route UPDATEs lines. |
| finance_invoice_lines_reduction_guard | finance_invoice_lines | reduction line w/o active/same-school/no-approval policy; charge w/ policy | 1644 | GUARDED | `GenerateInvoice.php:55` catches 1644 and re-throws BusinessRuleException → 422 (the one place that catches 1644). |
| finance_invoices_no_delete | finance_invoices | DELETE invoice | 1644 | UNREACHABLE | No DELETE route. |
| finance_invoices_total_immutable | finance_invoices | change total on UPDATE | 1644 | UNREACHABLE | Void UPDATEs status only (`ApproveVoidRequest.php:70`); no route writes total. |
| finance_ledger_transactions_no_delete | finance_ledger_transactions | DELETE ledger row | 1644 | UNREACHABLE | No route mutates the ledger. |
| finance_ledger_transactions_no_update | finance_ledger_transactions | UPDATE ledger row | 1644 | UNREACHABLE | No route mutates the ledger. |
| finance_allocation_not_over_invoice_total | finance_payment_allocations | allocation > invoice total; currency≠ | 1644 | GUARDED | Amount capped before insert: `RecordPayment.php:84` `min(amount, outstanding)`; `GenerateInvoice.php:416` `min(remaining, unallocated)`, `:245` `min(credit, total)`. |
| finance_payment_allocations_no_delete | finance_payment_allocations | DELETE allocation | 1644 | UNREACHABLE | No route DELETEs allocations. |
| finance_payment_allocations_no_update | finance_payment_allocations | UPDATE allocation | 1644 | UNREACHABLE | No route UPDATEs allocations. |
| finance_payments_no_delete | finance_payments | DELETE payment | 1644 | UNREACHABLE | No route DELETEs payments. |
| finance_payments_no_update | finance_payments | UPDATE payment | 1644 | UNREACHABLE | No route UPDATEs payments. |
| finance_void_requests_no_delete | finance_void_requests | DELETE void request | 1644 | UNREACHABLE | No DELETE route. |
| finance_void_requests_update_guard | finance_void_requests | mutate frozen columns | 1644 | UNREACHABLE | Only Approve/Reject UPDATE, status/decided_* only. |
| guardian_student_same_school_bi/bu | guardian_student | attach/re-link guardian & student of different schools | 1644 | UNREACHABLE | Attach guarded (`GuardianService` resolveExistingGuardianForAttachment creates guardian in target school on mismatch); `updatePivot` requires an EXISTING pivot (`GuardianService.php:399` — already same-school; ValidationException if absent); DELETE fires no same-school trigger. B-2 below = 404, not 500. (Routes carry `withoutScopedBindings()` — a separate cross-school-IDOR concern, but not a path to THIS trigger.) |

## CHECK constraints (11)

| Name | Table | Forbids | Code | Reachability | Evidence |
|---|---|---|---|---|---|
| finance_credit_notes_maker_ne_checker | finance_credit_notes | submitter == decider | 3819 | GUARDED | `ApproveCreditNote.php:41` refuses submitter==checker; Policy 403 too. |
| finance_discount_policies_basis_exclusive | finance_discount_policies | amount and percent both / neither | 3819 | UNREACHABLE | Only `ApproveDiscountPolicyChange` inserts, from a change row already gated by `..._changes_terms_shape`; a bad-terms change can't exist to approve. |
| finance_discount_policy_changes_maker_ne_checker | finance_discount_policy_changes | submitter == decider | 3819 | GUARDED | `ApproveDiscountPolicyChange.php:31` refuses submitter==checker; Policy 403. |
| finance_discount_policy_changes_target_shape | finance_discount_policy_changes | create-with-target / amend|retire-without-target | 3819 | GUARDED | `SubmitDiscountPolicyChangeRequest.php:27` `required_unless:kind,create`; controller resolves `target` only when kind≠create. |
| finance_discount_policy_changes_terms_shape | finance_discount_policy_changes | basis=amount with percent (or basis=percent with value_minor) | 3819 | **REACHABLE** | `SubmitDiscountPolicyChangeRequest.php:33,35` validate `required_if` but do NOT prohibit `percent` when amount / `value_minor` when percent; `terms()` passes both through. B-2 below = 500. |
| finance_fee_schedule_changes_maker_ne_checker | finance_fee_schedule_changes | submitter == decider | 3819 | GUARDED | `ApproveFeeScheduleChange.php:30` refuses submitter==checker; Policy 403. |
| finance_void_requests_maker_ne_checker | finance_void_requests | submitter == decider | 3819 | GUARDED | `ApproveVoidRequest.php:46` refuses submitter==checker; Policy 403. |
| scores_range | scores | score outside 0–100 | 3819 | UNREACHABLE | `UpsertScoreRequest` marks `score` prohibited, accepts `score_percent` 0–100, converts server-side (`CurriculumSubjectController:325-350`). |
| student_curricula_promoted_requires_link | student_curricula | status=promoted with null promoted_to_id | 3819 | GUARDED | Status routes forbid 'promoted' (`UpdateStudentCurriculumStatusRequest.php:28` `Rule::in([active,repeated,withdrawn])`; `StudentController.php:136` `Rule::enum` excludes PROMOTED); promote action + jobs set both atomically (`StudentCurriculumController.php:202-205`). |
| subject_result_statuses_maker_ne_checker | subject_result_statuses | submitter == decider | 3819 | GUARDED | `SubjectResultPolicy.php:40,45` isNotTheMaker on approve/reject. |
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
