# Open asks — one message, ordered by date needed

**Date:** 2026-08-31 · **To:** Dev 1 · **From:** payments workstream

Five items accumulated since the fourteen-item reply. They are consolidated
here rather than sent as five arrivals, in a week that also carries cutover
Section 0.

**Two have dates. Three do not.** That split is the point of the ordering —
if you only have time for the top of this page, the top of this page is the
part with a deadline attached.

---

## Needs a date

### 1. Abilities + the guardian merge command — **due tomorrow, 1 September**

Both are ready and both are blocked on you, not on more work.

The abilities were approved as proposed in your fourteen-item reply, so this
is the landing, not the decision. The guardian merge command is on
`feat/guardian-merge-command`, pushed.

**What I need:** review and merge, or a named reason to hold. Tomorrow is the
date because resumption is the 6th and this sits underneath the parent-facing
payment path — anything landing after it has to be re-verified against it.

### 2. The gateway transaction needs to carry the BILL — **before step 3 (initialise) is built**

**This item changed after it was drafted, and it is now narrower — you have less to decide, not
more.** The original version asked you to choose between two fee arithmetics. That is settled and
you settled it: your §2 said the parent is charged bill + fee and the school receives the full
bill, which is solve-for-gross — `G = (B + flat) / (1 − 0.015)`, confirmed exactly against three
live sandbox charges. Nothing to re-rule.

What the same paragraph also said, and what nothing yet implements, is the second-order clause:

> the fee must be known **before** the parent is charged, so it is computed up front rather than
> read off the settlement.

Under that, the amount to credit against the invoice is **the bill** — a number fixed at
initialise. What the webhook credits today is `gross − reported_fee`, which equals the bill only if
our up-front gross-up and Paystack's actual deduction agree to the kobo. They need not: `G` is
rounded to integer minor units, Paystack then rounds its own fee on the rounded `G`, and it caps
local-card fees. `finance_gateway_transactions` has **no column for the bill**, so the residual
cannot even be measured, let alone reported — it is silently absorbed into the payer's balance on
an append-only table.

**What I need:** agreement that step 3 stores the bill on the transaction at initialise, so the
webhook credits *that* and the discrepancy report treats `gross − fee ≠ bill` as a finding rather
than as the answer. It is one column and it is cheap now; it is a backfill against live money data
later.

**Not urgent in the sense the original item was** — the webhook already refuses a delivery whose
amount or currency is not the one we initiated, so a wrong *charge* is caught. This is about a
kobo-level *rounding* residual, and step 3 does not exist yet. But step 3 is where the shape gets
set, so the decision wants making before it is written rather than after.

### 3. Item #8, promoted to sit with item 2 — **same date, same message**

#8 was left open in the fourteen-item reply. It is promoted here because it sits on the same part
of the flow as item 2, and answering them apart would cost you the context twice.

**Note the change above:** item 2 is no longer the fee-arithmetic question #8 was originally paired
with — that is settled. If #8 turns specifically on the arithmetic rather than on the initialise
shape, it may already be answered; say so and I will drop it.

---

## No date — record a view when you have one

These are real and worth deciding. None of them blocks the 6 September path,
and none should displace Section 0 this week.

### 4. Redact-at-write for `authorization_code`

The Paystack event payload carries `authorization_code` with
`reusable: true` — a token that can initiate a future charge. We store raw
event payloads for reconciliation, which means we would be storing a live
reusable payment credential in an append-only table with a redaction door but
no automatic redaction.

**My proposal:** strip it at write rather than at retention time. Store the
event with the field removed and a `redacted_fields` list recording that it was
removed, so the absence is *stated* rather than looking like Paystack did not
send it. The reconciliation value of the payload does not depend on that field.

**Why it is not urgent:** nothing reads it, and the retention door exists. But
the longer we run, the more of them accumulate, and redact-at-write is cheap
now and a backfill later.

**What I need:** a yes, or a reason to keep it that I have not thought of.

### 5. The credit note

Your reply settled that a credit banks against the cancelled invoice. That is
recorded and I am not reopening it. What is open is the narrower question of
what the parent *sees* when that happens — whether the credit surfaces on the
fees screen as a line, as a balance adjustment, or not at all until it is
applied.

**What I need:** eventually, a view. Not this week.

---

## What I am not asking you for

Recorded so it is visibly off your desk:

- **Apportionment** — on HOLD per your reply. Still on hold. Not raised here.
- **The report cadence** — daily → weekly, settled, implemented.
- **`--pending-hours`** — settled as not-24. No further input needed.
- **The collation tripwire** — you said now, it was taken, it is in.
