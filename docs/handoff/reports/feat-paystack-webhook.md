# Report — `feat/paystack-webhook` (step 4)

**Base:** `origin/staging` · **Branch:** `feat/paystack-webhook` · **Commits:** 2
**Shape:** 8 new PHP files, 3 modified, 1 migration, 1 test file (12 tests), 1 ticket, 1 route.

**This is full-review tier — it touches money, a migration, and `school_id` isolation.
Recommend a cold session before merge.** No subagent review was run: the standing instruction in
this session is not to spawn agents unless asked.

---

## Deviations from the plan as specified

Three, all at the top because they are the parts most worth a second opinion.

### 1. The fee-bearer input was specified and is NOT built — because the ruling is settled, not because the policies agree

The plan called for "both fee-bearer arms behind required explicit input". No knob is built — but
**not for the reason I first gave.** My first version of this section claimed the payment amount was
bearer-independent. **That is false**, and it was caught in review:

| Policy | Parent charged | Paystack takes | School nets | Invoice credited |
|---|---|---|---|---|
| **Parent bears** (the ruling) | `G = (B+flat)/(1−0.015)` | `f` | `G − f = B` | `amount − fees` = `B` |
| School absorbs | `B` | `f` | `B − f` | **`amount`** = `B` |

The regimes agree on what the school **nets** and disagree on what the payment **credits**. Under
absorb the parent has paid the bill in full and the fee is the school's cost, not a shortfall on the
payer's account — recording `amount − fees` there would leave a parent who paid ₦100,000 against a
₦100,000 invoice owing ₦1,600, permanently, on an append-only table.

So the knob is genuinely unnecessary, but **because Dev 1 settled the policy as parent-bears**
(`docs/handoff/payments-decisions-30-august.md` §2, 2026-08-30), not because the policies coincide.
The reason is now on the record at the subtraction itself, so the line moves if the ruling ever does.

**This also propagates to §11 decision 4** — the credit banked against a cancelled invoice is the
recorded payment amount, and that amount differs between the regimes.

**This is the one to check hardest.** If the intent was for the webhook to record the GROSS and
book the fee as a separate expense, that is an accounting-policy decision, it is different from what
is built, and CLAUDE.md says no rounding-bearing operation exists until that policy is signed.

### 2. The gating specified for this route does not apply to it.

The plan said "route on `parent_portal.access` + `GuardianPaymentAuthorisation`, no new ability".
That pairing is right for the **initialise** route — a parent, in session, paying an invoice they
may pay. Paystack carries no session, so applying it here would 401 every genuine delivery. The
route sits outside every auth group; its only authentication is the HMAC. Stated in a comment on
the route itself so the omission does not read as an oversight.

### 3. `withoutGlobalScope` was the first design and was rejected by the boundary lint.

The draft looked the reference up across all schools and adopted whatever school the row named.
`bin/ci-boundary-lint.php` flagged it. I did **not** baseline it. Instead `GatewayReference` mints
the school id into the reference and parses it back, so the lookup runs inside the school's context
with `SchoolScope` intact.

**The consequence for step 3: the initialise call MUST mint its reference via
`GatewayReference::mint()`.** A reference built by string concatenation will not route, and the
symptom is silent — an unroutable reference is acknowledged 200 with no payment, which looks exactly
like a delivery for a transaction we never issued: the payer is charged and nothing is recorded.

**That was documentation, and documentation would have made it folklore** — whoever writes step 3
has no reason to read the webhook's docs. It is now ENFORCED at the write: `GatewayTransaction`
refuses on `creating` any reference that does not route to its own `school_id`, so the mistake fails
loudly where it is made rather than silently where it surfaces, and before anyone is charged.
`tests/Feature/Finance/GatewayReferenceRoutingTest.php` holds the guard down — including a
known-negative, because a guard that refused everything would pass the refusal arms alone.

---

## What was built

- `database/migrations/2026_08_31_100000_gateway_events_state_what_was_stripped.php` — adds
  `redacted_fields` (json, nullable) to the events table; shape-verified from `information_schema`;
  no `CHECK` (production is MySQL 5.7.23).
- `App\Finance\Services\GatewayEventRedactor` — strips named dot paths, returns what was *actually*
  removed.
