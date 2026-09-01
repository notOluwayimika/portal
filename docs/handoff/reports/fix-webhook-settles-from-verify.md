# `fix/webhook-settles-from-verify` — the webhook stops being the evidence

**Base:** `staging` @ `ead8d627`. **Branch:** `fix/webhook-settles-from-verify`.
**Shape:** three source files, one test file, one ticket. One commit.
**Tier:** full review — money path, trust boundary, and a weakened-then-repointed test file.

## The defect

Step 4 shipped calling `settle()` with the **webhook body**. `PaystackClient::verify()` was never
called on that path; the only `verify` in the controller was the HMAC check. Three docblocks and a
decision document forbade exactly this, one of them describing a failure case that could not occur
because the call it depended on was absent.

**It was not exploitable from outside** — the signature is HMAC over the raw body with the Paystack
secret, and `matchesCharge()` plus the compare-and-swap still bit. What it changed was **what one
secret is worth**: the same key is the API credential and the webhook verification key, so a leak
also bought the ability to have money recorded that was never collected.

## What was built

`PaystackClient::verifyWithPayload()` — one GET, returning the validated DTO **and** the body it was
read from. The DTO construction is the validation and the events table needs the real bytes; a
payload rebuilt from a DTO would be a paraphrase stored as evidence. `verifyRaw()` keeps its
production prohibition (the new method does not route through it) and its docblock now states the
rule it was actually protecting.

`SettleGatewayTransaction::settleFromProvider()` — the only path that writes money. **Step 6 calls
this same method.** Written twice, the two callers would agree the day they were written and drift
the first time either changed, which is the shape this codebase hit three times in one day.

Two outcomes: `VerifyUnavailable` (the 2026-08-29 decision executing for the first time) and
`NotSuccessfulAtProvider` (the case the fix exists for).

## Why the suite did not catch it, which is the finding under the finding

**All 20 tests passed with no HTTP fake at all** — nothing ever called out. Every fixture built the
webhook body and the verify response from the same literal, so *both implementations pass every
arm*. The fixture's degrees of freedom had collapsed until the axis under test did not exist. That
is the same class as the single-arm preview and the same-parity ids, in the highest-stakes file in
the module.

The arm that closes it makes them **disagree**: `fees` 999,999 on the wire against 72,062 from the
authority, so the payment amount names which body was read (4,065,438 against 3,137,501). Every
guard arm — amount mismatch, negative fee, fee not reported, fee at-or-above — now varies the
**verify** response, because that is what `settle()` reads; varying the webhook would prove nothing.
`Http::preventStrayRequests()` is on in `beforeEach`, so a forgotten fake fails naming the URL
rather than reporting a DNS error.

## Verified — 25 tests, 141 assertions, green; each guard suppressed and watched red

| suppressed | result |
|---|---|
| `settleFromProvider` reverted to settling from `$body['data']` — **the defect restored verbatim** | **RED ×13** (12 failures + 1 error) |
| the `isSuccessful()` corroboration check | **RED ×1**, and precisely the corroboration arm |
| `VerifyUnavailable` collapsed into `NotSuccessfulAtProvider` | **RED ×2** — both arms that distinguish the two refusals |

The third row is the "assert which negative" discipline paying: both outcomes answer 200 and book
nothing, so an arm checking only that nothing was written would have passed the collapse. They mean
opposite things to whoever reads the discrepancy report — *ask Paystack what is wrong with our
integration* against *ask why they charged a different amount*.

Counted errors as reds. The 13-red run was 12 failures and one **error**; under a summariser reading
only the failures bucket it would have been 12, and the erroring arm is the redaction one.

## Deviations and things to attack

- **One dataset case moved and changed its expected outcome.** `'no amount at all'` asserted
  `amount_mismatch`; a verify response with no `amount` is now refused by `verifyWithPayload` as
  **unreadable**, before `matchesCharge` is reached, so it is `verify_unavailable`. It is now its
  own arm with the reasoning written down. *Absent must fail, not pass* is unchanged — what moved is
  which refusal it earns, and that is asserted by name.
- **A replay re-verifies.** The idempotency arm now expects **4** event rows, not 2. Deliberate: a
  redelivery is exactly how a transaction recovers when an earlier verify was unreachable, so
  short-circuiting on `payment_id` would close the recovery path the unavailable branch depends on.
  Worth a second opinion — it costs an outbound call per replay.
- **The verify payload is redacted too**, and there is now an arm for it. It is a second copy of the
  authorization block arriving by a different door; a redaction covering only the webhook would have
  stored the reusable token while every existing assertion stayed green.
- **Missing `currency` still defaults to NGN** in `verifyWithPayload` (pre-existing). So a verify
  answer with no currency matches an NGN row rather than being refused. Not changed here, not
  covered by an arm, named because it is the sibling of the `amount` case above.
- **The ticket is included in its resolved form**, so the finding and the fix travel together. The
  earlier docs-only branch `docs/webhook-records-without-calling-verify` is **superseded** — it is
  pushed and gated as the record of when this was found, and should be closed unmerged rather than
  merged, or the same file lands twice.
- **Log keys still say `paystack.webhook.*`** inside `settle()`, which step 6 will also reach. Left
  alone rather than renamed mid-fix; a stale key is a smaller lie than a churned one, but it is a
  lie and it should be `paystack.settle.*`.

## Not done

- **Step 6 itself.** It now has its entry point (`settleFromProvider`) and is unblocked.
- **No browser drive** — no screen changes here.
- **No live-provider arm.** Every fake is local. The suite cannot prove Paystack's verify body has
  the shape assumed; the sandbox capture command is what would.
