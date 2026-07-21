# Finance accounting policy (Brookstone-confirmed, 2026-07)

The accounting decisions the Finance module implements, confirmed by Brookstone.
Each **enforcement** reference below is checked against the actual repo and marked
**ENFORCED** (mechanism named) or **PENDING [slice]** — a confirmed _policy_ does
not imply the _guard_ exists. Table/concept names read `finance_*`, matching the
frozen schema (`docs/finance/walking-skeleton-conventions.md`).

## 1. Rounding

**Policy:** banker's rounding (round-half-to-even) at the code level. On an uneven
split (e.g. a fee divided into installments that does not divide evenly), the
remainder lands on the **final** installment, so the parts reconcile to the total
exactly — no penny is created or lost.

**Enforcement — PENDING (first _dividing_ consumer; NOT slice 2).** Slice 2 shipped
multi-line invoicing without it, correctly: a multi-line total is `Money::plus` over
exact integer minor units, so it never divides and therefore never rounds. Rounding
is only reachable once something _splits_ an amount (installments, %-discounts).
The split/allocate op is a self-contained addition to the VO when that consumer
lands — building it earlier would be a rounding-bearing operation with nothing to
round. Today `App\Support\Money`
deliberately offers **no** rounding- or division-bearing operation: only exact
integer scaling (`times(int)`), with a class docblock forbidding any rounding op
until this policy was signed. The policy is now signed, so the split/allocate
method (half-to-even, remainder-on-final) is built when the first consumer
(installments) lands — not before. Until then there is nothing to round: every
amount is an exact integer minor-unit value.

## 2. Invoice / receipt numbering

**Policy:** numbers must be **unique only — gaps are acceptable** (not gap-free).
Per-School prefix, configurable with defaults:

| School    | Prefix |
| --------- | ------ |
| Secondary | `BSS`  |
| Primary   | `BSP`  |
| Pre-Home  | `BSPH` |
| Academy   | `BSA`  |

**Corrected 2026-07-21.** An earlier revision of this table listed prefixes carrying a
trailing separator (`BSS-`) and an `BSI-LAG-` entry that does not exist. The real
prefixes are **single-segment and separator-less**: `BSS`, `BSP`, `BSPH`, `BSA`.

**Rendered format — decided convention (2026-07-21):**

`<prefix>` + `-` + `<number zero-padded to a minimum of 6 digits>` → **`BSS-000042`**.

Three parts of that are load-bearing:

- **The separator is added at RENDER, not stored.** The prefix is stored
  separator-less, so all four are uniform and the `-` is defined in exactly one place
  rather than depending on each registrar typing a trailing dash. (Defensively, a
  stored prefix that still ends in `-` from the earlier mixed model must normalise to a
  single separator — never `BSS--000042`.)
- **6 is a MINIMUM WIDTH, NOT A MAXIMUM.** A number exceeding six digits renders **in
  full** — invoice 1,000,000 is `BSS-1000000`, never truncated and never wrapped.
  Padding sets a floor on width, not a ceiling. This is the explicit record of the
  overflow behaviour: a fixed-width rule would silently change format the day a School's
  numbering outgrew it, which is precisely the trap this clause exists to close.
- **The width is a GLOBAL constant, not per-School.** It is a formatting convention, not
  tenant data, so it lives in code (`Invoice::NUMBER_PAD_WIDTH`) and **not** as a column
  on `finance_school_settings`. The prefix is per-School; the width is not.

Padding applies to the **numeric portion only** — the prefix is never padded.

(The prefixes are configured per-School via §7; this table is the authoritative list.)

**Enforcement — split:**

- Gap-tolerant uniqueness: **ENFORCED.** The Shared-Kernel `App\Support\Sequences`
  is exactly this — "monotonic but not guaranteed contiguous" (ADR 0033 / slice
  1.4b), with a per-`(scope, key)` unique counter. Because gaps are acceptable,
  **no gap-free work and no signed gap-free policy are needed** — the kernel is
  correct as-is. `GenerateInvoice`/`RecordPayment` already draw their number from
  it (per-School scope key).
