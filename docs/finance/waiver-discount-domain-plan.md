# Waiver / discount — domain design

**Status: PLAN FOR REVIEW. Nothing built. 2026-07-21.**

The largest deferred Finance item. Re-derived against the code, and **the crux turned
out smaller than feared** — for a reason worth reading before anything else.

---

## The headline: F6 does not mean what the brief assumed

F6 is described as "total = SUM(lines)", and the fear was that signed reduction lines
would collide with a DB constraint enforcing that equality. **There is no such
constraint.**

What actually exists (`2026_07_19_120000`, trigger `finance_invoices_total_immutable`)
is a **BEFORE UPDATE trigger that denies any change to `total_minor` / `total_currency`**
— while deliberately leaving the `issued → void` status transition free. Its own
docblock is explicit about the split:

> `total ≠ SUM(lines)` has two sources — (a) mutating total after creation, and (b)
> inserting a line after total was computed. **This closes (a) at the DB.** (b) is NOT
> domain-reachable … there is no add-line-to-existing-invoice route, method or raw
> write. So (b) is a tamper vector, not an operational path.

So F6 is enforced as **immutability of a snapshot**, not as a computed equality. The
equality is _established_ in `GenerateInvoice`, which folds the line amounts into the
total inside one transaction, and then _frozen_ by the trigger.

**Consequence — the answer to the crux:** if reductions are signed lines supplied **at
creation**, `GenerateInvoice` folds them into the total exactly as it folds charges, the
equality holds at creation, and the trigger keeps freezing it. **F6's DB enforcement does
not change. The trigger is not touched. Nothing about the crown-jewel invariant is
weakened.**

That is option **(a)** below, and it is only cheap because of _when_ reductions are
applied — which turns out to be the real design decision.

---

## 1. The model

### Recommended: (a) signed reduction lines, applied at invoice creation

|                         |                                                                                                                                                                                            |
| ----------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `finance_invoice_lines` | gains a `type` column (`charge` \| `reduction`), NOT NULL DEFAULT `'charge'`                                                                                                               |
| Sign                    | reduction lines carry a **negative** `amount_minor`                                                                                                                                        |
| Total                   | `GenerateInvoice` folds all lines signed → `total = SUM(lines)` still literally true                                                                                                       |
| F6 trigger              | **unchanged**                                                                                                                                                                              |
| Append-only triggers    | **unchanged** — a reduction is a new line, never a mutation of the fee line                                                                                                                |
| §5 display              | full fee line and reduction line both persist as snapshots; the display layer groups charges above, reductions beneath. **No netted figure is ever stored or rendered as a single number** |

This satisfies §5 exactly: the original full fee remains a visible snapshot line, the
reduction is a separate line shown beneath, and nothing is recomputed.

**What must change (all in the domain, none in F6):**

1. `finance_invoice_lines.type` — additive column (below).
2. `InvoiceLineSpec` — gains the type.
3. `GenerateInvoice:106` — the positivity throw becomes **type-scoped**: a `charge` must
   be positive; a `reduction` must be **negative** (and must not be zero). Today's blunt
   "every line must be positive" becomes two rules, each stricter than the current one
   for its own type.
4. `GenerateInvoiceRequest` — `amount_minor >= 1` likewise becomes type-conditional.
5. **A new invariant: the invoice total must not be negative.** Reductions may not
   exceed the charges they offset. This is new, has no equivalent today (because
   negatives were impossible), and is the invariant most likely to be forgotten.

### Rejected: (b) a separate reduction structure

Reductions living outside `finance_invoice_lines`, with `total = charges − reductions`.
This **would** force redefining F6 and changing its enforcement, it fights the
snapshot-line model §5 is built on, and it buys nothing: the display grouping §5 wants is
achievable with a `type` column and no structural split. Heavier, riskier, worse.

### Rejected: (c) mutate the fee line downward

Directly forbidden by §5 ("never a single netted figure") **and** impossible anyway — the
`fee_invoice_lines_no_update` trigger denies UPDATE outright. Noted only to record that
the append-only design already rules it out.

---

## 2. Waiver vs discount vs credit note — the discriminator is TIMING, not the label

- **Waiver and discount are the same shape.** Both are a reduction applied **at billing
  time**, before the invoice is issued, and both must show beneath the full fee. They
  differ only in _why_ — so they are **one line type with a `reason`/`kind`**, not two
  mechanisms. §6 supports this directly: a repeat episode is billed fresh and "whether it
  is waived, discounted, or charged in full is a **manual per-case adjustment**" — one
  path, three labels.

- **Credit note / write-off is a genuinely different concept**, and §10 already files it
  as Ph2+. It is a **post-issuance** adjustment, and that is not a stylistic difference —
  it is structurally impossible under the current model, which is the point below.

**This is the constraint the whole design turns on:**

> A reduction applied **after** issuance cannot be a new line on the existing invoice.

Doing that would take residual gap **(b)** — today a _tamper vector_, because exactly one
line-INSERT path exists inside `GenerateInvoice`'s transaction — and turn it into a
**routine operational path**. The moment an add-line-to-issued-invoice route exists, F6's
"(b) is not domain-reachable" argument collapses, and the seal (`lines_sealed_at` + a
BEFORE INSERT trigger) stops being deferrable and becomes mandatory.

