# A dead discount policy is refused by the trigger, not by the edge — U8's opening precondition

**Raised by:** the cold review of U2 (`feat/discount-policies-page`), which removed the catalog
endpoint's implicit `active` filter.

**This is not a free-floating ticket. It is the first item in U8's scope** — awarding a discount at
invoice time — in the same way U1's drive-fixture seeding was folded into the commit that needed it.
A known trap that belongs to the next commit is that commit's precondition, not something the next
commit might happen to read.

## What is true today

`GenerateInvoiceRequest` validates the policy reference by SHAPE only:

```php
// GenerateInvoiceRequest.php:113
'lines.*.discount_policy_id' => ['sometimes', 'nullable', 'integer'],
```

and the rule above it says so deliberately (`GenerateInvoiceRequest.php:110-112`, verbatim):

> beyond shape — the DB reduction_guard is the authority (active + not approval-requiring + same
> School). There is deliberately NO is_discountable rule: that is a fee-item property resolved
> server-side in the Action, never a client claim (it would let a caller move the percentage base).

`GenerateInvoice` passes the id through unexamined (`GenerateInvoice.php:221`,
`InvoiceLineSpec.php:58`), so the **only** thing that refuses a superseded or retired policy is
`finance_invoice_lines_reduction_guard` — a MySQL trigger signalling SQLSTATE `45000`
(`2026_07_26_140002_add_discount_policy_to_finance_lines.php:80-82`, message *"The referenced
discount policy is not active."*). Driver code 1644 is deliberately unmapped in `bootstrap/app.php`
(the handler classifies 1062, 1451, 1205/1213 and says so at `:201`), so it surfaces as a generic
**500**, not as a field error on the line the bursar chose.

## Why this is a ticket and not a fix

Nothing is wrong today, on either axis that matters:

- **The money invariant holds.** The trigger refuses the write; no invoice is ever priced by a dead
  policy. This is a message-quality defect, not a correctness one.
- **There is no consumer.** No screen and no test posts `discount_policy_id`. U2 authors the catalog;
  it does not spend it.

Fixing it now would be a guard written against an imagined caller — the shape of the eventual rule
(a `Rule::exists` with a status predicate, versus a pre-check inside `GenerateInvoice` that can also
see `requires_approval` and produce one sentence for both refusals) depends on what U8's picker
actually sends, and choosing before that is a primitive ahead of its consumer.

## Why U2 made it more likely rather than less

U2 changed `DiscountPolicyController::index()` from a hard `status = active` filter to an optional
one, absent meaning unfiltered — the `FeeScheduleController::index()` shape. That was right: the
authoring screen must show superseded and retired policies, because a policy that priced an invoice
has to stay nameable forever.

But the old default was also, incidentally, the only thing stopping a naive consumer from ever
SEEING a dead policy. The predicted failure is concrete: **U8 builds an invoice-time picker, omits
`?status=active`, a bursar picks "Sibling discount — Retired", and the save 500s** with no indication
of which line was wrong.

## The internal inconsistency, which is the actual argument

The same feature, in the same repository, took the opposite decision one table over.
`SubmitDiscountPolicyChangeRequest.php:33-34`, verbatim:

> amount XOR percent, refused here so a cross combo is a 422, not the DB terms_shape CHECK's
> 3819 → 500 (backstop-reachability audit). The CHECK stays as the backstop.

That is the treatment this reference does not get. Both are DB constraints that produce an unmapped
driver code and therefore a 500; one is pre-empted at the edge so the constraint is a backstop, and
one is the front line. There is no reason for the asymmetry other than that nobody was posting the
field yet.

## The remedy, when U8 starts

Either:

1. a School-scoped `exists` rule with a status predicate on `lines.*.discount_policy_id` — the shape
   `FeeScheduleController::index()`'s `term_id` rule already uses, so the 422 names the field; or
2. a pre-check in `GenerateInvoice` that resolves the policy and refuses through
   `BusinessRuleException` — which can also catch `requires_approval = true` (today a second 45000
   → 500, *"apply it as a credit note, not an invoice line"*) and answer both in one sentence the
   bursar can act on.

(2) is the better fit for the second reason: two of the trigger's three refusal branches are
operator-facing, and only a resolved policy can tell them apart. Either way the trigger stays,
unchanged, as the backstop — the point is that it stops being the first line.

**And U8's picker must fetch `?status=active`.** That is the one-line half of this, and it is not a
substitute for the guard: a client-side filter is not a refusal.
