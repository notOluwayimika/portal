# The proposal fingerprint does not cover the bank-account destination

**Status:** OPEN. Raised by the cold review of `feat/u10-allocation-screen`, 2026-08-22.

## What the token covers

`AllocationProposal::fingerprint` hashes:

- the payment uuid,
- the unallocated amount and its currency,
- and per offered invoice `id | outstanding | allocatable | proposed`.

`AllocatePayment` refuses a stale token before comparing any figure, so an operator whose position
moved under them is told to reload rather than being asked to justify a change the world made. That
mechanism works and is armed (PROOF 8).

## What it does not cover

**The bank-account destination.** It is derived through `finance_invoice_lines.fee_item_id` →
`finance_fee_items.bank_account_id`, and that column is **mutable** — a fee item's account can be
edited while the allocation screen is open. None of it is in the hash.

So an operator can read `Same account` on a row, have the fee item repointed underneath them, and
submit against a destination reading that is no longer true, **with no reload prompt** — the same
class of staleness the token exists to catch everywhere else on the screen.

## Both halves, because only one of them is alarming

**There is no legality consequence.** The destination decides nothing. It gates no refusal, enters no
comparison, and changes no amount: `AllocatePayment` never reads it. Every rule that governs what may
be written — the outstanding cap, the payment headroom, the currency match, the override marker —
depends on figures the token *does* cover, and all of them are re-derived under the account-row lock.
A stale destination cannot produce an illegal row or a wrong amount.

**And it is the one figure on the screen the token does not defend.** The screen exists in part to
show a bank-account mismatch rather than allocate across it silently (MVP cut brief §9 item 6), so
the destination is not decoration — it is one of the two things the operator is being asked to look
at. A reading that can go stale without saying so is a weaker version of exactly the guarantee the
fingerprint was added to provide.

Say both. Reporting only the first makes the omission sound deliberate; only the second makes it
sound dangerous.

## The fix, if it is wanted

Add the resolved destination account ids to the canonical string the hash is built from. It is a
small change to one method — but it makes the token stricter, so a fee-item edit anywhere in the
school starts forcing reloads on open allocation screens. Whether that trade is worth it is a
judgement about how often fee items move mid-term, which nobody has measured.

An alternative that does not touch the token: have the screen re-fetch the proposal on submit and
compare only the destination, warning rather than refusing. More moving parts, no forced reload.

Neither was attempted on the branch that raised this.

## Related

The destination derivation and its three-valued nature are argued in
`app/Finance/Services/AllocationProposal.php`'s class docblock. When S11's snapshot lands and the
account travels onto the invoice line, the destination stops being a live lookup and **this ticket
disappears with it** — the line is immutable, so there is nothing left to go stale.
