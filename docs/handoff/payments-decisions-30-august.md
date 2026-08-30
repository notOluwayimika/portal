# Payments — questions put to Brookstone, and what came back

**Asked and answered 30 August 2026.** Raised by Developer 2 (payments) as items 3–7 of their
30 August note. All five were business decisions that could not be answered from the code; each had
a working implementation behind it that had picked *something* in order to compile, and every one of
those would have become policy the moment the first real payment was taken.

**Three are settled and buildable. Two are still open and are named separately below so nobody reads
this document as five answers.**

---

## Settled

### 1. Partial payment — YES

A family may pay part of a bill and clear the rest later. Matches what the system already supports.

### 2. The parent bears the gateway fee

The parent is charged bill + fee; the school receives the full bill.

**The consequence to build against, because the natural mistake is the other way round:** the amount
charged at the gateway is **not** the amount recorded against the invoice. The fee never belongs to
the school, so `finance_gateway_transactions` and the `finance_payments` amount legitimately differ,
and the fee columns carry the difference.

Second-order: the fee must be known **before** the parent is charged, so it is computed up front
rather than read off the settlement.

### 3. A payment against a cancelled bill — stays as credit

It remains on the child's account and applies to a future bill. Build that.

---

## Open, and each is open for a different reason

### A. Do fees split across bank accounts? — GATES APPORTIONMENT

Brookstone answered the apportionment question (item 6) by saying a part-payment should reduce the
child's **total outstanding balance**, rather than being applied to tuition or transport
specifically.

That is coherent, and it is *less* work than the waterfall — it keeps allocation at invoice level,
where the system already works. **But it answers the parent-facing question rather than the one
asked.** If tuition goes to account A and transport to account B and a family part-pays, the money
physically lands in one account. "Reduce the total balance" does not say which.

**It is only a problem if fees split**, and that is question 3 of the four sent on 30 August, still
unanswered. One account → their answer is complete and the waterfall question dissolves. Fees split
→ unimplementable as written, and an order is needed after all.

**Nothing should be built on item 6 until that lands. The two answers are one answer.**

### B. Refunds — the deferral's premise may have expired

Brookstone said a refund may be issued with Executive Director approval, recorded in the system.

**Refunds are not new work.** The master plan carries `Refund`, `ProcessRefund` and `RefundPolicy`;
the approval table has them at row 4, Ph7; they were in the MVP cut as **S10** with U15 as their
screen. They were moved out on **9 August**, and the recorded reason was Brookstone's own: *"no
refund in the last three terms, so the first one is not in term one — deferred, not cancelled."*

**I told the project lead this was a new feature. That was wrong and I had not checked.** The
correction matters because it changes the question: this is not a new request to size, it is a
deferral whose stated premise may no longer hold.

**And there is no safe manual workaround — measured, not assumed.** `LedgerEntryType` has four
cases; `Reversal` has exactly one emitter, `ApproveVoidRequest`, which reverses a *charge*. Nothing
reduces a credit except allocating it to an invoice. So if the school pays a refund out by bank
transfer there is no correct way to record it, and the child's account keeps showing credit the
family no longer has. The only trick available — raising a bill for the refunded amount to consume
the credit — balances the books while showing a parent a charge they do not owe.

So the question put back to Brookstone is binary: **do you expect to issue any refund this term?**
No → the deferral stands. Yes → the Ph7 work is pulled forward and something moves.

---

## The fifth answer, corrected back to them

### Retention — they said "more than seven years", and that conflates two records

Their reasoning is right for the **payment record** — six years secondary plus one foundation, so a
child's history spans seven. It holds no card data and is already append-only. Keep it.

It is wrong for the **provider payload**, which is what the question was about: payer name, email,
and card BIN and last four. Its purpose is dispute investigation, measured in months. Seven years of
card fragments across every backup and every copy of production is exposure with no benefit, and
NDPA expects data held no longer than the purpose requires.

**The system already separates them** — Developer 2's `redacted_at` door nulls the payload while the
row and every financial fact on it survive. Two clocks, not one: 7+ years on the payment record, a
much shorter period on the payload.

**12 months was proposed as the payload figure and it is not a measured number.** Before it becomes
policy it should be checked against Paystack's own dispute and chargeback window; if theirs is
longer, theirs wins.

---

## What Developer 2 can build

Buildable: partial payment, the fee model, credit on a cancelled bill, `PaymentReceived`, and
everything downstream of step 4.
Blocked on Brookstone: apportionment (via the bank-accounts question), the payload retention figure.
Blocked on Developer 2: the stuck-pending threshold and Paystack's dispute window.
Not their scope: refunds.
