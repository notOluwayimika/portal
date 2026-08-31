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

### 2. The fee ruling — **needed before the webhook handler ships (I am building it today)**

Your reply settled *who bears the fee*: the parent. What it did not settle is
the arithmetic, and the calibration against three live sandbox charges turned
up that the two readings are not equivalent.

Paystack charges **1.5% + ₦100** (flat waived under ₦2,500) measured on the
**gross** — the amount actually charged, not the amount you wanted to net. So
"parent bears the fee" has two implementations:

- **Add the fee on top** — parent is charged `B + fee(B)`, school nets slightly
  *less* than `B`, because the fee is then recomputed on the larger gross.
- **Solve for gross** — parent is charged `G = (B + flat) / (1 − 0.015)`,
  school nets exactly `B`.

The second is what "the parent bears the fee" means if the school is meant to
receive the invoice amount whole. The first leaves a small shortfall on every
payment. I have the formula confirmed exactly against three sandbox charges
across all three regimes, so either is implementable today.

**What I need:** which one. I will build the handler with both arms behind a
required explicit input so it cannot ship defaulted, but one of them has to be
chosen before the parent screen goes out.

### 3. Item #8, promoted to sit with the fee ruling — **same date, same message**

#8 was left open in the fourteen-item reply. It is promoted here because it
turns on the same choice as item 2 and answering them apart would cost you the
context twice. If you rule on the fee arithmetic, please rule on #8 in the same
breath.

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
