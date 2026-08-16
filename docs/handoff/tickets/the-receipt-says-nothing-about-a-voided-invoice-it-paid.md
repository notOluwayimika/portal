# TICKET — the payment receipt names a voided invoice exactly as it names a live one

**Status:** open, not implemented, **unverified**. Raised by the cold review of
`feat/finance-payment-receipt` (U11). Deliberately not fixed there: what the receipt *should* say
about a reversed charge is a policy question about a document a parent keeps, not a rendering
tweak, and answering it in the same commit would fold an undiscussed decision into a screen.

**Severity:** ticket. It is recorded as an **unverified** gap rather than a defect — the state is
reachable by construction (below), but nobody has driven it or asserted it.

## The state, and that it is reachable

`PaymentAllocation`'s own docblock (`app/Finance/Models/PaymentAllocation.php:16-19`) states the
rule this ticket turns on:

> The append-only money→invoice link. **Survives invoice cancellation** (a cancelled invoice with a
> prior allocation leaves a credit on the account — the payment is never un-linked, only the charge
> is reversed in the ledger).

So an allocation pointing at an invoice whose `status` is `InvoiceStatus::Void`
(`app/Finance/Enums/InvoiceStatus.php:21`) is a normal, intended state — produced by the ordinary
Ph3b path: record a payment against an invoice, then have a maker submit and a checker approve a
void request.

## What the receipt does with it

`PaymentReceiptController::document()` renders each allocation as
`invoice_number` / `academic_context` / `amount`, reading `$a->invoice?->displayNumber()`. It reads
**no status**, and `receipt.tsx` renders no badge. So a receipt for a payment whose invoice has
since been voided prints, with no qualification:

| Invoice | Period | Applied |
| --- | --- | --- |
| 000002 | JSS 1 · A · 2026/2027 · First Term | ₦10,000.00 |

…identical to a receipt for a live charge, under the heading **"What this paid for"**, followed by
"The full amount has been applied to the invoices above."

That statement is not obviously false — the money *was* applied to that invoice, and the allocation
still stands. But the charge it settled has been reversed and the value now sits on the account as
credit, and the document says nothing about it.

## What is NOT claimed here

- **Not** that any test fails. Nothing in `PaymentReceiptTest` constructs this state; that is the
  gap being recorded.
- **Not** that the drive saw it. `docs/handoff/reports/feat-finance-payment-receipt.md` § 7 lists
  this among what was not driven.
- **Not** that a particular wording is right. `void` is a *document* state and settlement is a
  separate axis (`InvoiceSettlement`'s "TWO ORTHOGONAL AXES, never one badge"), so a receipt that
  slapped a red "VOID" on the row would be making its own claim about a second axis.

## What answering it needs

1. A decision on what a receipt should say: name the void beside the invoice, say the money moved
   to the account, both, or nothing on the grounds that the receipt records the act at the time it
   happened and later reversals belong on the statement.
2. Whatever is decided, a test arm building the state through the real Actions
   (`RecordPayment` → `SubmitVoidRequest` → `ApproveVoidRequest`, maker ≠ checker), and a drive
   showing the rendered row — the fixture already reaches this state via
   `DriveFinanceStates::approvedVoid`.
