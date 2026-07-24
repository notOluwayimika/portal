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

- **Carry-forward credit — account `lockForUpdate` (W3).** `GenerateInvoice` reads
  credit and spends it (a real read-modify-write), so it holds the account row for the
  transaction, account-before-invoice. See the section below for the ordering and its
  two proofs.

## The convention, now ENFORCED: **account row first, then invoice row**

W3 (apply-credit-forward at invoice generation) is the first action that performs a
genuine **read-modify-write of the account balance**: it reads carry-forward credit
(`max(0, −balance)` from the pre-charge balance), decides how much to apply, and
writes the settling allocations. `GenerateInvoice` acquires
`StudentAccount::lockForUpdate()` as its **first** statement in the transaction —
before `assertNoActiveInvoice` — so the lock is held while the credit is read and
spent, and (being a locking read) it does not fix the REPEATABLE READ snapshot early.

**Why there is no deadlock with #94.** `RecordPayment` locks the **invoice** row and
touches the account only through `SubledgerPoster::post`'s atomic increment (a brief
row lock, never a `lockForUpdate` it holds across other work). `GenerateInvoice` holds
the **account** row and, for the new invoice, only INSERTs (a brand-new row nothing
else can lock) — it never waits on an existing invoice row. So the two actions share
exactly one contended resource, the account row, and there is no opposite-order pair
to form a cycle. Account-before-invoice is the ordering both respect.

**Proven (WalletW3ConcurrencyTest):**

1. **Read-modify-write skew** — two concurrent `GenerateInvoice` for the same student
   with credit 2,000: total applied ≤ 2,000 (consumed once). Pull the account
   `lockForUpdate` → both read the credit and both apply it (double-spend) — the red
   step that makes the lock load-bearing.
2. **Cross-action deadlock** — `RecordPayment(S, invoice X)` racing
   `GenerateInvoice(S, new invoice Y)`: both complete, no 1213, final balance and
   credit correct.

The credit itself is consumed by the **charge** (which moves the balance positive),
not by the apply (a ledger-free settlement link); the lock’s job is to serialise the
read-credit→charge→apply so a second generation sees the already-consumed balance.

### Snapshot-timing footnote (why order-of-reads matters)

InnoDB REPEATABLE READ fixes a transaction's read snapshot at its **first consistent
(non-locking) read** — not at `BEGIN`. So a guard that must see a competitor's
committed row has to make its **first** read a *locking* read (`lockForUpdate`), or a
later plain `SUM` will read a snapshot taken before the competitor committed and miss
it. This is exactly why `RecordPayment` opens with `Invoice::lockForUpdate()` before
summing allocations, and it is a trap W3 must respect when it adds the account lock:
the account `lockForUpdate` must precede any plain read whose result feeds the
credit-apply decision.