- Per-School prefix + configurable defaults: **ENFORCED (2026-07-21),
  PRESENTATION-DERIVED.** `finance_school_settings.invoice_number_prefix` holds one
  prefix per School; `Invoice::displayNumber()` composes it with the stored integer and
  `InvoiceResource` exposes it as `display_number` **beside** the unchanged `number`.
  **Nothing is stored prefixed** — `finance_invoices.number` remains
  `unsignedBigInteger` under `UNIQUE(school_id, number)`, so no deployed table was
  altered and no live invoice was rewritten.

    Format per the table above: the prefix is stored **separator-less**, the `-` and the
    6-digit minimum pad are applied at render, and the width is the global
    `Invoice::NUMBER_PAD_WIDTH` rather than a per-School column.

    **Superseded note, kept for the record:** when this clause first landed the policy
    contained no width rule, so the render deliberately applied none — inventing a format
    the signed document did not specify would have been the greater error. The rule was
    then decided and written down, and the render followed it. That ordering is the
    point: the format is a documented decision, not an implementation default.

    NULL/blank means no prefix — the bare **padded** number (`000042`, no leading
    separator) — which is every School's state until one is configured.

- **Search by prefixed number: NOT BUILT, and deliberately not.** There is no
  invoice-number search anywhere today (no index route, no `where('number')` call, no
  Finance search UI — invoices are addressed by `{invoice:uuid}`). A normalizer that
  strips the active School's prefix is the right shape when a search surface exists;
  building it now would be a primitive ahead of its consumer, the same reasoning that
  defers the rounding op (§1). It belongs to the slice that adds invoice search.

## 3. Cancellation = VOID, never delete

**Policy:** an invoice is never removed. Cancellation sets a **VOID** status that
keeps the record permanently visible with a void stamp — the audit paper trail
Brookstone asked for. Explicitly **not** a hard delete, and explicitly **not**
Laravel `SoftDeletes`/`deleted_at` (soft-delete hides rows from default queries,
the opposite of a paper trail).

**Enforcement — split:**

- Never hard-deleted: **ENFORCED.** The `finance_invoices_no_delete` trigger
  (1.4c pattern) denies DELETE at the database; the model has a `deleting` guard.
- Not `SoftDeletes`: **ENFORCED by absence** — the `Invoice` model does not use
  the `SoftDeletes` trait and has no `deleted_at` column.
- The **VOID status** itself: **ENFORCED** (mark corrected 2026-07-21 — it was left
  reading PENDING after slice 2 shipped it). `InvoiceStatus::Void` exists alongside
  `Issued`, with `Invoice::isVoid()` and the `scopeExcludingVoid()` reporting scope —
  deliberately a NAMED scope, not a global one, so `{invoice:uuid}` binding still
  resolves a voided invoice and the double-void guard keeps working. Historical note,
  now inaccurate: `InvoiceStatus` currently has
  `Issued` and `Cancelled`; slice 2 renames/repurposes the terminal state to
  `Void` (a permanently-visible stamp) to match this policy's vocabulary.

## 4. Void must not leak into calculations

The one real risk of choosing void over soft-delete: a void invoice silently
counted in a total. Two mechanisms:

- **(a) Default query scope excludes VOID** — seeing void invoices requires
  explicit intent (an audit/statement view), so no ordinary total includes them.
  **ENFORCED** (mark corrected 2026-07-21 — shipped in slice 2, left reading PENDING).
  `Invoice::scopeExcludingVoid()`, applied by the read model, with `includeVoid` as the
  explicit opt-in.
