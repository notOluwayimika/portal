# Finance concurrency conventions

The receivable subledger is money truth, so every guard that keeps it consistent
must hold **under concurrency**, not merely in a single-threaded test. This file
records the locking conventions the Finance actions follow and — importantly — the
one that is **not yet enforced because nothing needs it yet**, so a future slice
adopts it deliberately rather than re-deriving it.

Proofs live in `tests/Feature/Finance/InvoiceConcurrencyTest.php` and
`WalletConcurrencyTest.php`, all as deterministic two-connection interleaves (a
backgrounded-process race proves nothing — the #94 lesson).

## What is enforced today

- **Invoice-row lock (over-allocation, #94).** `RecordPayment` takes
  `Invoice::lockForUpdate()` **first**, then reads Σ(allocations) for the outstanding
  cap. This serialises allocations to one invoice so `Σ(allocations) ≤ total` holds
  under concurrency; the `finance_allocation_not_over_invoice_total` trigger is the
  single-write/tamper backstop. This slice did **not** touch that lock.

- **Active-enrollment uniqueness & double-void.** `GenerateInvoice` and
  `CancelInvoice` rely on current reads (a `UNIQUE` index; `lockForUpdate` re-read)
  rather than snapshot reads — see `InvoiceConcurrencyTest`.

- **Account balance — atomic increment, no lock.** `SubledgerPoster::post` maintains
  `finance_student_accounts.balance_minor` with an atomic upsert-increment
  (`balance = balance + delta`, `ON DUPLICATE KEY`). `col = col + delta` is a current
  read at InnoDB, serialised at the row, so two concurrent posts to one account both
  land with **no application lock**. This is why W2 adds no account lock: there is no
  read-modify-write to protect. Proven skew-free (and contrasted with a read-modify-
  write that loses an update) in `WalletConcurrencyTest` PROOF 4.

## The convention W3 must adopt: **account row first, then invoice row**

W3 (apply-credit-forward at invoice generation) is the first action that performs a
genuine **read-modify-write of the account balance**: it reads available credit,
decides how much to apply, and writes an allocation funded by it. That step needs a
pessimistic `StudentAccount::lockForUpdate()` — an atomic increment cannot express
"read the credit, then spend up to it".

Because `RecordPayment` already locks the **invoice** row, and W3 will lock **both**
the account row and (via the allocation) the invoice, the two actions must acquire
the locks in the **same order** or they deadlock (MySQL 1213). The order is:

> **account row first, then invoice row.**

W3 owns the proof burden this creates:

1. the account `lockForUpdate` read-modify-write skew proof (pull the lock → the
   double-spend of credit returns), and
2. the cross-action deadlock proof — a payment (`RecordPayment`) racing an
   apply-forward (`GenerateInvoice`) on the **same student**, asserting no 1213.

Neither can be written in W2 (apply-forward does not exist yet), which is why the
convention is *recorded* here and *proven* there. When W3 adds the account lock to
`RecordPayment` as well (so both actions order account-before-invoice), update the
"enforced today" section above.

### Snapshot-timing footnote (why order-of-reads matters)

InnoDB REPEATABLE READ fixes a transaction's read snapshot at its **first consistent
(non-locking) read** — not at `BEGIN`. So a guard that must see a competitor's
committed row has to make its **first** read a *locking* read (`lockForUpdate`), or a
later plain `SUM` will read a snapshot taken before the competitor committed and miss
it. This is exactly why `RecordPayment` opens with `Invoice::lockForUpdate()` before
summing allocations, and it is a trap W3 must respect when it adds the account lock:
the account `lockForUpdate` must precede any plain read whose result feeds the
credit-apply decision.