- `App\Finance\Services\GatewayReference` — mint/parse; the routing contract.
- `App\Finance\Actions\SettleGatewayTransaction` — `handle()` = T1 then T2.
- `App\Finance\Enums\GatewaySettlementOutcome`, `App\Finance\Exceptions\GatewayClaimLost`.
- `App\Finance\Http\Controllers\PaystackWebhookController` + `POST /api/webhooks/paystack`.
- `App\Finance\Providers\FinanceServiceProvider` — Paystack bindings, inside the module because
  `App\Finance\Services` is private to it.

## Verification — raw

```
pest tests/Feature/Finance/PaystackWebhookTest.php   passed=12/12 failed=0
pest --group=arch                                    passed=115/115 failed=0
bin/ci-boundary-lint.php   OK — no new boundary violations (8 known)
bin/ci-authz-lint.php      OK — 0 known
bin/ci-money-lint.php      OK — 0 known
composer analyse           {"tool":"phpstan","result":"passed","errors":0}
pint                       {"tool":"pint","result":"passed"}
```

### Watched reds — planted against the FINAL code, after the refactor

| Mutation | Result |
|---|---|
| Drop `setTimezone('Africa/Lagos')` | **RED** — Lagos-date test |
| Signature `verify()` returns `true` | **RED** — unsigned-delivery test |
| Redactor iterates `[]` | **RED** — authorization-code test |
| T1 made conditional on the settling event | **RED** — non-settlement-event test |
| Drop early return **and** CAS predicate | **RED** — idempotency test (double payment) |
| Write guard `if (false)` (never refuses) | **RED ×2** — both reference-refusal arms |
| Drop early return **only** | **GREEN — by design, see below** |

The survivor is deliberate and is the proof that the CAS is live, not a hole: with the early return
gone, the second delivery still produced **one** payment and `already_settled`, which can only
happen if the CAS affected zero rows, threw `GatewayClaimLost`, and rolled the payment back. Each
layer alone is sufficient; the pair is redundant on purpose.

Every mutation was asserted to have MATCHED before the run (the script aborts on a non-matching
substitution), so a "survivor" cannot be an edit that silently did not apply.

### Two instrument failures found while doing this, both in my own tooling

Recorded because in each case the instrument reported clean over something that was not.

1. **My Pest summariser read only the `failures` bucket and ignored `errors`.** It printed
   `failed=0` over an ERRORING test — and the erroring test was the known-negative for the new write
   guard, so for a while the guard had no arm proving it did not refuse everything. This is the
   exact defect CLAUDE.md records for mutation summarisers, met here in ordinary test reporting. The
   reader now prints errors, and flags any test in neither bucket.
2. **`pest $BOTH` with an unquoted variable ran nothing.** zsh does not word-split unquoted
   parameters, so both paths arrived as one argument, pest failed, and my reader said "NO JSON" —
   which I could easily have read as a tooling hiccup rather than as *the suite never ran*. Same
   class as the pint scalar-vs-array trap in CLAUDE.md, one command after I wrote up its sibling.
   Both were re-run with the array form and an error-aware reader; the survivor's result is
   unchanged under both fixes.

### Migration rollback

Re-derived position from `migrate:status` rather than assuming a depth. Rolled back, then asserted
**my** column specifically: `GONE`. Re-upped: `PRESENT`. Suite green after re-up.

---

## What I did NOT do

- **No browser drive.** This change adds no screen. The verify-on-return screen (step 6) is next
  and will need one.
- **No real-provider arm.** Every test posts a locally-signed body. The HMAC is computed with the
  same function it verifies with, so these tests would not catch Paystack signing something other
  than the raw body. The sandbox capture command exists and a live delivery against a tunnel is
  the arm that would close it — not run.
- **`GatewaySettlementOutcome::FeeExceedsAmount` and `::Unknown` are untested.** Both are
  defensive; neither is reachable from a healthy provider. Named here rather than left to be found.
- **No concurrency test with two real connections.** The idempotency proof is sequential. The
  mutation evidence shows the CAS fires, but a genuine interleave is not demonstrated.
- **The column rename** — ticketed, `gateway-event-payload-is-not-the-whole-payload.md`.
- **Redact-at-write is built ahead of Dev 1's ruling**, deliberately: storing a reusable credential
  now and backfilling later is the irreversible direction. If the ruling is "keep it", removing the
  strip is a one-line edit.
