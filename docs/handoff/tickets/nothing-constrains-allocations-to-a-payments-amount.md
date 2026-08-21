# TICKET — nothing constrains Σ(allocations of a PAYMENT) ≤ that payment's amount

**Status:** **SHIPPED** on `feat/allocation-payment-axis-guard` (2026-08-21) — with one part of
it deliberately still open, narrowed from a question into a named residual. See
§ "What shipped, and what it does and does not cover" at the foot of this file before reading
the rest, which is preserved as it was written. Raised by the cold review of
`feat/finance-payment-receipt` (U11). Deliberately not fixed there: the receipt is a read
surface and this is a write-side invariant on an append-only table, which is its own change
with its own migration and its own concurrency argument.

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

---

## What shipped, and what it does and does not cover

**Branch:** `feat/allocation-payment-axis-guard`. **Report:**
[`docs/handoff/reports/feat-allocation-payment-axis-guard.md`](../reports/feat-allocation-payment-axis-guard.md)
— the trigger read back from `information_schema`, every proof's raw output and its mutation,
and the concurrency measurements.

`database/migrations/2026_08_21_110000_finance_allocation_not_over_payment_amount.php` installs
`finance_allocation_not_over_payment_amount`: a `BEFORE INSERT` trigger on
`finance_payment_allocations` reading `finance_payments.amount_minor` and
`SUM(amount_minor)` for that `payment_id`, signalling SQLSTATE `45000`. It fires **second** for
that event (`ACTION_ORDER = 2`), after the July invoice-axis sibling, so a row violating both
axes still reports the older invoice-axis message. It also refuses an allocation whose currency
differs from the payment's — beyond this ticket's literal ask, because without it the Σ
comparison sums minor units of two currencies into one total and is undefined rather than
merely weak.

Proofs: `tests/Feature/Finance/PaymentAxisGuardTest.php` (8 arms, every allocation inserted raw
so neither Action's cap can satisfy them, every refusal asserting the MESSAGE and not just
`45000` — roughly fifty triggers here signal `45000`) and
`tests/Feature/Finance/PaymentAxisConcurrencyTest.php` (5 arms). Finance suite 663 → 676, all
green.

### What it covers

A single write, a tamper, a bulk correction, a SQL console, a restored dump. That is what the
sibling calls the single-write backstop, and it is real: the illegal state is now
unrepresentable against any one writer.

### What it does NOT cover — and this is the part that stays open

**The trigger is not the concurrency anchor and cannot be.** Its `SELECT SUM` is a plain read;
it cannot see another transaction's uncommitted allocation. Measured, not conceded: two
connections each inserting 5001 against a 10000 payment BOTH pass the trigger and Σ ends at
10002. The same two inserts on ONE connection are refused, which is the control that makes that
measurement mean something.

### What the ticket asked and this branch answered

§ 3 above recorded that `GenerateInvoice`'s cap is a read-then-write with no lock on the payment
row, and that whether `RecordPayment`'s serialisation argument covers the payment axis "has not
been established". It is established now:

- **`RecordPayment` is safe by exclusivity, not by a lock.** It creates the payment inside its
  own transaction; a concurrent `GenerateInvoice` cannot see that payment at all — measured as
  zero rows from a second connection — so there is nothing for a competitor to draw. The axis is
  vacuous for it and no payment-row lock would protect anything.
- **`GenerateInvoice::applyCreditForward` IS serialised — by the ACCOUNT row, not the payment
  row.** The payment row is genuinely unlocked (a second connection takes it `FOR UPDATE`
  mid-flight and succeeds). But `GenerateInvoice`'s first statement is
  `StudentAccount ... lockForUpdate`, a second generation blocks there with 1205, and every
  payment `applyCreditForward` can draw belongs to that one student — so the account row is a
  strictly coarser serialisation point that covers this axis. Pull that lock and the arm goes
  red.

### The residual, stated as a residual

The coverage above is a property of **the two writers that exist today, not of the schema**. A
future writer that allocates against a payment without joining the account-row lock — a job, a
bulk correction, a second path — would race, and this trigger would not catch it. Closing that
needs `SELECT ... FOR UPDATE` on the payment row inside that writer's transaction; a trigger
cannot take a lock that outlives its own statement, so it cannot be pushed into the database.

That is a smaller, named residual than the open question this ticket recorded. It is not closed.

### Two things this branch did not do

- **`PaymentReceiptController` was not touched.** § "Not the fix" stands.
- **No deploy pre-flight.** A `BEFORE INSERT` trigger does not inspect existing rows and will
  deploy cleanly over a violating one. As with the sibling, a pre-flight asserting zero exist is
  still needed and is not part of this branch:
  `SELECT payment_id FROM finance_payment_allocations GROUP BY payment_id HAVING SUM(amount_minor) > (SELECT amount_minor FROM finance_payments WHERE id = payment_id)`.
