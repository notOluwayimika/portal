# Paystack calibration — the formula holds, and two findings that are not about fees

**Measured:** 2026-08-31, three real sandbox charges, card channel, one per fee regime.
**Captured by:** `php artisan finance:capture-paystack` — an instrument, so it re-runs.
**Payloads:** `storage/app/private/paystack-capture/` (gitignored — see finding 1 for why that matters
more than first thought).

---

## 1 · The fee formula is CONFIRMED, exactly, in all three regimes

| regime | amount charged | predicted fee | Paystack's `fees` | |
|---|---|---|---|---|
| below the ₦2,500 waiver | ₦2,000 | ₦30 | **₦30** | match |
| flat applies | ₦50,000 | ₦850 | **₦850** | match |
| above the cap | ₦300,000 | ₦2,000 | **₦2,000** | match |

`1.5% + ₦100, capped at ₦2,000, the ₦100 waived below ₦2,500` — derived from documentation,
now measured against the account we will actually use.

### And `fees` is measured against the GROSS charged, which is the premise the guard rests on

This is the question `fees` alone could never answer, and it is why one charge per regime was the
right ask:

| amount | `fees` | if computed on GROSS | if computed on NET |
|---|---|---|---|
| 200,000 kobo | **3,000** | 3,000 | 2,955 |
| 5,000,000 kobo | **85,000** | 85,000 | 83,725 |
| 30,000,000 kobo | **200,000** | 200,000 | 200,000 |

The first two discriminate; the third cannot, because the cap flattens both. Observation matches
GROSS. **So solve-for-gross is the correct construction, and the divergence guard compares like with
like.** `observedFee()` reads `data.fees` directly and needs no adjustment.

### One step still owed, with its reason attached so it does not quietly not happen

`requested_amount == amount` in all three captures — **uninformative by construction**, because we
initialised for exactly the amount we wanted charged. The two can only diverge once we gross up.

> **RE-CAPTURE ONE TRANSACTION AFTER `observedFee()` AND THE GROSS-UP LAND**, and check which of
> `amount` / `requested_amount` the fee tracks. If `fees` turns out to follow `requested_amount`
> rather than `amount` under a real gross-up, the guard compares unlike numbers and every settled
> transaction reports false drift. One command, two minutes: `finance:capture-paystack --verify=…`.

---

## 2 · FINDING — the events table would store a REUSABLE PAYMENT INSTRUMENT, not just PII

**This is an access-control question wearing a retention question's clothes, and it should be its own
item rather than folded into the retention ticket.**

Measured in every one of the three payloads:

```
authorization.authorization_code   present, "AUTH_" prefix
authorization.reusable             true
authorization.signature            present  (stable card fingerprint, persists across transactions)
```

`authorization_code` is what `POST /transaction/charge_authorization` takes to **debit a saved card
without the card**. It is merchant-scoped and useless without our secret key, so a database read
alone is not "stolen cards" — but it makes the two sensitive things in this system **correlated**:

- the **secret key** is already the webhook forgery credential;
- it is also the other half of this.

Whoever holds both can charge parents' cards. Today those are `.env` and an append-only table with
**no retention period**, and this repository's own audit found **three copies of the database on
developer machines**.

### The answer is probably not a period at all

A retention period says *"we will delete it eventually."* For a token that can move money the right
shape is **redact-at-write**: *"we never held it."*

> **Proposal — drop `authorization_code` and `signature` at the door**, keeping only what a bursar
> needs to reconcile a statement line: `last4`, `card_type`, `bank`, `channel`. Store the rest of the
> payload verbatim as evidence.

Unless **saved-card billing is a product decision somebody has actually made**, in which case the
token is a feature and needs a deliberate home with its own access control — not an audit table.

It is cheaper to decide this before the first real transaction than after: the events table is
append-only, so a token written today cannot be removed, only redacted, and only if someone
remembers it is there.

---

## 3 · FINDING — `paid_at` is UTC, and `received_at` has a one-hour-a-night date bug

`paid_at` arrives as `2026-08-31T16:36:46.000Z` — **ISO-8601, UTC, `Z` suffix**. `app.timezone` is
**UTC**, so the naive extraction is also the default one.

`finance_payments.received_at` is a `Y-m-d` **business day**, and the column is append-only: a wrong
date can never be corrected.

Nigeria is **UTC+1, no DST**. So every payment made between **00:00 and 01:00 WAT** carries a UTC
date one day earlier. Demonstrated:

```
paid_at (UTC)               naive Y-m-d   Africa/Lagos Y-m-d
2026-09-05T23:30:00.000Z    2026-09-05    2026-09-06     <-- WRONG DAY, permanently
2026-09-05T23:59:59.000Z    2026-09-05    2026-09-06     <-- WRONG DAY, permanently
2026-09-06T00:30:00.000Z    2026-09-06    2026-09-06
```

**One hour every night**, and deadline nights are exactly when someone pays at half past midnight —
so the affected population is not random, it is concentrated on the dates that matter most.

> **Convert to `Africa/Lagos` before taking the date.** Never `Carbon::parse($paidAt)->toDateString()`.

