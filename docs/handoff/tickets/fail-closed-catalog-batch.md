# The fail-closed CATALOG batch — `FeeSchedule` and `FeeItem` are not in the list

**Status:** deferred, deliberately, by the project lead at the time of the transactional batch.
**Raised as a ticket now** because until this file existed the deferral was recorded only inside a
report — `docs/handoff/reports/feat-rbac-fail-closed-finance.md:97-98` and `:499` — and a deferral
that lives only in a report is one nobody will action. **No ticket file existed**; the remediation
brief for PR #234 assumed one did.

## The fact

`config/rbac.php`'s `fail_closed_models` holds **ten** models, read from the running app:

```
LedgerTransaction, Payment, PaymentAllocation, Invoice, InvoiceLine,
CreditNote, StudentAccount, OpeningBalanceBatch, OpeningBalanceRow, VoidRequest
```

All ten are transactional. **`FeeSchedule` and `FeeItem` — the catalog — are not among them**, along
with the rest of the six catalog models. The batch-1 report records that the enumeration found **no
blocker** for them (they have no no-context reader), so the case for a second batch was already made
and the omission is scope, not risk.

## Why it matters more than it looks — evidence from PR #234 (finding A4)

PR #234 added a validation rule on `GenerateInvoiceRequest.lines.*.fee_item_id` that resolves the id
through `FeeItem::query()`, and its comment says **"SchoolScope IS the isolation"**. That claim is
true of the rule as written — the model carries `BelongsToSchool`, and the watched red confirms it:
mutating to `FeeItem::withoutGlobalScopes()` turns a foreign School's item from 422 into **201**.

But it is **stronger than the configuration supports**. For the ten fail-closed models, a missing
school context *throws* — the isolation is enforced whether or not a caller remembers to establish
context. For `FeeItem`, `SchoolScope` applies only when there is a context to apply; with none, the
scope is simply not the guarantee the transactional models get. Any future reader taking
"SchoolScope IS the isolation" as a general statement about the catalog would be reading a promise
the config does not make for these two models.

So the sentence is accurate about the mechanism and optimistic about the guarantee, and the gap
closes when the catalog batch is flipped.

## What flipping it involves

The same three steps the transactional batch took, and the enumeration is the whole job:

1. **Enumerate every no-context reader** of the six catalog models BEFORE flipping — the discipline
   the transactional batch used, and the reason it landed without incident. Batch 1's enumeration
   already reported none for these, but that was several commits ago and must be re-derived, not
   carried.
2. Add the surviving models to the versioned default in `config/rbac.php` (they are a default with
   an env override; a blank env var must not disable the batch — see that file's `trim(...) ?: …`).
3. Watched red per model, observed in the running app rather than in the diff.

**Re-derive before flipping.** PR #234 added the first reader of `FeeItem` that is not a fee-schedule
path — the invoice-generation validation rule above — which is exactly the kind of arrival the
enumeration exists to catch.
