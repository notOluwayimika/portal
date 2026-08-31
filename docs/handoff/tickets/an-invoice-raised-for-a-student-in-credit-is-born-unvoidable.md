# An invoice raised for a student holding credit is unvoidable from the moment it is issued

**Status:** open, live. Found 31 August while checking two premises for Developer 2's note on
applying credit to an outstanding invoice. Not a defect in anything shipped this week — a property
of the system that nobody had drawn out.

## The mechanism, in two reads

`GenerateInvoice` applies carry-forward credit to the invoice it has just created
(`GenerateInvoice.php:337`), which writes ordinary `finance_payment_allocations` rows. The docblock
above it is explicit that this is "A SETTLEMENT LINK ONLY — it writes allocation rows and does NOT
post to the ledger".

`VoidEligibility::blocker()` refuses a void when any allocation exists (`:26`):

    PaymentAllocation::query()->where('invoice_id', $invoice->id)->exists()

**It does not inspect the allocation's source.** So an invoice generated for a student who held
available credit carries allocation rows at birth, and can never be voided — before anyone has
looked at it, disputed it, or paid anything toward it.

## Why it matters

Void is the instrument for "this charge should not have been raised". The students most likely to be
in credit are the ones whose families pay ahead, and a schedule misconfiguration hits every student
generated under it. The correction path is closed for exactly the accounts that were most in order.

The remaining remedy is a credit note, which is a different act with a different meaning on a
statement: void says the charge never should have existed, a credit note says the charge existed and
is being reduced. Forcing the second because the first is unreachable puts the wrong story in front
of a parent.

## What closes it

The choice is a decision, not a preference:

- Distinguish credit-derived allocations from payment-derived ones in `VoidEligibility`, and let a
  void unwind the former (returning the credit to the account) while still refusing the latter.
  This is the honest fix and the largest.
- Or refuse the void as today, and make the message say so accurately — see
  `the-void-refusal-calls-a-credit-application-a-payment.md`, which must be fixed regardless.

**Do not close it by stopping `applyCreditForward`.** Applying credit at generation is correct and
is the behaviour the parent-facing pay screen relies on; the defect is that the void check cannot
tell the two kinds of allocation apart.
