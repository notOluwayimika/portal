# `finance_gateway_transaction_events.payload` is not the whole payload

**Raised:** 2026-08-31 · **From:** `feat/paystack-webhook` · **Severity:** ticket

## What

The column is called `payload`, and since `2026_08_31_100000` it holds the delivery **minus the
fields named in `GatewayEventRedactor::STRIPPED_PATHS`** — currently the reusable
`authorization_code` and the card `signature`. The name now overstates what the column contains.

The removal is *recorded* — `redacted_fields` lists exactly what was taken out of each row, so no
reader has to infer it — which is why this is a ticket and not a defect. But a reader who meets
`payload` and not `redacted_fields` will reasonably believe they are looking at what Paystack sent.

## Why it was not fixed in the same change

Renaming the column means rebuilding the three events triggers, whose bodies name `payload` by
hand: the insert guard, the update guard and the redaction biconditional
(`redacted_at IS NOT NULL ⟺ payload IS NULL`). That is a trigger rewrite on the append-only table
that carries the audit trail for money, done for a naming improvement, on the same branch that
introduces the webhook. The risk and the benefit are not in the same league, and the table has not
reached production yet — so the rename stays cheap for a while longer.

## A second, smaller instance of the same thing

The events **insert guard**'s message reads:

> a delivery is stored as it arrived; it cannot be born redacted.

The clause is about `redacted_at`, and in that sense it is still exactly right. But "stored as it
arrived" is now literally false for any delivery with an authorization block. Worth a word change
whenever this ticket is picked up.

## The fix, when it is picked up

1. Rename `payload` → a name that admits the stripping (`stored_payload` reads honestly).
2. Rebuild the three events triggers against the new name, shape-verified from
   `information_schema` in the manner of `2026_08_27_100000`.
3. Reword the insert guard's message.

## Not the fix

**Dropping `redacted_fields` and stripping silently.** It would make the name accurate by making
the column less useful: a payload with no `authorization_code` and nothing saying why is
indistinguishable from a bank-transfer payload that never had one, and a reconciliation would read
our redaction as a fact about the payment.
