# TICKET — `create_finance_bank_accounts_table`'s docblock describes a commit that did not happen

**Status:** open, not implemented.

**Provenance.** This was the "Separate observation" section of
`reduction-guard-proof-12-db-is-vacuous.md`, which recorded it only because it was found in the same
reading and said explicitly: *"Do not fix it as part of closing the above; a migration comment
correction is its own commit."* That parent ticket was closed and deleted by
`fix/u8-reduction-guard-field-errors` (U8 commit 3), which repaired `proof 12 (DB)`. This observation
was **not** closed by that commit, so it is carried here rather than deleted with its host. Re-verified
against the repo when it was moved; both migrations still read as described.

---

`database/migrations/2026_08_10_100000_create_finance_bank_accounts_table.php:10` says:

```
 * Commit 2 makes `bank_account_id` NOT NULL on payments, fee items and invoice lines.
```

Commit 2 is `2026_08_10_120000_finance_bank_account_foreign_keys.php`. What it actually does:

| Table | What `…120000` does | Matches the claim? |
|---|---|---|
| `finance_payments` | `$table->foreignId('bank_account_id')->nullable()->after('origin')` (`:92`) | no — **nullable**, not NOT NULL |
| `finance_fee_items` | `$table->foreignId('bank_account_id')->after('school_id')` (`:110`), no `nullable()` | yes |
| `finance_invoice_lines` | nothing — `DELIBERATELY NOT IN SCOPE` (`:49`) | no — the column is never added |

So the sentence is wrong about two of the three tables it names, and `…120000`'s own docblock
(`:49-62`) argues at length for the very thing `…100000` says it does not do.

## Why it is worth a commit

A reader who trusts `…100000` concludes `finance_invoice_lines.bank_account_id` exists and is
required. **That is not hypothetical — it is exactly the belief encoded in the defect the parent
ticket documented.** `proof 12 (DB)` passed a `bank_account_id` key into `finance_invoice_lines` for
months; the insert died at 1054 before the trigger ran, and the arm was green while proving nothing.
A comment that misdescribes the schema produced a test that misdescribed the guard.

## The fix

Both migrations have shipped. The correction is a **comment change to `…100000:10`**, not a schema
change to anything. Something like: commit 2 adds `bank_account_id` to payments (nullable, with a
CHECK tying it to `origin`) and to fee items (required), and deliberately leaves invoice lines alone.
