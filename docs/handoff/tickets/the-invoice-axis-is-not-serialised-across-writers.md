# The invoice axis is not serialised across writers

**Status:** OPEN. Raised by the cold review of `feat/u10-allocation-screen`, 2026-08-22.
**Sibling:** [`nothing-constrains-allocations-to-a-payments-amount.md`](nothing-constrains-allocations-to-a-payments-amount.md)
— the same shape on the payment axis, now closed, and its § "What it does NOT cover" is the
paragraph this ticket is the invoice-side twin of.

## The claim that was false

`app/Finance/Actions/AllocatePayment.php` said, at the invoice-axis refusal, that
`finance_allocation_not_over_invoice_total` "is the authority and stays reachable for any writer that
does not come through here."

That is the sentence the payment-axis ticket had already demolished for this trigger's sibling, and
it is false here for the same reason. **A `BEFORE INSERT` trigger's `SELECT SUM` is a plain read and
cannot see another transaction's uncommitted allocation.** It refuses what a single transaction can
see — a single-write backstop — and it is not a serialisation point. The sentence has been corrected
in place on that branch (`fix(finance): a numeric string wrote an override nobody made…`); this
ticket is the finding it points at.

## The measurement

The cold review measured **Σ = 20000 against a 10000 invoice**, by the same method the payment-axis
ticket used: two connections, neither able to see the other's uncommitted row, both passing the
trigger, both committing.

## Why nothing blocks: the three writers hold disjoint locks

| Writer | Locks | Touches the invoice row? |
| --- | --- | --- |
| `RecordPayment` | the **INVOICE** row (`Invoice … lockForUpdate`, the #94 anchor) | yes |
| `GenerateInvoice::applyCreditForward` | the **ACCOUNT** row (`StudentAccount … lockForUpdate`, its first statement) | no |
| `AllocatePayment` (new) | the **ACCOUNT** row, first statement | no |

Rows 2 and 3 never block row 1, and row 1 never blocks them: the locks are on different rows. The
account row serialises the two account-lockers against each other and covers the **payment** axis —
every payment either can draw belongs to the one student whose account row is held — but it says
nothing about a third party writing against the same **invoice**.

## This pre-dates the branch that surfaced it

**The disjoint pair is `RecordPayment` × `applyCreditForward`, and both shipped long before U10.**
`feat/u10-allocation-screen` adds a **third** writer on the same uncovered axis; it did not create
the gap. Reporting it as a regression of that branch would be wrong, and so would closing it as
"pre-existing" — the branch made the axis carry one more writer.

## A prediction this falsified, worth recording

`AllocatePayment`'s docblock argues it "takes the account row and no other row lock at all, so it
introduces no opposite-order pair and no deadlock". The cold review looked for FK-check contention on
the invoice row that would have made that false and **found none**. The no-deadlock claim therefore
stands as measured rather than as reasoned — which is the useful half of a negative result.

## The fix is its own change

Closing this needs a serialisation point the three writers share, and that is a concurrency argument
with its own blast radius — `RecordPayment`'s and `GenerateInvoice`'s locks are load-bearing for
invariants of their own and must not be reordered casually. **It is exactly how the payment axis was
handled:** the trigger landed first as a single-write floor, the residual was named, and the lock
argument came separately with its own measurements.

Do not attempt it inside a feature branch.

## What is true today, so nobody over-reads this

- Both allocation triggers **do** hold against any single transaction — a tamper, a bulk correction,
  a SQL console, a restored dump. That is real and it is what they were built for.
- The **payment** axis is serialised, by the account row, and is measured in
  `tests/Feature/Finance/AllocatePaymentConcurrencyTest.php` PROOF B and in the sibling ticket.
- No deploy pre-flight exists for either trigger. A `BEFORE INSERT` trigger does not inspect existing
  rows and deploys cleanly over a violating one:

```sql
SELECT invoice_id FROM finance_payment_allocations
 GROUP BY invoice_id
HAVING SUM(amount_minor) > (SELECT total_minor FROM finance_invoices WHERE id = invoice_id);
```
