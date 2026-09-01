# The webhook records a payment from the webhook body — `verify()` is never called

**Status:** **FIXED** on `fix/webhook-settles-from-verify` (2026-09-01). Found the same day, while
checking whether step 6 could be built off `staging`. The two halves are in one branch deliberately:
a finding and its fix travelling separately is how a ticket outlives the thing it describes.
**Severity:** **fix** — ship-blocking for step 6, not a stop for step 4. Not remotely exploitable
without the Paystack secret. Read the "what is NOT established" section before escalating it.
**Bites in:** every environment, on the merged step-4 path.

## The measurement

`PaystackWebhookController::__invoke` calls
`$settle->handle($transaction, SOURCE_WEBHOOK, $event, $body)`
(`app/Finance/Http/Controllers/PaystackWebhookController.php:130`), and
`SettleGatewayTransaction::handle` passes `$body['data']` straight to `settle()`
(`app/Finance/Actions/SettleGatewayTransaction.php:97`), which writes the payment.

**`PaystackClient::verify()` is never called on this path.** The only `verify` in the controller is
`$signature->verify(...)` at line 59 — the HMAC check, a different thing entirely.

## What the code contradicts

Three places state the opposite rule, and they are not casual asides — each is the load-bearing
paragraph of its own file:

1. `PaystackClient`'s class docblock: *"the flow is: signature-check the webhook, then **call
   `verify()` and record from THAT**. A handler that writes a payment from webhook contents is
   trusting the wire for the one field that becomes an irreversible money row."*
2. `PaystackTransaction`'s docblock: *"The webhook says 'look again'; this says what is true.
   **Nothing may record a payment from webhook contents alone.**"*
3. `docs/handoff/decisions/webhook-arrives-but-verify-is-unreachable.md`, whose step 3 is *"Call
   `verify()`. On `PaystackUnavailable`, stop there."*

The third is the sharpest, because **that document's entire subject is a failure case the shipped
code cannot reach.** It reasons carefully about what to do when a genuine webhook arrives and
`verify()` is unreachable — a state that requires a `verify()` call to exist. It was decided in
advance, on 2026-08-29, *"before the handler exists"*, and then the handler shipped without the call.
`docs/handoff/reports/feat-paystack-webhook.md` names four things it did not do and this is not
among them, so it is an unrecorded gap rather than a deliberate deferral.

## What is NOT established — read this before treating it as a breach

**No external forgery.** The signature is HMAC-SHA512 over the raw body using the Paystack secret.
Without that secret a body cannot be produced that the endpoint will accept, so nothing here is
reachable by an attacker who does not already hold it.

**The money guard still bites.** `matchesCharge()` refuses a delivery whose `amount` and `currency`
do not equal the row's own, and the compare-and-swap on `payment_id IS NULL` makes settlement happen
at most once. A wrong-amount or replayed delivery is already refused.

Presence of the gap is measured; **reachability by an outside party is not, and is very likely nil.**

## Why it is still worth fixing before step 6

**It widens what one secret is worth.** `PaystackClient`'s own docblock records that the secret key
is both the API credential and the webhook verification key. Under the documented design a leaked
secret buys API access. Under the shipped design it also buys the ability to mint a signed body for
a known reference and have this system record money that was never collected — bounded only by the
amount matching a row whose amount the payer was told at initiation. That widening is the reason the
record-from-`verify()` rule exists, and it is invisible from inside the webhook path.

**And it decides step 6's design, which is why this is being raised now.** Step 6 (verify-on-return)
is the first path that calls `verify()` for real. Built to the documented rule, the two settlement
callers would disagree about what the authority is: one records from the provider's answer, one from
the notification. That is **two implementations of one rule** — the third instance of that shape
found on 2026-09-01, after `isReviewed()`/`scopeReviewed()`/the inline `whereNull`, and after the
second fee calculator that was refused for the same reason. Each duplicate agrees on the day it is
written and nothing reveals the drift until the definitions move apart.

## The decision this needs, which is not the implementer's to take alone

Either:

- **(a) Step 4 is brought to the documented rule** — the webhook calls `verify()` and records from
  it, and the fifth failure row in the decision document becomes reachable and needs its handling
  built. This is what every docblock currently promises a reader.
- **(b) The rule is deliberately relaxed for the webhook** — "signature + `matchesCharge` + CAS is
  sufficient; `verify()` is for the return path and the discrepancy report". This may well be the
  right call on 2026-09-01 with five days to resumption: it adds no outbound network dependency to
  the delivery path. **But then the three docblocks and the decision document are wrong and must be
  corrected**, because a rule stated in three places and enforced in none is the wallpaper this
  project keeps paying for — and here the wallpaper is what a reader would rely on when deciding how
  hard to look at the settlement path.

What must not happen is step 6 being built to (a) while step 4 sits at (b) with the documents
describing (a). That leaves three spellings of the trust boundary and no way to tell which is meant.

## The ruling, and what was built

**Ruled (a) on 2026-09-01 by the project lead: step 4 comes to the rule.** The reasoning, recorded
because it is the part that generalises: HMAC proves the sender holds the secret and says nothing
about whether the contents match Paystack's ledger. An attacker with the secret can sign a body
claiming any charge; nobody can make Paystack's own API return a transaction that does not exist.
Relaxing instead would have meant accepting, five days before a live payment path, that one leaked
key mints payments never collected — and recording that acceptance by *weakening three docblocks*,
which is a security-posture change made under deadline pressure and documented as a correction.

It was cheap because the hard part was already decided. What usually makes a `verify()` call
contentious is what to do when it is unreachable, and that was settled on 2026-08-29. The design was
complete; the code simply did not execute it.

Built:

- **`PaystackClient::verifyWithPayload()`** — one GET returning the validated DTO *and* the body it
  was read from. Both are needed and neither substitutes: the DTO construction IS the validation,
  and `finance_gateway_transaction_events` exists to hold what the provider actually said, so a
  payload rebuilt from a DTO would be a paraphrase stored as evidence. `verifyRaw()` keeps its
  prohibition — the new method does not route through it — and its docblock now states the real
  rule: no production caller may hold a verify body the DTO construction has not passed over.
- **`SettleGatewayTransaction::settleFromProvider()`** — the only path that writes money.
  `handle()` records the delivery, gates on `charge.success`, and calls it. **Step 6 calls the same
  method**, which is the point: two entry points, one implementation, so the duplicate-rule shape
  does not get a fourth instance in the trust boundary itself.
- **`VerifyUnavailable`** — the 29 August decision executing for the first time.
- **`NotSuccessfulAtProvider`** — the case the fix exists for.

**The suite could not previously tell.** All 20 tests passed with no HTTP fake at all, because every
fixture made the webhook body and the verify response identical — the degrees of freedom had
collapsed and the axis was not testable. The arm that closes it makes them DISAGREE (`fees` 999,999
on the wire against 72,062 from the authority) so the payment amount names which body was read.
