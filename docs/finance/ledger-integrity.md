# Ledger integrity — what is guaranteed vs what is detected

Three different questions hide under "the ledger is correct," and conflating them is how an
overstated guarantee gets relied on. State them apart — the same discipline
[segregation-of-duties.md](segregation-of-duties.md) applies to duty separation.

## Structural level — ABSOLUTE, enforced by the database

These hold **regardless** of application code, against raw SQL and `tinker`:

- **Append-only.** `finance_ledger_transactions` refuses `UPDATE` and `DELETE` — the named
  `no_update` / `no_delete` triggers (Constitution §15C). A correction is a *reversing entry*,
  never an edit. This is why neither detector below can offer a ledger `--fix`: the ledger is
  not editable by anyone, by design.
- **Money immutability.** An invoice's / credit note's money columns cannot change after
  creation (the `*_total_immutable` BEFORE-UPDATE triggers); only status and decision columns move.
- **Maker ≠ checker.** Every approval table carries `CHECK (submitted_by <> decided_by)` — no
  one approves their own submission, by any path (see the SoD doc).
- **Durable referents.** `school_id` and `student_id` are real FKs (RESTRICT). `source_type` /
  `source_id`, however, is a **soft** polymorphic reference — no FK is expressible for it, which
  is exactly why coherence there needs a *detector* (I2 below).

## Projection level — DETECTED, `finance:reconcile-accounts` (§15F)

**`finance_student_accounts.balance_minor` is a projection of the ledger**, maintained
atomically by `SubledgerPoster::post` on every movement. reconcile-accounts re-derives it and
reports drift:

```
balance_minor  ?=  SUM(signed ledger amount_minor)   per (school, student)
```

It has a **known-right side** — the ledger is definitionally truth here — so its `--fix` is
honest: correct the projection to the ledger. It exits non-zero on drift.

## Coherence level — DETECTED, `finance:audit-ledger-coherence` (ADR 0047)

reconcile-accounts trusts the ledger. **Nothing else checks the ledger against the documents
that produced it.** If a void posted two reversals, or a reversal whose amount did not match the
charge, or an invoice were flipped to `void` with no reversal at all, reconcile-accounts reports
**no drift** — the projection faithfully mirrors the wrong ledger, and both agree with each other
while being wrong about the documents. This detector closes that boundary.

Seven assertions, from the `(source_type, source_id, type)` linkage, driven from the document
side (so it uses the existing `(school_id, student_id)` index and adds nothing to the ledger's
hot write path):

| # | Assertion | Constraint-shaped? |
|---|---|---|
| I1 | every row's `type` is a known `LedgerEntryType` | **No — kept a detector deliberately** (below) |
| I2 | `source_type` known + referenced row exists in-school | No — polymorphic FK is not expressible |
| I3 | an `issued` invoice has NO reversal | No — cross-row aggregate |
| I4 | a `void` invoice has EXACTLY ONE reversal = −Σ(charges) | No — cross-row aggregate |
| I5 | approved credit note ⇒ one posting = −amount; submitted/rejected ⇒ none | No — cross-row aggregate |
| I6 | each invoice has exactly one charge = total (single-charge today, R3) | No — cross-row aggregate |
| I7 | every row's `amount_currency` = its source's & its account's currency | No — cross-table |

**Detect-only — there is no `--fix`, and that is the finding, not a limitation.** Unlike
reconcile-accounts, this has no known-right side: a void invoice with no reversal is equally
consistent with "a reversal that should have posted" and "a status that should never have
flipped," and repairing either way writes money on a guess. And the append-only ledger *cannot*
be repaired anyway. So: report, exit `FAILURE`, let a human decide (ADR 0047 §Decision).

**Why I1 is a detector, not a `CHECK` (rider A).** Two reasons the code makes decisive:

1. The free varchar `type` is a **deliberate, documented** choice (`LedgerEntryType` docblock):
   it keeps adding a movement type a PHP-only change, not a migration. A CHECK reverses that.
2. A CHECK would be **weaker** than the detector. MySQL compares with the column's collation —
   case- and trailing-space-insensitive — so `type IN ('charge', …)` **accepts** `'CHARGE'` and
   `'charge '`, the very values that break the byte-exact PHP enum cast. The detector compares
   with `BINARY` and catches them; the constraint cannot. (`type` is clean in dev and drive
   today, so a constraint *could* be added without a blocking row — but shouldn't be.)

## The one-line summary

The ledger is **un-editable** (database). The account balance is a **projection**, its drift
**detected and fixable** (reconcile-accounts). The ledger's agreement with its **documents** is
**detected, never fixed** (audit-ledger-coherence — no honest repair exists). Do not let a doc,
comment, or UI imply the third one is prevented at write time — it is checked nightly, and the
only write-time guarantees are the structural ones at the top.