- **(b) Voiding posts a reversing ledger entry** so the subledger nets a voided
  invoice to zero. **ENFORCED today (as "cancel").** `CancelInvoice` posts a
  `Reversal` ledger entry equal to the negated charge; `WalkingSkeletonTest`
  proves the student balance nets to zero after cancellation while both ledger
  rows survive (append-only). Slice 2 renames this path cancel→void.

**Slice-2 gate test (record, not yet built):** void a non-zero invoice → the
student's balance is unchanged (the charge and its reversal net to zero), the void
invoice is **absent from default totals** but **present on the audit/statement
view**.

## 5. Waivers and discounts

**Policy:** the statement shows the **original full fee** with the reduction
displayed **beneath** it — never a single netted figure. This is §7 statement
integrity: snapshot lines, each captured at billing time, never a recomputed net.
The approver is configurable in-system (Ph3 maker-checker).

**Enforcement — PENDING (Ph2/Ph3).** No waiver/discount feature exists yet
(§3 discount / §10 credit-note-write-off are Ph2+). The frozen convention it will
build on is real: invoice lines are immutable snapshots (`finance_invoice_lines`,
append-only triggers), so a historical statement never re-renders with a new net.
The approver _rules_ are Ph3 (maker-checker).

## 6. Repeat billing

**Policy:** a repeat enrollment episode is billed **fresh, like any episode**;
whether it is waived, discounted, or charged in full is a manual per-case
adjustment. No repeat-specific billing logic.

**Enforcement — ENFORCED by design (no special code).** The registrar ruling
(terminal statuses are pure academic facts; every episode bills uniformly) means
Finance has, and needs, **no** repeat branch — `GenerateInvoice` bills any
enrollment identically. The absence of repeat-specific logic _is_ the enforcement;
adjustments ride the ordinary waiver/discount path (§5, Ph2+).

## 7. Configurability

**Policy:** the number prefix (§2), the waiver approver (§5), and repeat treatment
(§6) are **per-School configurable**. The schema should leave room for School-scoped
Finance configuration; the config engine is **not** built now.

**Enforcement — PARTIAL (2026-07-21).** The School-scoped store now exists:
`finance_school_settings`, one row per School (`UNIQUE(school_id)`, durable RESTRICT FK
— a top-level Finance table owning `school_id` directly, per the ownership template).

- **Number prefix (§2): BUILT.** `invoice_number_prefix`, read via
  `SchoolFinanceSettings::invoiceNumberPrefixFor()`.
- **Waiver approver (§5) and repeat treatment (§6): NOT BUILT, deliberately.** Neither
  has a consumer yet, and guessing their type and semantics now is the same
  front-load-ahead-of-the-consumer mistake that defers the rounding op (§1). The table
  is _shaped for_ them — adding a column later is additive — but it holds only what is
  actually read today.

The store was built together with its first consumer on purpose: a config table with no
reader is a schema answering a question nobody asked, verifiable only against tests we
would also have invented.

## 8. Deferred / open (not decided here)

- ~~**422-vs-400 validation convention**~~ — **RESOLVED 2026-07-21.** Business-rule and
  validation errors standardise on **422 app-wide**, changed at the single
  `validation_error` macro whose only production caller is the `ValidationException`
  renderer. Finance's interim 422 is now the app-wide rule rather than a local
  divergence, so this is no longer deferred or a Finance-specific caveat.
- **Waiver approver rules** — Ph3 maker-checker.
- **GL / Sage mapping** — §13, a later phase (the subledger is the only ledger now).

---

_Summary — what is real today vs policy-for-later:_ gap-tolerant numbering,
never-hard-delete, not-soft-delete, the reversing-ledger-nets-to-zero mechanism,
snapshot-line integrity, and no-repeat-logic are **enforced now**. Banker's
rounding, the VOID status + default-exclude-void scope, per-School prefixes, the
waiver/discount presentation, and School-scoped config are **confirmed policy,
enforcement pending slice 2 / Ph2-3**. The doc claims no guard that does not exist.
