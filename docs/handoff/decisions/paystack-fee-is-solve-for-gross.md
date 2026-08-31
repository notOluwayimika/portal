# The parent-bears-the-fee ruling needs a different mechanism than expected

**Raised:** 2026-08-30, from researching the three Paystack numbers.
**Decision needed from:** Developer 1, on the rounding direction and the two policy numbers.
**Status of the numbers below:** documented, **not** confirmed against our sandbox account — see §6.

---

## 1 · HEADLINE: `bearer` cannot pass the fee to the customer. We must compute it.

The plan was to prefer Paystack's own `bearer` setting at initialisation, on the sound reasoning
that *their* number is authoritative and ours can drift from their pricing.

**That option does not exist.** `bearer` accepts `account` or `subaccount`
([split payments docs](https://paystack.com/docs/payments/split-payments/)) — **both of them ours**.
It chooses which of *our* accounts absorbs the fee; it has no concept of the payer bearing it.

So "the parent bears the fee" can only be implemented by **charging the parent a larger amount than
the bill** and recording the bill as the payment. Which means the fee formula is ours, it is
load-bearing, and everything below follows from that.

## 2 · It is SOLVE-FOR-GROSS, not `bill + fee(bill)`

The natural implementation is wrong, and wrong in the direction that loses the school money on every
single transaction without ever failing.

Paystack charges their fee on **what you charge**, not on the bill inside it. So adding the fee to
the bill under-recovers, because the addition itself is fee-bearing:

```
bill                 ₦100,000.00
fee(bill)              ₦1,600.00   ->  charge ₦101,600.00
ACTUAL fee on ₦101,600 ₦1,624.00   ->  school receives ₦99,976.00
SHORT BY                  ₦24.00   <-- every transaction, silently
```

The correct form solves `G − f(G) = B` for the gross `G`:

```
charge ₦101,624.37  ->  fee ₦1,624.37  ->  school receives ₦100,000.00  exactly
```

## 3 · It is PIECEWISE — three regimes, two boundaries

Because the ₦100 is waived below ₦2,500 and the fee is capped at ₦2,000, the inverse has three
branches. Computed, not asserted (all arithmetic in **kobo**, integers):

| regime | condition on the bill `B` | gross `G` |
|---|---|---|
| **R1** no flat | `B ≤ ₦2,462.50` | `G = B / 0.985` |
| **R2** flat applies | `₦2,462.50 < B ≤ ₦124,666.67` | `G = (B + ₦100) / 0.985` |
| **R3** capped | `B > ₦124,666.67` | `G = B + ₦2,000` — the division disappears |

The cap engages at `G = ₦126,666.67`, which is where `1.5%·G + ₦100 = ₦2,000`.

### 3.1 · The waiver boundary is DISCONTINUOUS, and a parent can see it

`f(G)` jumps by ₦100 as `G` crosses ₦2,500, so the gross jumps too:

```
bill ₦2,462.00  ->  charge ₦2,499.49
bill ₦2,462.50  ->  charge ₦2,601.52     <-- +50 kobo of bill, +₦102.03 of charge
```

That is arithmetically correct and will still look like a bug to whoever meets it first.

**The cliff is not ours.** Paystack's ₦100 waiver is a STEP rather than a taper; charging gross
merely makes the parent see it. Nothing in our arithmetic created it and no formula removes it.

### The shape of the decision, so it takes five minutes rather than becoming a ticket nobody opens

**There is a ₦102.03 dead band: no bill produces a charge between ₦2,499.49 and ₦2,601.52.**

**How often can it be reached?** Term bills are in the hundreds of thousands, so the band is
unreachable from a full bill. It is reachable **only by part-payment**, which §11 decision 3 has just
made legal — so "rare" rather than "never", and the frequency is entirely a function of how small a
part-payment a parent may make.

**The exposure if the school smooths it is bounded and small.** Cap the charge just under the
threshold (₦2,499.99, the most that still attracts no flat fee) and the school receives ₦2,462.49:

| bill | school absorbs |
|---|---|
| ₦2,462.50 | ₦0.01 |
| ₦2,500.00 | ₦37.51 |
| ₦2,550.00 | ₦87.51 |
| **₦2,562.49** | **₦100.00** — break-even with the step |
| ₦2,600.00 | ₦137.51 — worse than the step; charge gross instead |

So a smoothing rule is: **for bills in `(₦2,462.50, ₦2,562.49]`, cap the charge at ₦2,499.99 and
absorb the difference — never more than ₦100 per transaction.** Above ₦2,562.49, charging gross is
cheaper for the school and the step is simply passed on.

**Three options, and any is defensible:**

1. **Do nothing** — the parent sees a ₦102 step on a narrow band of part-payments. Zero code.
2. **Smooth it** — the bounded rule above, at most ₦100 per transaction, on transactions that will be
   uncommon. One branch in the gross calculation.
3. **Set a minimum part-payment above ₦2,562.49** — the band becomes unreachable by construction and
   no money is absorbed. One validation rule, and it constrains parents rather than the school.

**Recommendation: (3) if a minimum part-payment is acceptable to the business, otherwise (1).**
Option 2 puts a special case inside money arithmetic to solve a cosmetic problem, and money
arithmetic is where special cases are most expensive.

## 4 · The rounding direction is a DECISION, and the default is wrong

`G` must be an integer number of kobo, and the division makes that a choice.

**`Money::percentage()` rounds banker's**, which is the right default almost everywhere in this
codebase and is **the wrong direction here**. Rounding `G` down means `G − f(G) < B` — the school
receives less than it billed, by a few kobo, on every transaction, invisibly per-payment and real at
term scale on an append-only row that cannot be corrected.

**Recommendation: round `G` UP (ceiling), in kobo.** The parent pays at most one kobo more than the
exact solve; the school is never short. But it is Developer 1's call, and it must be stated in the
code rather than inherited from a helper whose default is the opposite.

## 5 · The divergence guard is REQUIRED, not nice-to-have

Because we compute the fee ourselves, our formula drifts silently the day Paystack changes pricing —
and there is no notification. Two facts make that worse than usual, and both were met while
researching this:

- `paystack.com/pricing` **403s** to automated fetching, so the canonical page cannot be watched;
- the support article carrying the numbers **has no last-updated date**
  ([support](https://support.paystack.com/en/articles/2130306)).

So the external number we depend on is one we cannot verify on a schedule. **The only mechanism that
catches drift is comparing what we charged against what they actually took** — the `fees` field on
`verify`, which is per-transaction, authoritative and already stored (`fee_minor`).

> **The guard: on every settled transaction, `gross − fee_reported` must equal the recorded payment.
> Any divergence is a discrepancy class**, and it fires on the first transaction after a pricing
> change rather than at the end of a term.

This is what makes depending on an unverifiable external number safe: not trusting it, but detecting
the moment it stops being true.

## 6 · The two policy numbers, and what research could and could not settle

**(a) Fee structure — documented.** `1.5% + ₦100`, capped at `₦2,000`, the `₦100` waived below
`₦2,500` ([support](https://support.paystack.com/en/articles/2130306), quoted verbatim).

**(b) Abandonment timeout — THE QUESTION MOVED, it did not fail.** Paystack's own article defines
abandoned as *"when a user begins to make a payment but doesn't complete it"* and states **no
threshold** ([support](https://support.paystack.com/en/articles/2123330)). The 30-minute figure that
surfaces in searches is for **transfers**, a different flow.

So `--pending-hours` **cannot be derived from Paystack at all.** It is not an open research thread;
it is an operational choice — *how long before a bursar should care about a payment that has not
resolved* — and it belongs to the same person and the same conversation as the report cadence
(daily for two weeks, weekly thereafter). Deciding it there closes it.

**(c) Retention window — the widely-quoted number answers a different question.** The **16 hours** is
the *merchant's response deadline*
([support](https://support.paystack.com/hc/en-us/articles/360012946200-How-to-resolve-chargebacks)) —
how fast we must reply once a dispute exists. Retention keys on how long a dispute can be **raised**,
which Paystack does not publish; card-scheme norms are ~120 days, extending to 180 for
unauthorised-transaction claims ([scheme limits](https://chargebacks911.com/chargeback-rules/chargeback-time-limits/)).

**Developer 1's proposed 12 months sits comfortably above 180 days**, so the proposal survives the
research. It is still his number rather than a measured one, and that is now a deliberate margin
rather than an unexamined guess.

## 7 · The one step only Segun can take

**Run a single sandbox transaction and paste the ENTIRE `verify` response** — not just the `fees`
field. That settles the formula empirically, against the account we will actually use, at today's
pricing, and it is the measure-rather-than-recall step the credential boundary correctly stopped me
from taking. `.env` is not mine to read and nothing in the client's tests touches a live key.

**Why the whole payload rather than one field.** `fees` alone settles §3. It does **not** settle
§5's premise, and §5 is the guard that makes the whole design safe:

> The divergence guard compares *what we charged* against *what they took*. That comparison is only
> valid if we know **what their `fees` is measured against** — the gross we charged, the net settled,
> or something else again. If `fees` turns out to be reported against a different base than we
> assume, the guard silently compares two unlike numbers and reports drift that is not there, or
> misses drift that is.

One transaction answers the formula **and** the guard's premise together, and it is the difference
between a guard that works and a guard that looks like it works. Alongside `fees`, the fields that
matter are `amount`, `requested_amount` if present, `currency`, `status`, `paid_at` and `id` — but
paste all of it, because the useful field is often the one nobody thought to ask for.

