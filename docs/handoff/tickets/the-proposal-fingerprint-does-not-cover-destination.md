# The proposal fingerprint does not cover the bank-account destination

**Status:** STILL OPEN, and NARROWED. Raised by the cold review of `feat/u10-allocation-screen`,
2026-08-22. S11 commit 1 landed the snapshot this ticket expected to close it (2026-08-29) — see
"What S11 commit 1 changed" at the bottom. It did not close it, and the closing paragraph that said
it would was wrong about the mechanism as well as the timing. Read that section before acting on
anything above it.

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

## What S11 commit 1 changed — and why this ticket did not disappear with it

`finance_invoice_lines.bank_account_id` landed on 2026-08-29
(`2026_08_29_110000_finance_invoice_lines_destination_account`), nullable, with a composite
`(bank_account_id, school_id)` foreign key, and **both writers populate it**:
`FeeScheduleLineMapper` snapshots the fee item's account at issue, and the generate modal sends the
account the operator selected per charge line. A reduction line carries null. The column is settable
only at INSERT — `finance_invoice_lines` is append-only at the model and at the database — so a
written destination can never move.

**`AllocationProposal` still derives the destination through `fee_item_id`.** Nothing about this
ticket's mechanism has changed yet, and the reason is stated in that class's own docblock: every line
issued before the migration has the column NULL and always will (there is no backfill), so switching
the read to the column would report every historical invoice `unrecorded` and black out the mismatch
banner the screen exists for. The replacement is a three-valued read — the line's own account, else
the live lookup for a pre-migration line, else `unrecorded` — which needs its own commit and its own
arms because the fallback is exactly where a wrong answer would look right.

### The prediction in the old closing paragraph was wrong twice, and both are worth keeping

It said: *"When S11's snapshot lands … the destination stops being a live lookup and this ticket
disappears with it — the line is immutable, so there is nothing left to go stale."*

1. **A column landing is not a reader switching.** The snapshot exists and this ticket is still open,
   because the write side and the read side were never the same commit and could not have been —
   the reader has to keep answering for lines that predate the column.
2. **"The line is immutable, so there is nothing left to go stale" will be true of the SNAPSHOT half
   and false of the FALLBACK half**, permanently. Every pre-migration line still resolves through
   `fee_item_id`, so once the reader is three-valued, part of every proposal is a live lookup
   forever, and the fingerprint question this ticket raises applies to exactly that part.

So the shape of what remains is now known, which it was not when this was filed:

- **For post-migration lines** — closed by construction once the reader is switched. The line is
  immutable; there is nothing to hash that can move.
- **For pre-migration lines** — the original ticket, unchanged and permanent. Adding the resolved
  destination ids to the hash is still the fix, and the trade is still the one nobody has measured:
  it makes the token stricter, so a fee-item edit anywhere in the school starts forcing reloads on
  open allocation screens.

### One premise in this ticket was measured and is narrower than written

Above, this ticket says `finance_fee_items.bank_account_id` "is **mutable** — a fee item's account
can be edited while the allocation screen is open". **Through the application it cannot.**
`finance_fee_items_parent_state_guard_upd` refuses any UPDATE whose parent schedule is not a `draft`,
only `active` schedules are billable (`FeeScheduleStatus::billable()`), and nothing returns an active
schedule to draft — `RejectFeeScheduleChange` restores only `pending_approval`. Measured, and pinned
by `InvoiceLineDestinationTest`'s snapshot arm, which asserts the refusal before forcing the repoint
through the database.

That does **not** retire the ticket, and the reason is the more interesting fact:

- the freeze is a **coincidence of two independent rules**, not a stated one. The trigger's rule is
  "the parent must be a draft"; nothing ties it to "cited by an invoice". It holds only while
  `billable()` is exactly `[active]` — and that method's docblock says the set MOVES — and while no
  correction path returns a schedule to draft. Either change makes the edit reachable again with
  nothing going red, because no test asserts the two rules as one property;
- a raw UPDATE, a migration or tinker meets no guard at all;
- and **the staleness this ticket is actually about was never only mutation**. A superseded
  schedule's item still resolves, and a free-text line resolves to nothing — which is the reading the
  screen shows as `unrecorded` and which no immutability rule upstream would fix.

## Related

The destination derivation and its three-valued nature are argued in
`app/Finance/Services/AllocationProposal.php`'s class docblock, which was corrected in the same
commit as this section: it asserted that `finance_invoice_lines` "has no `bank_account_id`", which
S11 commit 1 made false.
