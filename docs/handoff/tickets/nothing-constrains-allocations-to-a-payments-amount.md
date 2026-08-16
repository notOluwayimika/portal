# TICKET — nothing constrains Σ(allocations of a PAYMENT) ≤ that payment's amount

**Status:** open, not implemented. Raised by the cold review of `feat/finance-payment-receipt`
(U11). Deliberately not fixed there: the receipt is a read surface and this is a write-side
invariant on an append-only table, which is its own change with its own migration and its own
concurrency argument.

**Severity:** ticket, not stop. No path that produces the state has been found — see § "What
guarantees it today", which is measured rather than assumed. It is recorded because the guarantee
is entirely in application code, on a table the database makes permanent.

## The gap

`finance_payment_allocations` has exactly one over-allocation guard, and it is on the **wrong
axis** for this question.

`database/migrations/2026_07_22_120000_finance_allocation_not_over_invoice_total.php:35-40` creates
a `BEFORE INSERT` trigger, `finance_allocation_not_over_invoice_total`, enforcing:

> Σ(allocations to an **invoice**) ≤ that invoice's total.

There is **no** counterpart for the payment side. Nothing — trigger, CHECK, foreign key or
generated column — enforces:

> Σ(allocations of a **payment**) ≤ that payment's `amount_minor`.

Derived by reading every migration that touches the table: the only two that name
`finance_payment_allocations` are the over-allocation trigger above and
`2026_08_01_120000_add_currency_shape_checks.php:38` (a currency-shape CHECK on
`amount_currency`).

## What it would look like on a receipt

`PaymentReceiptController::document()` computes `$unallocated = $payment->amount->minus($allocated)`.
`Money::minus` does not floor at zero — it produces a negative — and the page renders on
`held_on_account = ! $unallocated->isZero()`, which is **true for a negative**. So an
over-allocated payment prints:

> The remaining **-₦5,000.00** is held as credit on … 's account and will be applied to the next
> invoice raised.

A receipt is the one document a parent keeps. It stating a negative credit is worse than the page
erroring, because it looks authoritative.

## What guarantees it today, and by what

Both writers cap per-payment in application code. Measured, not assumed:

- `RecordPayment::handle` — `app/Finance/Actions/RecordPayment.php:89`:
  `$allocateKobo = min($amount->toKobo(), $outstandingKobo)`. One allocation per call, capped at the
  payment's own amount, so Σ ≤ amount holds trivially.
- `GenerateInvoice::applyCreditForward` — `app/Finance/Actions/GenerateInvoice.php:412-418`:
  computes `$unallocated = $payment->amount->toKobo() - $allocated`, **skips** the payment when
  `$unallocated <= 0`, and draws `min($remaining, $unallocated)`. So it can never take a payment
  past its own total, however many invoices draw on it.

Those are the only two writers of the table. So the invariant holds **by construction in code, and
by nothing in the database.**

## Why that is worth a ticket rather than a shrug

Three properties make the residual sharper than "code is careful":

1. **The table is append-only.** A bad allocation row is permanent — there is no DELETE and no
   UPDATE. Unlike a balance, it cannot be corrected, only compensated around.
2. **The guard on the sibling axis exists.** Someone reading the schema sees an over-allocation
   trigger and will reasonably conclude over-allocation is handled at the database. It is, on one
   axis of two.
3. **`GenerateInvoice`'s cap is a read-then-write with no lock on the payment row.** The invoice
   guard's own docblock (`2026_07_22_120000_…:22-24`) records that the trigger cannot see an
   uncommitted concurrent allocation and leans on `RecordPayment`'s serialisation for the invoice
   axis. Whether the same argument covers the payment axis under two concurrent
   `GenerateInvoice` calls drawing on one payment has **not** been established here.

## What a fix would be

A `BEFORE INSERT` trigger on `finance_payment_allocations` mirroring the existing one, reading
`finance_payments.amount_minor` and `SUM(amount_minor)` for that `payment_id`, signalling
SQLSTATE `'45000'`. Same shape, same failure mode, one axis over. It should ship with a
concurrency proof rather than an assertion, because the sibling trigger's docblock already says
what a trigger cannot see.

## Not the fix

Flooring `unallocated` at zero in `PaymentReceiptController`. That hides the state on the one
surface that would have shown it, and leaves the row in the ledger.
