# Step 3 (initialise) — four constraints, before a line is written

**Date:** 2026-09-01 · **Source:** accumulated across step 4's build and its two cold reviews

These did not come from the plan. Every one of them was **found** — three by review or by a gate,
one by a tool noticing that `staging` had moved. They are written down together because they
accumulated across many separate turns, and step 3 is where all four land at once.

---

## 1. Mint the reference through `GatewayReference::mint()`

**Nothing routes otherwise, and the failure is silent.**

The webhook derives the school from the reference so its lookup runs with `SchoolScope` intact
rather than searching across schools and adopting whatever turns up. A reference built by string
concatenation is accepted by Paystack, the parent is charged, the delivery arrives — and the lookup
finds nothing. The webhook answers 200. The symptom is indistinguishable from a delivery for a
transaction we never issued: money taken, no payment recorded, one log line.

`GatewayTransaction` refuses, on `creating`, any reference that does not route to its own
`school_id`. The mistake fails at the write, before anyone is charged. Do not route around it.

⚠️ **BUT THE GUARD IS ELOQUENT-ONLY, AND AN EARLIER VERSION OF THIS DOCUMENT DID NOT SAY SO.** It
read "already enforced", which is a guarantee wider than the artifact — the same overclaim this
project keeps paying for, here in the constraint list written to prevent one.

`static::creating` does not fire on `DB::table()->insert()`, `->upsert()`, or any query-builder
write. `tests/Feature/Finance/GatewayTransactionSchemaTest.php` already inserts hand-built
references by raw builder and passes.

**This matters specifically for step 3.** `bin/ci-boundary-lint.php` forbids `DB::table` on a
`finance_` literal only OUTSIDE `app/Finance`, and step 3 lives inside it — so the component this
guard protects is the one permitted to walk around it. Use the model. If a raw insert is ever
genuinely needed, the trigger must exist first:
`docs/handoff/tickets/gateway-reference-guard-is-eloquent-only.md`, **required before step 3**.

Found by the second cold review, not by writing this list.

## 2. Round the gross UP

`G = (B + flat) / (1 − 0.015)` does not divide evenly, so `G` must be rounded to integer minor
units and the direction is a decision, not an implementation detail.

**Round up.** The kobo residual then falls on the credit side — the parent is charged at most one
kobo more, and the school receives at least the bill. Rounding down leaves the invoice a kobo short
**forever**, on an append-only table, on every payment that hits the residual. A bill that cannot
be cleared by paying it is the worst available outcome, and it is the one rounding-down produces.

## 3. Carry the bill on the transaction

The cold review's finding 4, and the one item still open.

The ruling fixes the fee **before** the charge, so the amount to credit is the **bill** — a number
known at initialise. What the webhook credits today is `gross − reported_fee`, which equals the bill
only if our up-front gross-up and Paystack's actual deduction agree to the kobo. They need not:
`G` is rounded (see 2), Paystack then rounds its own fee on the rounded `G`, and it caps local-card
fees.

`finance_gateway_transactions` has **no column for the bill**, so the residual cannot be measured —
only absorbed silently into the payer's balance.

**Add the column at initialise, credit against it, and let step 7 treat `gross − fee ≠ bill` as a
finding rather than as the answer.** One column now; a backfill against live money data later.

## 4. Check `reviewed_at` server-side

`staging` gained `finance_invoices.reviewed_at` on 31 August — NULL means the invoice is **not yet
released to the payer**. Nothing on the payment path checks it.

**Server-side, not by trusting the feed.** The parent's feed already withholds unreleased invoices,
and that is presentation. An initiate request naming an unreleased invoice must be refused by the
server, because a control the server never applies is theatre — and here the theatre would let a
parent pay a bill Internal Audit has not released.

**With the known-negative:** a POST naming an unreviewed invoice, asserting refusal, **and** a POST
naming a reviewed one asserting success. The second arm is what stops a guard that refuses
everything from passing as a guard that refuses the right thing — the shape `bin/db-exclusive`
shipped with.

**Open, for Dev 1:** whether release can be WITHDRAWN after initiation. If it can, there is a
time-of-check/time-of-use window between initiate and settle, and the webhook — not step 3 — has to
decide what to do with money that arrived for an invoice that was released when the parent started
and is not now. Not answered.

---

## How these were found, which is the part worth keeping

None came from planning:

- **1** — the boundary lint refused `withoutGlobalScope` in `app/Finance`, which forced a better design.
- **2 and 3** — a cold review reading the ruling more carefully than the implementer had.
- **4** — `bin/board`'s divergence section, on its first run, naming a commit that landed on
  `staging` after the plan was written.

Branch topology and other people's merges are invisible from inside a task. The check costs seconds.
