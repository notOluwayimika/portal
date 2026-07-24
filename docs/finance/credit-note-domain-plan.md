# Credit-note / write-off — domain design

**Status: PLAN FOR REVIEW. Nothing built. 2026-07-22.**

The last deferred Finance item and the one billed as the highest-stakes — because it is
post-issuance, the case every prior Gate 0 protected against, and was expected to
**force the seal**. Re-derived against the code, **it does not force the seal**, for the
same reason the waiver slice's F6 crux dissolved: the fear rested on an assumption about
the model that the model does not require.

---

## The headline: a credit-note is a DOCUMENT, not a line edit — so the seal is NOT forced

The financial truth in this system is the **ledger**, not the invoice. A student's
balance is `SUM(finance_ledger_transactions.amount)` — `Charge (+)`, `Payment (−)`,
`Reversal (−)` — and every movement is sourced by `(source_type, source_id)`. The
invoice is a _document_; the ledger is the _account_.

VOID already proves the pattern: cancelling an invoice does **not** delete it or touch
its lines — it flips `status` and posts a `Reversal` ledger row sourced to the invoice.
The invoice stays frozen; a new compensating fact is posted beside it.

**A credit-note is the same move, partial instead of whole.** It is a **separate
document** (`finance_credit_notes`) that references the invoice and posts its own
reversing ledger entry. It adds **zero lines** to the issued invoice.

That single fact resolves the crux:

> F6's residual gap (b) — that `finance_invoice_lines` permits INSERT — is deferred as
> _tamper-only, because no legitimate op adds a line post-freeze_. A credit-note adds no
> line to the issued invoice. **Gap (b) is not reopened. The seal is NOT forced by this
> slice.**

This is model **(a)**, and it is not merely cheaper — it is what real accounting does. A
credit note is a document issued _against_ an invoice, never an edit _to_ it. Model (b)
(a reduction line added to the frozen invoice) is rejected: it would force the seal,
reopen gap (b), and mutate a snapshot fact the entire model is built to keep immutable.

**The most-feared part of the most-feared item evaporates** — stated as plainly as the
waiver slice stated its equivalent.

---

## 1. The domain model

### `finance_credit_notes` — a new aggregate beside the invoice

| field                              | purpose                                                                                                                                                                      |
| ---------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `id`, `uuid`                       | identity                                                                                                                                                                     |
| `school_id`                        | isolation (durable RESTRICT FK, top-level Finance table owns it directly)                                                                                                    |
| `student_id`                       | the account the credit lands on                                                                                                                                              |
| `invoice_id`                       | the invoice being credited (composite FK `(invoice_id, school_id)` → `finance_invoices`, mirroring the lines/allocations pattern so no cross-School credit is representable) |
| `invoice_line_uuid`                | **nullable** — the specific line credited, or NULL for a whole-invoice partial credit (see §3)                                                                               |
| `number`                           | its own gap-tolerant sequence (`Sequences::next('finance_credit_note', schoolId)`), rendered `CN-000042` via the same presentation-derived prefix machinery                  |
| `amount_minor` / `amount_currency` | the credited amount, a **snapshot** (Money VO); positive magnitude, the ledger row carries the sign                                                                          |
| `reason`                           | free-text, and a `kind` (see §4 vocabulary)                                                                                                                                  |
| `status`                           | `issued` today; `draft`/`approved` is the Ph3 approver hook (§5) — shaped for, not built                                                                                     |
| `created_by_user_id`               | maker; `approved_by_user_id` is the Ph3 hook                                                                                                                                 |

It posts **one** ledger row — `SubledgerPoster::post(..., LedgerEntryType::CreditNote,
$amount->times(-1), 'credit_note', $creditNote->id, ...)` — inside its own transaction,
exactly as VOID and payment do. The balance nets automatically because the balance _is_
the ledger sum; nothing recomputes the invoice.

**Snapshot integrity** carries straight over: the credited amount is stored on the
credit-note at issue time, never recomputed from the (frozen) invoice later.

### Why not reuse the invoice's own lines / VOID

