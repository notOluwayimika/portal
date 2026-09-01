# The void refusal calls a credit application a payment, and points at the wrong remedy

**Status:** open. Found 31 August. Small, and it grew teeth this week.

## The message

`VoidEligibility::blocker()` returns, for any allocation at all (`:26-27`):

> This invoice has a payment allocated to it and cannot be voided — reverse or refund the payment
> instead.

A credit-derived allocation — written by `GenerateInvoice::applyCreditForward` — is the same
`PaymentAllocation` row, so this is the message a bursar meets when the "payment" was never a
payment. See `an-invoice-raised-for-a-student-in-credit-is-born-unvoidable.md`.

## Why it is worse than a wording slip now

It is instruction, not description: it tells the operator what to do instead. Until 31 August the
advice was inert, because refunds had been cut from the launch scope and there was nothing to refund
with. **Refunds came back into launch scope on 31 August** with Executive Director approval
(`brookstone-answers-31-august.md` §1). So a bursar reading this sentence will shortly be able to
follow it — and refunding money the school never received, against an invoice settled from the
student's own credit, is the wrong act on the wrong ledger.

## What closes it

Split the refusal by allocation source and say the true thing in each branch. A credit-derived
allocation should say that the invoice was settled from the student's account credit, and name
whatever the eventual remedy is — which is decided by the ticket above, not by this one.

Whoever builds credit application against an outstanding invoice must fix this **in the same
commit**: that feature multiplies the number of invoices carrying credit-derived allocations, and
therefore the number of operators who meet this sentence.
