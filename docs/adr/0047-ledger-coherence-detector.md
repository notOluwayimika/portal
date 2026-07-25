# 0047 — Document↔ledger coherence: a detect-only sibling to reconcile-accounts

**Status:** Accepted — 2026-07. **Deciders:** owner + advisor. Ships as a thin vertical
slice under [0046](0046-finance-delivery-thin-vertical-slices.md). Adds a detector and a
schedule entry; changes no request-path behaviour and no schema.

## Context

`finance:reconcile-accounts` (§15F) verifies exactly one thing:

```
finance_student_accounts.balance_minor  ?=  SUM(signed ledger amount_minor)   per (school, student)
```

It treats the **ledger as truth** and the balance as a projection — which is what makes
its `--fix` honest. But nothing checks the ledger against the **documents** that produced
it. If `ApproveVoidRequest` posted two reversals, or a reversal whose amount did not match
the charge, or an invoice were flipped to `void` by a path that posted no reversal at all,
reconcile-accounts would report **no drift** — the projection would faithfully mirror the
wrong ledger, and both would agree with each other while being wrong about the documents.

Today, correctness on that boundary lives entirely in the control flow of four Actions
(`GenerateInvoice`, `RecordPayment`, `ApproveVoidRequest`, `ApproveCreditNote`).
`ApproveVoidRequest` says so in its own comment — exactly one reversal is posted, and there
is no ledger-level guarantee of it. That is a convention wearing an invariant's clothes.

## Decision

Add **`finance:audit-ledger-coherence`** — a read-only detector that verifies the subledger
is coherent with its source documents. Seven assertions, all derived from the
`(source_type, source_id, type)` linkage, driven from the document side (no index added to
the hot-write ledger table):

- **I1** every row's `type` is a known `LedgerEntryType`;
- **I2** `source_type` is known and the referenced row exists in-school;
- **I3** an `issued` invoice has no reversal;
- **I4** a `void` invoice has exactly one reversal = −Σ(charges);
- **I5** an approved credit note has one posting = −amount, a submitted/rejected one has none;
- **I6** each invoice has exactly one charge = its total (single-charge today);
- **I7** every row's `amount_currency` matches its source document's and its account's.

**It is a SIBLING command, not a flag on reconcile-accounts, and it is DETECT-ONLY — there
is deliberately no `--fix`.** Both points follow from one fact: the two commands have
**different truth models.**

- reconcile-accounts has a known-right side (the ledger), so repair is mechanical → `--fix`.
- This detector has **no** known-right side. A void invoice with no reversal has two equally
  consistent stories — a reversal that should have posted and did not, or a status that
  should never have flipped — and repairing either way writes money-affecting data on a
  guess (forgive a real debt, or re-charge a cancelled invoice).
- And it **could not** repair the ledger even if it wanted to: `finance_ledger_transactions`
  is append-only (the `no_update`/`no_delete` triggers, §15C), so a `--fix` could only touch
  the document side — the side it is least sure is wrong.

Merging the two into one command would produce a single `--fix` that repairs one class of
finding and refuses another — a footgun with money behind it. So they stay separate, and
this one reports, exits `FAILURE`, and leaves the decision to a human. A repair path, if ever
wanted, is a separate decision with a named owner — not a flag added quietly.

**Rider A — `type` stays a detector, not a DB constraint.** The advisor floated promoting I1
to a `CHECK`/`ENUM`. Rejected, for two reasons the code makes decisive:

1. The free varchar `type` is a **documented, deliberate choice** (`LedgerEntryType`
   docblock): it keeps adding a movement type (a future refund) a PHP-only change, not a
   migration. A CHECK reverses that.
2. A CHECK would be **weaker** than the detector anyway. MySQL compares with the column's
   collation, which is case- and trailing-space-insensitive, so `type IN ('charge', …)`
   would **accept** `'CHARGE'` and `'charge '` — the exact values that break the PHP enum
   cast (`LedgerEntryType::from`, which is byte-exact). The detector compares with `BINARY`
   and catches them; the constraint cannot. (Both dev and drive `type` columns are clean
   today, so a constraint *could* be added without a blocking row — but shouldn't be.)

## Consequences

**Positive:** the document↔ledger boundary — previously guarded only by four Actions' control
flow — now has an independent nightly check; every assertion is bite-proven with a planted
incoherence and per-assertion watched-red. The `BINARY` comparison hardens the detector
against collation-hidden corruption that a naive `IN` (or a CHECK) would miss.

**Negative / accepted:** it is a scheduled command, not a real-time guarantee — a corrupt
row is caught on the next daily run, not at write time (the same trade §15F made, and for the
same reason: a coherence aggregate has no place in the hot write path). And it detects, never
prevents: the guarantees that ARE absolute stay at the DB (append-only, FKs, the maker≠checker
CHECK). Out of scope and named as the next candidate: document↔document coherence ("an invoice
is `void` **iff** an approved void request exists").

**Also shipped here:** `finance:audit-duty-separation` (ADR 0040 arc) was scheduled — it had
shipped unscheduled, so the SoD detection net the enforcement slice relies on now actually runs.