So post-issuance reductions must be either **void + reissue** (the §4 compensating
pattern, already built) or a **separate credit-note document** (§10, Ph2+). Both are out
of this slice. **Nothing in this slice may add a route that inserts a line into an
already-issued invoice.**

---

## 3. Rounding — **not in this slice**, and that is a consumer argument, not a preference

§1 ties banker's rounding to **uneven splits**: installments and percentage-based
reductions. A **fixed-amount** reduction (₦5,000 off) is exact integer arithmetic —
`Money::plus` with a negative operand. **No division, therefore no rounding.**

`Money` still has no division op (confirmed: zero `divide`/`split`/`allocate` methods),
and §6's "manual per-case adjustment" describes fixed amounts, not percentages.

**Recommendation: slice 1 is fixed-amount reductions only, and the rounding op does not
land.** Percentage reductions are a _later_ slice, and that is where `Money::split`
(half-to-even, remainder-on-final) gets its first real caller — along with the `Money`
docblock correction, which per the standing note lands **with** the op.

Building the split op now, for percentage reductions this slice does not implement, is
the front-load-ahead-of-the-consumer mistake in its purest form.

---

## 4. Deployed-state and migration stakes

**This is the first Finance migration to ALTER a deployed table with live data** — a
different risk class from the config slice, which only created a new table.

|                      |                                                                                                                                            |
| -------------------- | ------------------------------------------------------------------------------------------------------------------------------------------ |
| Change               | `ALTER TABLE finance_invoice_lines ADD COLUMN type ... NOT NULL DEFAULT 'charge'`                                                          |
| Additive?            | **Yes** — every existing line is a charge, so the default is correct by construction. **No backfill query, no data rewrite, no row read.** |
| Reversible?          | Yes — `down()` drops the column. Four-path verify per standing discipline                                                                  |
| Append-only triggers | Untouched. `no_update` / `no_delete` on lines still hold, and reductions being _new lines_ is consistent with them                         |
| F6 trigger           | Untouched                                                                                                                                  |
| Live-data risk       | Low, but non-zero: it is a lock-taking DDL on a table with production rows. Worth a row-count check and an off-peak window                 |

The honest framing: this is **the safest possible shape** of "alter a deployed table" —
a defaulted, nullable-by-construction column with no backfill — but it is still the first
of its kind here, so it deserves the four-path treatment and a stated rollback.

---

## 5. How F6 is re-proven under the new model

F6's enforcement does not change, so the existing bite-proof stays valid. Three
**additional** proofs are owed, because the model around it did:

1. **Signed total reconciles.** Create an invoice with charge lines and a reduction line;
   assert `total == SUM(all lines, signed)` and that the stored total is the reduced
   figure — with **both lines persisted** and neither netted away.
2. **The trigger still bites under the new model.** Attempt `UPDATE finance_invoices SET
total_minor = …` on an invoice that has reduction lines → still rejected by
   `finance_invoices_total_immutable`. (Proving F6 was not weakened _by_ the new shape.)
3. **No post-issuance line route exists.** Assert there is still exactly one line-INSERT
   path and no route/method adds a line to an issued invoice — the structural claim gap
   (b) rests on. This is the test that stops a future slice quietly turning the tamper
   vector into an operational path.

Plus the new-invariant proof: **a reduction exceeding its charges is rejected** (total
must not go negative), red when the guard is removed.

---

## 6. Recommended slicing

**Slice 1 — fixed-amount reductions at billing time.**

- `type` column (additive, defaulted), `InvoiceLineSpec` type, type-scoped positivity,
  non-negative-total invariant, §5 display grouping.
- **Excludes:** percentages, the `Money` split op, credit notes / write-offs, any
  post-issuance adjustment, approver/maker-checker rules (Ph3 — note the interface,
  design nothing).

**Slice 2 — percentage reductions.** Where `Money::split` (half-to-even,
remainder-on-final) lands with a real consumer, and where the `Money` docblock staleness
is corrected.

**Slice 3 — credit note / write-off (§10, Ph2+).** Post-issuance adjustment as its own
document, which is also where the invoice **seal** likely becomes necessary.

---

## What I would want confirmed before building opens

1. **Are reductions genuinely billing-time only in slice 1?** The whole "F6 untouched"
   result depends on it. If the business needs to waive a fee on an **already-issued**
   invoice, this is a materially different and much larger slice — it forces the seal
   decision and probably the credit-note document — and the plan should be re-opened
   rather than stretched.
2. **Sign convention:** reduction lines stored **negative** (recommended — keeps
   `total = SUM(lines)` literally true and the fold unchanged) versus stored positive
   with the type carrying the sign (needs a signed fold and makes the invariant harder to
   read). Recommendation: negative.
3. **Is "waiver" vs "discount" a `reason` string, or a constrained enum?** Enum is
   cheaper to report on later; a free-text reason is more flexible now. Recommend enum +
   optional note.
4. **Non-negative total** — confirmed as an invariant? I believe an invoice may not go
   negative (a net refund is a credit note, §10), but that is a business rule this plan
   asserts rather than one the signed policy states.