This is `RecordPayment`'s existing Friday/Monday reasoning — *a payment made Friday and delivered
Monday belongs to Friday* — one timezone further in: **a payment made Saturday at 00:30 belongs to
Saturday, not to Friday.** The docblock at the call site should say so, next to that note.

---

## 4 · THE INTERIM STRIP MAKES "raw payload" FALSE — so it must not be called that

Stripping two fields at write is right (§2), and it has a consequence that must be built in rather
than discovered: **the stored payload is then no longer the payload.** A column documented as the
provider's raw body, holding a body we edited, is a description asserting a property the artifact
does not have — and every downstream reader (the discrepancy report, a dispute, whoever reconstructs
a bad night eighteen months from now) will treat it as verbatim, because that is what it says.

Two requirements, both cheap now and expensive later:

1. **Name it for what it is.** Column comment and docblock read *"provider payload, authorisation
   credential fields removed at write"* — never *"raw"*.
2. **Record THAT we stripped, and WHICH fields.** An absent `authorization_code` is otherwise
   ambiguous between *"Paystack did not send one"* and *"we removed it"*, and those are entirely
   different facts to an investigator. A `redacted_fields` list on the event row turns a silence into
   a statement. Without it this is the no-local-failure-signal class **inside the artifact whose
   whole job is to be evidence.**

### The one-way door, named rather than discovered

**Stripping is irreversible for historical rows; keeping is reversible**, because the redaction door
already exists. So the interim deliberately takes the NON-recoverable direction — and it is still
right:

- nothing in the product charges a saved card, so stripping loses nothing anyone needs today;
- keeping accumulates a live liability during precisely the window in which nobody has decided.

**The cost, stated so it is not a surprise:** if saved-card billing is later wanted, cards from
before the decision must be re-entered by their owners. That cost was probably unavoidable anyway —
storing a reusable authorisation needs the parent's consent, and nobody has asked for it.

## 5 · The timezone sweep — one candidate, and my check of it was vacuous

`app.timezone` is UTC, so the naive instant→date path is the default everywhere, not only in the
gateway. Swept `app/Finance/` for `toDateString()` and `format('Y-m-d')`:

**Safe** — these read DATE columns, where the conversion is a no-op:
`OpeningBalanceBatchController:287` and `PostOpeningBalanceBatch:226,277,313` (`cutover_date`, a
`date`), `ImportOpeningBalances:445` (validating a user-entered string).

**One candidate** — `ApproveVoidRequest::originalChargeEffectiveAt()`:

```php
return $charge?->effective_at?->toDateString()      // date column — safe
    ?? $invoice->created_at->toDateString();        // TIMESTAMP — carries the bug
```

Its docblock says the fallback is unreachable, and gives a reason that covers only half the branch:
*the column is NOT NULL and the table was empty when it was added* explains why `effective_at` is
never null — it does **not** explain the `$charge === null` case, which fires when an invoice has no
charge row at all.

**And my attempt to measure that was vacuous, which is worth recording as much as the finding.** The
query "invoices with no charge ledger row" returned **0** — but the database holds **0 invoices**, so
it cannot distinguish *"every invoice has a charge"* from *"nothing exists yet"*. Both give zero.
Reported as confirmation it would have been the degenerate-fixture class in my own check.

**And re-running the count later would NOT fix it**, which is the more useful correction. **A count
over existing rows can confirm a violation; it can never establish an invariant.** Zero charge-less
invoices says nothing about whether one is *permitted* — exactly as zero duplicate guardians said
nothing about whether `Guardian::create()` can duplicate. Both are readings of HISTORY offered as
statements about the RULES. Once invoices exist the same query becomes non-vacuous and still does not
answer the question.

**What answers it is the write path and the schema, both readable now.** Checked: there is **no
constraint anywhere requiring an invoice to carry a charge line.** `2026_08_29_120000` requires a
destination *on* charge lines — not that one exists. So whatever the generate modal currently
permits, the claim is **unenforced**: nothing stops an invoice of only waiver and discount lines
being written by any future path, and the fallback fires the moment one is.

**By the quantifier rule this docblock is a test that was never written.** *"Unreachable"* is the
strongest claim in that file, supported by a reason covering only half the branch. Two honest
resolutions:

- **Cheap and true:** weaken it to what was actually checked — *"not reachable through current write
  paths; not enforced."* One sentence.
- **Better:** make it executable — a constraint that every invoice carries at least one charge line,
  or an explicit throw on the null-charge path rather than a silent fallback to a timestamp-derived
  date.

`ticket`, for Developer 1, not urgent: the fallback is currently unreached and the tables are empty.

## What this changes for step 4

1. `observedFee()` reads `data.fees` — confirmed, no adjustment.
2. The gross-up uses solve-for-gross — confirmed correct against the real schedule.
3. **The event row must not store `authorization_code` or `signature`** pending §2's decision. Until
   it is taken, redact-at-write is the safe default: it can be relaxed later, and an unwritten token
   needs no remediation. **And per §4 the column is named for what it holds, with a `redacted_fields`
   list** — a stripped payload described as raw is a lie in the evidence table.
4. `received_at` comes from `paid_at` **converted to Africa/Lagos**, with the reasoning at the call
   site.
