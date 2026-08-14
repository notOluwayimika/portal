# TICKET — `FeeItem` and `DiscountPolicy` are not fail-closed, and U8 made them load-bearing

**Status:** open, not implemented. Raised by `feat/u8-wire-ids-uuid`; deliberately not fixed there,
because adding a model to `fail_closed_models` changes read behaviour platform-wide and belongs in a
commit whose blast radius is that change, not a wire-format change.

**Root:** `POST /v1/finance/invoices` now resolves two wire uuids to integer primary keys by reading
through `FeeItem::query()` and `DiscountPolicy::query()`. That resolution's isolation is `SchoolScope`
— which adds no predicate when there is no active School, and throws only for models on
`config/rbac.php`'s `fail_closed_models` list. Neither model is on it.

## The list, as it stands

`config/rbac.php:156-170` — ten entries, re-read for this ticket:

```
LedgerTransaction, Payment, PaymentAllocation, Invoice, InvoiceLine,
CreditNote, StudentAccount, OpeningBalanceBatch, OpeningBalanceRow, VoidRequest
```

`FeeItem` and `DiscountPolicy` are not among them. The config's own block explains the shape of the
batch — "the finance transactional set … and why the catalog is not" — so their absence is a
deliberate scoping decision that predates this branch. What changed is that both models are now read
on a **write** path to produce a value that is stored.

## The principal it applies to

One: a `super_admin` with no School selected.

- `app/Http/Middleware/SetSchoolContext.php:51` — `if (! $isSuperAdmin && ! $activeSchoolId)` is what
  redirects or 403s a context-less request. For a super_admin the first half is false, so the request
  proceeds with no active School.
- `App\Support\ActiveSchool::id()` is then null.
- `app/Models/Scopes/SchoolScope.php` — the null-context branch (`elseif (auth()->check() && $this->shouldFailClosed($model))`)
  throws `MissingSchoolContextException` **only** for a listed model. For an unlisted one it adds no
  `where` and does not throw, so the query runs unscoped.

Consequence: for that principal, a fee item or discount policy uuid belonging to any School on the
installation passes validation and resolves to its real integer id.

## What refuses the write today

`GenerateInvoice`. With no context there is no `school_id` to raise an invoice under, and the Action
returns 422:

```
'No active School context: an invoice cannot be raised.'
```

Measured over HTTP, not reasoned. `tests/Feature/Finance/InvoiceWireIdsTest.php` carries the arm — a
super_admin with no `school_id` in session, posting a foreign School's fee item uuid. Temporarily
asserting a wrong message to print the real one gives:

```
Failed asserting that two strings are identical.
--- Expected
+++ Actual
@@ @@
-'XX-SHOW-ME-THE-RAW-REFUSAL-XX'
+'No active School context: an invoice cannot be raised.'
```

The same arm asserts that `errors` is **null** — i.e. that the edge did *not* refuse it. So nothing
is written, and the gap is entirely between the two layers: the edge accepts and resolves an id it
should not have been able to see, and a later layer declines to use it.

## Why that is worth a ticket rather than nothing

The refusal is a property of `GenerateInvoice` needing a school_id, not of the resolution being
isolated. Any future caller of `GenerateInvoiceRequest::lineSpecs()` that does **not** need an active
School inherits the resolution without inheriting the refusal, and there is no test that would notice.

## The fix, and what it would cost

Add both models to the `fail_closed_models` default in `config/rbac.php`. The null-context branch then
throws `MissingSchoolContextException` instead of running unscoped.

Before doing it, check the READ paths, because the same throw applies to them: the fee-schedule
catalog and the discount-policy index are context-less-super_admin-reachable surfaces today, and this
change turns those reads into exceptions rather than empty results. That is the argument the config's
own comment block makes for keeping the catalog out of the batch, and it has to be answered, not
skipped.

Whichever way it goes, `InvoiceWireIdsTest`'s super_admin arm fails when it lands — by design. Its
assertion message says so, and it is the signal that the two rule comments in
`GenerateInvoiceRequest` naming this exception need rewriting too.