- Adding a line → model (b) → seal forced. Rejected above.
- VOID reverses the **whole** invoice (`charge->times(-1)`) and flips status. A
  credit-note is **partial** and leaves the invoice `issued`. Same _pattern_ (a
  compensating ledger entry), different _scope_ — so it **reuses `SubledgerPoster`**, it
  does not fork it.

---

## 2. The seal — explicitly NOT forced (the crux, resolved)

Stated without hedging, because the brief asked for exactly this:

- Credit-note is model (a), a **separate document**.
- The issued invoice gets **no new lines**.
- F6's residual gap (b) is **not reopened**.
- **The `lines_sealed_at` seal is NOT forced by this slice** and stays deferred, on the
  same terms as before: it lands only when a real post-freeze-_line_ consumer arrives,
  and a credit-note is not one — it routes around being one by being a document.

The structural test from the fixed-amount slice ("exactly one line-INSERT path, inside
`GenerateInvoice`") **stays true and unchanged** — a credit-note inserts into
`finance_credit_notes`, never into `finance_invoice_lines`. That test should keep
passing after this slice; if a credit-note implementation ever makes it fail, that is the
signal that the design slipped into model (b) and the seal question is back.

---

## 3. Line reference — ALREADY SOLVED, and shared with per-line waivers

The brief anticipated designing a stable line-reference mechanism. **It already exists.**

`finance_invoice_lines` carries a `uuid` (`AddUuid`, `char(36) UNIQUE`), and the API
already exposes it — `InvoiceLineResource` returns `'id' => $this->uuid`. So a specific
line is referenced by its **uuid**: stable (not array-index, not description), immutable
(the line is append-only), and already on the wire.

- **Credit-note against a specific charge** ("write off the boarding line"):
  `invoice_line_uuid` = that line's uuid. NULL = a whole-invoice partial credit.
- **Per-line waiver** (the deferred billing-time item) reuses the _same_ reference: a
  billing-time reduction targeting a specific charge names it by line uuid.

**No new column, no line-identity migration.** The abstraction the brief expected to
design here turns out to be a naming decision — "reference a line by its uuid" — that the
existing schema already supports for both consumers. That is the shape; both can use it.

One caveat to confirm (§6): a percentage credit-note against a _specific line_ needs that
line's amount as the base. The line uuid resolves to the row, whose `amount` is the base
— clean. A whole-invoice percentage credit uses the invoice `total`.

---

## 4. VOID / reversal / write-off vocabulary

| term            | scope                     | mechanism                                    | status effect          |
| --------------- | ------------------------- | -------------------------------------------- | ---------------------- |
| **VOID**        | whole invoice             | `Reversal` ledger row = −(full charge)       | invoice → `void`       |
| **Credit-note** | partial, post-issuance    | `CreditNote` ledger row = −(credited amount) | invoice stays `issued` |
| **Write-off**   | a credit-note **subtype** | same mechanism                               | invoice stays `issued` |

**Write-off is not a distinct mechanism** — it is a credit-note whose `kind`/reason is
"uncollectable", exactly as waiver and discount are one reduction mechanism differing by
reason. One aggregate, a `kind` enum (`credit_note` | `write_off`), same ledger posting.
Modelling them separately would duplicate the machinery for a label.

**Ledger type — one real decision.** Reuse `LedgerEntryType::Reversal`, or add
`LedgerEntryType::CreditNote`?

- Reuse: the sign and mechanism are identical, and `source_type='credit_note'` already
  distinguishes the row. Zero enum change.
- Add: a partial post-issuance credit is a _distinct business event_ from a whole-invoice
  void, and reporting/audit will want to tell them apart without joining on `source_type`.

**Recommend adding `CreditNote`** — it is a one-line enum addition, it is additive
(existing rows keep their types), and "we wrote off ₦X" is a materially different fact
from "we voided the invoice". Low-stakes, but clearer. The subledger still reconciles
identically: balance = `SUM(amount)`, append-only, both rows survive — the same property
VOID has, proven by `WalkingSkeletonTest`.

**A new invariant this introduces:** total credits against an invoice may not exceed its
outstanding charge. `SUM(credit-notes for invoice) ≤ invoice.total` (and, if payments
are considered, the net owed). Over-crediting would drive the account negative — the
subledger equivalent of the non-negative-total rule, and it needs the same
concurrency care VOID has (`lockForUpdate` on a decide-then-write; two concurrent
credit-notes must not both pass the ceiling check). This is the one genuinely new piece
of correctness work in the slice.

---

## 5. Migration / rounding / approver

**Migration — additive, and lighter than expected.**

- `finance_credit_notes`: a **new table**. No alter of a deployed table, no backfill —
  the safest shape, same class as `finance_school_settings`.
- **No `finance_invoice_lines` alter** — the line uuid it needs already exists (§3). This
  is the finding that most reduces the slice's risk: it was expected to alter a deployed,
  live, append-only line table; it does not.
- `LedgerEntryType::CreditNote` is a code enum, not a schema change (the `type` column is
  a string).
- Four-path the new table, and **apply the #85 rule**: re-derive rollback depth from
  `migrate:status` and assert `finance_credit_notes` itself is gone after rollback — the
  RBAC stream's migrations may sit on top, so `--step=1` is not assumed to be this one.

**Rounding — no new Money op.** A percentage credit-note reuses `Money::percentage`
(built last slice); a fixed-amount one is exact `Money`. `Money::allocate` is there if a
credit is ever split. Confirmed: this slice adds nothing to Money.

**Approver — interface noted, NOT designed (Ph3 / RBAC).** Credit-notes are where
maker-checker matters most — writing off money is the highest-authority Finance action.
The **hook** is: a `status` (`draft` → `approved` → `issued`) and `created_by` /
`approved_by` columns, with the ledger row posted only on approval. **This plan does not
design the approval rules** — who may approve, thresholds, segregation of duties are Ph3
and RBAC's territory. Flagged as a coordination point: the credit-note aggregate must
leave room for the approver columns (additive later), and the RBAC stream owns the rule.
Recorded here as an interface, per the parallel-work protocol.

---

## 6. Recommended first slice + what to confirm

**Slice 1 — fixed-amount credit-note against a whole invoice.**

- `finance_credit_notes` (no line target — `invoice_line_uuid` NULL), fixed amount,
  `kind` (`credit_note` | `write_off`), the `CreditNote` ledger posting, the
  over-credit ceiling invariant with `lockForUpdate` concurrency, `CN-` numbering.
- **Excludes:** line-specific credits (needs the uuid-target wiring — slice 2), percentage
  credits (reuses `Money::percentage` — trivial once the shape exists — slice 2),
  approver/maker-checker (Ph3), refunds/cash-out (money leaving — a different concept, not
  a subledger adjustment).

**Slice 2 — line-specific + percentage credits**, on the uuid reference from §3, shared
with per-line waivers.

**Then: per-line waivers** ride slice 2's line-reference, resolving that deferred item as
a side effect — but see the open question below.

### What I would want confirmed before building opens

1. **Ledger type: add `CreditNote` or reuse `Reversal`?** Recommend add (§4). It is the
   one enum decision and shapes reporting.
2. **The over-credit ceiling** — is `SUM(credits) ≤ invoice.total` the right ceiling, or
   should it net payments (`≤ outstanding`)? This is a business rule about whether you can
   credit a _paid_ invoice (which would create a refundable balance, like the
   paid-then-voided case §4 already handles). Recommend `≤ total` for slice 1 (simpler,
   and the paid case is a real accounting scenario worth a deliberate decision, not a
   default).
3. **Do per-line waivers ride slice 2, or follow separately?** They share the line-uuid
   reference, so co-designing is cheap — but a waiver is _billing-time_ (Gate 0) and a
   credit-note is _post-issuance_, so they are not the same slice, only the same
   _reference_. Recommend: slice 2 builds the uuid-target wiring and both consumers use
   it, but as two clearly separate flows, not one merged path.
4. **Policy section.** §10 is a **forward reference with no signed section behind it** —
   the policy has eight sections and lists only GL/Sage and the waiver approver as
   deferred. This slice should **write the credit-note policy section** (the document
   model, the ledger reversal reuse, the ceiling invariant, write-off as a subtype) and
   have it signed, the same "ratify the invariant, don't assume it" discipline the
   non-negative-total rule got. It is a decision, not just code.
