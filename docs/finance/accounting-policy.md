# Finance accounting policy (Brookstone-confirmed, 2026-07)

The accounting decisions the Finance module implements, confirmed by Brookstone.
Each **enforcement** reference below is checked against the actual repo and marked
**ENFORCED** (mechanism named) or **PENDING [slice]** — a confirmed *policy* does
not imply the *guard* exists. Table/concept names read `finance_*`, matching the
frozen schema (`docs/finance/walking-skeleton-conventions.md`).

## 1. Rounding

**Policy:** banker's rounding (round-half-to-even) at the code level. On an uneven
split (e.g. a fee divided into installments that does not divide evenly), the
remainder lands on the **final** installment, so the parts reconcile to the total
exactly — no penny is created or lost.

**Enforcement — PENDING (slice 2, installments/splits).** Today `App\Support\Money`
deliberately offers **no** rounding- or division-bearing operation: only exact
integer scaling (`times(int)`), with a class docblock forbidding any rounding op
until this policy was signed. The policy is now signed, so the split/allocate
method (half-to-even, remainder-on-final) is built when the first consumer
(installments) lands — not before. Until then there is nothing to round: every
amount is an exact integer minor-unit value.

## 2. Invoice / receipt numbering

**Policy:** numbers must be **unique only — gaps are acceptable** (not gap-free).
Per-School prefix, configurable with defaults:

| School | Default prefix |
|---|---|
| Secondary | `BSS-` |
| Primary | `BSP-` |
| Abuja | `BSA-` |
| Port Harcourt | `BSPH-` |

**Enforcement — split:**
- Gap-tolerant uniqueness: **ENFORCED.** The Shared-Kernel `App\Support\Sequences`
  is exactly this — "monotonic but not guaranteed contiguous" (ADR 0033 / slice
  1.4b), with a per-`(scope, key)` unique counter. Because gaps are acceptable,
  **no gap-free work and no signed gap-free policy are needed** — the kernel is
  correct as-is. `GenerateInvoice`/`RecordPayment` already draw their number from
  it (per-School scope key).
- Per-School prefix + configurable defaults: **PENDING (slice 2).** The number is
  an internal integer today; the prefix and its per-School configuration are not
  built (see §7 configurability). No hardcoded prefix exists yet.

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
- The **VOID status** itself: **PENDING (slice 2).** `InvoiceStatus` currently has
  `Issued` and `Cancelled`; slice 2 renames/repurposes the terminal state to
  `Void` (a permanently-visible stamp) to match this policy's vocabulary.

## 4. Void must not leak into calculations

The one real risk of choosing void over soft-delete: a void invoice silently
counted in a total. Two mechanisms:

- **(a) Default query scope excludes VOID** — seeing void invoices requires
  explicit intent (an audit/statement view), so no ordinary total includes them.
  **PENDING (slice 2).**
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
The approver *rules* are Ph3 (maker-checker).

## 6. Repeat billing

**Policy:** a repeat enrollment episode is billed **fresh, like any episode**;
whether it is waived, discounted, or charged in full is a manual per-case
adjustment. No repeat-specific billing logic.

**Enforcement — ENFORCED by design (no special code).** The registrar ruling
(terminal statuses are pure academic facts; every episode bills uniformly) means
Finance has, and needs, **no** repeat branch — `GenerateInvoice` bills any
enrollment identically. The absence of repeat-specific logic *is* the enforcement;
adjustments ride the ordinary waiver/discount path (§5, Ph2+).

## 7. Configurability

**Policy:** the number prefix (§2), the waiver approver (§5), and repeat treatment
(§6) are **per-School configurable**. The schema should leave room for School-scoped
Finance configuration; the config engine is **not** built now.

**Enforcement — PENDING (Ph2).** No School-scoped Finance config table or service
exists today, and nothing is hardcoded in its place (there is simply no prefix /
approver / repeat-rule mechanism yet). Slice 2+ introduces School-scoped Finance
config; this policy is the record that these three are configuration, not constants.

## 8. Deferred / open (not decided here)

- **422-vs-400 validation convention** — app-wide, still pending; Finance controllers
  return 422 in the interim (not frozen as an invariant).
- **Waiver approver rules** — Ph3 maker-checker.
- **GL / Sage mapping** — §13, a later phase (the subledger is the only ledger now).

---

*Summary — what is real today vs policy-for-later:* gap-tolerant numbering,
never-hard-delete, not-soft-delete, the reversing-ledger-nets-to-zero mechanism,
snapshot-line integrity, and no-repeat-logic are **enforced now**. Banker's
rounding, the VOID status + default-exclude-void scope, per-School prefixes, the
waiver/discount presentation, and School-scoped config are **confirmed policy,
enforcement pending slice 2 / Ph2-3**. The doc claims no guard that does not exist.
