# `editDraft` reuses a request that demands two fields it ignores — decide at U1

**Raised by:** cold review of PR #234 (finding R1). **Deliberately NOT fixed in #234** — the project
lead's call: it is a decision for the commit that writes the page, not for the domain commit, and
changing it before there is a caller would be designing the contract from imagination.

**PARTIALLY CLOSED.** The isolation half — both `exists` rules unscoped — was **fixed**, because it
is a live hole on `store` and `supersede` rather than a design question, and leaving it open after
scoping the identical `fee_item_id` rule two commits earlier would have been incoherent. See the
closing section. **What remains open is the request-reuse decision, and that is still U1's:
inherit the decision, not the surprise.**

## What is true today

`PUT /v1/finance/fee-schedules/{feeSchedule:uuid}/draft` reuses `FeeScheduleRequest` unchanged. That
was the right call for the domain commit — it is what makes #233's bank-account-per-item rule bite on
an edit exactly as it does on a create, with no second implementation. But the request also carries:

```php
'term_id' => ['required', 'integer', 'exists:terms,id'],
'class_level_id' => ['required', 'integer', 'exists:class_levels,id'],
```

and `EditFeeScheduleDraft::handle()` **never reads either one** — the draft's slot is fixed by the
row, and re-slotting it from the body would be the same defect `supersede` deliberately avoids.

So the page must send two fields the server validates and then discards. A form that submits values
nobody consumes is a form whose author will eventually "fix" it by consuming them.

Two further facts a decision has to account for:

- ~~**Both `exists` rules are UNSCOPED.**~~ **FIXED — see below.** They now carry
  `->where('school_id', ActiveSchool::id())`.
- The fields being `required` means a page that omits them gets a **422 naming fields the operator
  cannot see**, which is the worst version of this.

## The options, for U1 to choose between

1. **A dedicated `EditFeeScheduleDraftRequest`** that carries `label` + `items` and nothing else,
   extending or composing the item rules so the bank-account rule stays single-sourced. Cleanest
   contract; the cost is keeping the item-rule reuse genuinely shared rather than copied — a second
   copy of that rule is exactly what the domain commit avoided.
2. **Make the two fields `sometimes` on the shared request** and have `store`/`supersede` require
   them by their own rule. Smaller diff, but the shared request stops describing one shape.
3. **Send them and accept the redundancy**, documented at the call site. Cheapest, and it leaves the
   trap for the next reader.

(1) is the likely answer.

## Closed half — the unscoped `exists` rules

Fixed rather than deferred, because it was not a design question.

`store` and `supersede` READ `term_id` and `class_level_id`, and nothing downstream checked
ownership. Read from `information_schema` rather than from the migration, `finance_fee_schedules`
carries three **single-column** foreign keys — `term_id → terms.id`,
`class_level_id → class_levels.id`, `school_id → schools.id` — and **no composite
`(school_id, term_id)` pair**. The `(school_id, term, class level)` uniqueness key asks whether a
slot is TAKEN, never whether it is YOURS. So a schedule could sit in your School, priced by you,
keyed to another School's term and class level — and `SchoolScope` would show it to you, because the
schedule's own `school_id` is correct.

Both rules are now `Rule::exists(...)->where('school_id', ActiveSchool::id())`, the same shape as
`items.*.bank_account_id` beside them and as the `fee_item_id` rule on `GenerateInvoiceRequest`.

Pinned by an arm that posts another School's term and class level to `store` — together, then each
alone paired with the caller's own (so a rule scoping only the first cannot pass), then the caller's
own pair asserted **201** so the refusals are the scoping and not a rule that rejects everything.
Watched red by restoring the bare `exists:terms,id`: the arm fails to find the `term_id` error, and
`rules()` read out of the **running program** shows `term_id => required | integer | exists:terms,id`
beside the still-scoped `class_level_id`.
