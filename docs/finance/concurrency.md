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

- **Account-scoped payment — no lock, deliberately (the fourth money action, ADR 0048).**
  `RecordAccountPayment` records a payment with **no invoice and no allocation**. The #94
  invoice-row lock exists to bound `Σ(allocations) ≤ total` for one invoice; with no
  allocation written that invariant is **vacuous here, not unprotected** — there is no
  per-invoice sum to serialise. The only shared state it moves is the account balance, via
  `SubledgerPoster::post`'s atomic increment (skew-free without an application lock, PROOF 4
  above), and nothing in the path is a read-modify-write: the amount comes from the request,
  never from a prior read. So it joins **neither** lock. This is stated explicitly because a
  reader who knows the three-call-site lock discipline would otherwise read a fourth money
  action with no lock as a missing guard. Its concurrency is bite-proven two ways in
  `AccountPaymentConcurrencyTest`: two account payments interleaved (both land, balance = sum, no 1213),
  and — the case no existing proof covered — an account payment racing `GenerateInvoice` for a
  student with **no account row yet**, where `GenerateInvoice`'s first-statement
  `StudentAccount::lockForUpdate()` on a non-existent row is a gap lock and the payment's
  `INSERT … ON DUPLICATE KEY` is an insert-intention wait — the shape insert-intention
  deadlocks are built from. It commits without deadlock; if that ever changes, the test says so.

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

## The gateway settlement takes an OUTER lock, before the invoice — added 2026-09-01

`SettleGatewayTransaction::settle()` (`app/Finance/Actions/SettleGatewayTransaction.php`) opens with

```sql
SELECT id, payment_id FROM finance_gateway_transactions WHERE id = ? FOR UPDATE
```

and **holds it across** `RecordPayment`'s invoice lock, `Sequences::next()`'s sequence-row lock and
the account increment in `SubledgerPoster::post`. So the newest money path locks in the order:

    finance_gateway_transactions → finance_invoices → sequences → finance_student_accounts

**Why the outer lock exists:** it serialises two deliveries for the same gateway transaction — a
Paystack retry racing the verify-on-return — so exactly one writes a payment. The compare-and-swap
on `payment_id IS NULL` is what makes the loser KNOW it lost; the lock is what makes them take turns.

**No inversion exists today.** Every `lockForUpdate` in `app/` was walked: nothing takes the invoice
first and then a gateway-transaction row, so the order above cannot form a cycle with the
account-before-invoice ordering recorded elsewhere in this document.

**WHY THIS PARAGRAPH IS HERE AT ALL.** The branch that introduced this lock reported that it added
*no new lock site*, on the grounds that the invoice lock is taken inside `RecordPayment` rather than
by the new code. That is true of the **invoice** lock and false of the **transaction** lock, and a
cold review caught the difference. Step 6 (verify-on-return) will be a second caller of this same
order; the next author needs a recorded ordering to check an inversion against, and "no new lock
site" would have been inherited as verified.

## The invariant that now lives in three call sites: **the invoice row is the serialization point for money actions on that invoice**

Every money action whose legality depends on an invoice's current state takes
`Invoice::lockForUpdate()` **first** — before any read the decision depends on — so
concurrent actions on the same invoice serialise at that one row and each sees the
others' committed effects rather than a stale snapshot:

- **`RecordPayment`** (#94) — locks the invoice, then sums allocations for the
  outstanding cap (`Σ(allocations) ≤ total`).
- **`ApproveCreditNote`** (Ph3) — locks the invoice, then re-checks the ceiling
  (`Σ(approved credits) ≤ total`) **and** that the invoice is still `issued` (Ph3b
  remediation — a credit note cannot be approved against a since-voided invoice).
- **`ApproveVoidRequest`** (Ph3b) — locks the invoice, then refuses if it is already
  void (one-way) and re-checks `VoidEligibility` (no payment / no approved credit note)
  before flipping it to void and posting the reversal.

This is one invariant, not three coincidences, and it is easy to lose in a refactor
because nothing names it in a single place — so it is named here. The general rule it
serves (**every approval re-checks its subject's legality under the lock**) is written
up for the maker-checker template in
[`docs/handoff/maker-checker-two-instance-diff.md`](../handoff/maker-checker-two-instance-diff.md).

A future money action that reads or mutates an invoice must join this convention:
invoice-row lock first, then the reads its decision depends on. (The retired one-step
`CancelInvoice` also followed it; its successor `ApproveVoidRequest` inherits it.)
