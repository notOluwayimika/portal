# §7's fifth failure case — the webhook is genuine and `verify()` cannot be reached

**Decided:** 2026-08-29, on `feat/gateway-paystack-client`, **before** the handler exists.
**Belongs in:** step 4's brief, and §7's failure table as a fifth row.

## Why this case exists at all

`PaystackClient::verify()` is the authority and the webhook is not. A signature proves a body came
from the holder of the secret; it proves nothing about whether the transaction succeeded, and the
`amount` inside it is a number an attacker would very much like us to trust. So the rule is:
signature-check the webhook, then **call `verify()` and record from that**.

That rule is deliberately stronger than §9, which says it only about the return redirect. It is right
— a redirect is a browser we do not control and a webhook is a server we do not control, and neither
is evidence — **but it puts a network dependency on the webhook path**, and §7's table has no row for
what happens when that dependency fails.

## The case

> A webhook arrives. The signature is valid. `verify()` throws `PaystackUnavailable` — Paystack
> timed out, 5xx'd, or answered unreadably.

Every obvious response is wrong:

- **Record the payment from the webhook body.** No. That is the rule this whole design exists to
  hold, and the one moment it is most tempting to break is the one where the alternative is
  inconvenient. `finance_payments` is append-only; a payment recorded on an unverified claim cannot
  be taken back.
- **Return a 4xx.** No. Paystack reads a 4xx as "this endpoint is broken" and retries **harder**, on
  a schedule we do not control. We would be asking for more traffic at the exact moment the thing we
  need is unreachable.
- **Return a 5xx.** Same retry behaviour, and it is also a lie: our endpoint is fine.
- **Drop it silently.** No. A delivery we accepted and discarded is money we may never look for
  again, and nothing would record that it happened.

## The decision

**Acknowledge with 200, persist the delivery, leave the transaction `pending`, and let the recovery
paths pick it up.**

Concretely, for step 4:

1. **Verify the signature first.** An invalid signature is refused before anything else happens and
   is never stored against a transaction (that is a separate, already-noted gap: an unmatched or
   unsigned delivery has nowhere to go — see the retention ticket's §"what it cannot hold").
2. **Write the delivery to `finance_gateway_transaction_events`** with `source = 'webhook'`. It
   happened; the raw body is evidence, and this table exists precisely so a delivery is kept whether
   or not we could act on it.
3. **Call `verify()`. On `PaystackUnavailable`, stop there.** Do not write a payment. Do not move
   the status. Do not mark it `failed` — **"we could not find out" is not "it failed"**, and that
   confusion is the one this exception type exists to prevent.
4. **Return 200.** We have durably recorded what arrived; asking Paystack to send it again buys
   nothing, because the thing that failed was our call *out*, not their call *in*.

## Why leaving it `pending` is safe rather than lazy

This is the case `finance_gateway_transactions` was shaped for, and it is worth naming the two design
decisions that make the answer above work:

- **`success` is terminal; `pending` is not.** A transaction left `pending` can still be moved by a
  later verify-on-return or a later webhook. Had `pending` been terminal, this case would have no
  recovery at all.
- **Every provider-reported fact is write-once, NULL → value.** So a later path filling in
  `paid_at`, `payment_id` and the settlement columns cannot be confused with a rewrite, and the
  compare-and-swap on `payment_id` still makes settlement happen exactly once no matter how many
  paths race to do it.

## What picks it up

Three, in order of speed:

1. **Verify-on-return** — the payer's browser comes back to us and we verify then. Fastest, and free.
2. **A later webhook.** Paystack retries its own deliveries; the next one may find `verify()` healthy.
3. **The discrepancy report (§6 step 7)** — the backstop, and this case is exactly one of its
   classes: *a transaction stuck in `pending` beyond a stated age*. Note this is `pending` only —
   `failed` and `abandoned` are ANSWERED states and are excluded, per the decision recorded in
   `GatewayTransactionStatus`'s docblock. **If step 7 ships without the stuck-pending class, this case
   has no backstop**, which is the dependency worth stating out loud.

## What is still open, and is not this decision to make

**How many times, and for how long, do we keep re-verifying a `pending` transaction before a human is
told?** The report surfaces it; nobody has said what the age threshold is or who receives it. That is
the same class of question as the retention period — a policy, not an engineering choice — and it
belongs with whoever owns the runbook.
