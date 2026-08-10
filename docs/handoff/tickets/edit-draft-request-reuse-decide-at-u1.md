# `editDraft` reuses a request that demands two fields it ignores — decide at U1

**Raised by:** cold review of PR #234 (finding R1). **Deliberately NOT fixed in #234** — the project
lead's call: it is a decision for the commit that writes the page, not for the domain commit, and
changing it before there is a caller would be designing the contract from imagination.

**For whoever builds U1: this is yours to settle. Inherit the decision, not the surprise.**

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

- **Both `exists` rules are UNSCOPED.** No `->where('school_id', …)`, and `Rule::exists` queries the
  table rather than the model, so `SchoolScope` does not apply. Another School's `term_id` passes
  validation. It changes nothing today *on this route* because the values are discarded — but the
  same request object also serves `store` and `supersede`, where they are not.
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

(1) is the likely answer. Whichever is chosen, **scope the two `exists` rules to the active School at
the same time** — that is a real gap on the routes that DO read them, and it is cheap to close while
the file is open.
