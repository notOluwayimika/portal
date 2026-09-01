# Discrepancy report — a FIFTH class: released at initiation, not released at settlement

**Raised:** 2026-09-01 · **From:** `feat/paystack-webhook`, second cold review · **Severity:** required in step 7's first version

## Why this is written now rather than when step 7 is built

Step 7 is designed around four classes. This is a fifth, and it is being written down **today, while
the reasoning is in front of us**, because a class added "later" to a report that already looks
complete is exactly the intention that evaporates — and it would evaporate into an artifact whose
appearance of completeness is the reason nobody re-examines it.

## The class

`staging` gained `finance_invoices.reviewed_at` on 31 August: NULL means the invoice is **not yet
released to the payer**. The release check belongs at INITIATION (step 3, constraint 4). But release
is a school-side administrative act and can move **after** a parent has started paying.

So there is a time-of-check/time-of-use window between initiate and settle:

- the parent starts paying an invoice that IS released;
- Internal Audit withdraws release, or the invoice is voided;
- the delivery arrives and the money has already moved.

## What the webhook does about it: NOTHING, deliberately

Refusing at settlement does not un-take the money. It only detaches the evidence from the invoice
the parent actually chose, leaving a `could_not_book` and a human reconciliation — which is
**strictly worse** than recording the payment. The correct outcome is a recorded receipt plus an
alert, not an orphaned charge. Same reasoning as §11 decision 4.

That is a decision, and it is recorded at the write site rather than left implicit.

## What the report must therefore do

Surface **settled gateway transactions whose invoice changed release or void state between the
transaction's `created_at` and its `paid_at`.** Both directions matter, but the withdrawal direction
is the one nothing else will ever notice: the payment is correctly recorded, correctly allocated,
and sitting on an invoice the school has since decided is not payable. No gate fires. No test fails.
It is only visible by comparing two timestamps that no current query compares.

## The related hole, which is not this one

`RecordPayment` reads `isVoid()` off the **unlocked** invoice instance before taking
`lockForUpdate()`, and never re-checks the locked row. A void committing in that window is missed
and the payment lands on a void invoice — in the direction the system has already decided to refuse.
Pre-existing on the bursar path; gateway settlement is now the caller with the widest window between
those two statements. One line, tracked separately in
`docs/handoff/tickets/record-payment-void-check-reads-unlocked-instance.md`.

**Do not fold that into this ticket.** One is a report the system does not yet produce; the other is
a guard that exists and reads the wrong row. Fixing the second does not close the first, and a
reader who sees them merged will assume it does.
