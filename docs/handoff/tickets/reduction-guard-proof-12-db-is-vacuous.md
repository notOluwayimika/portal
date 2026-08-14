# TICKET — `proof 12 (DB)` never reaches the trigger it is named after

**Status:** open, not implemented. Found by `feat/u8-wire-ids-uuid` (U8 commit 1) while writing a
new DB-layer arm modelled on this one; deliberately not fixed there, because repairing a
reduction-guard proof is not a wire-id change and would have made that commit unreviewable.

**Root:** the arm inserts a `bank_account_id` key into `finance_invoice_lines`, which has no such
column. MySQL rejects the statement at **1054** before it fires any trigger, and
`toThrow(QueryException::class)` cannot tell that apart from
`finance_invoice_lines_reduction_guard` doing its job.

## The arm

`tests/Feature/Finance/ReductionEnforcementTest.php:134-149`

```php
it('proof 12 (DB) — a RAW reduction-line insert citing a requires_approval=true policy trips the trigger', function () {
    …
    expect(fn () => DB::table('finance_invoice_lines')->insert([
        'uuid' => (string) Str::uuid(), 'school_id' => $school->id, 'invoice_id' => $invoiceId,
        'bank_account_id' => testBankAccountId(), 'description' => 'Sneak', 'kind' => 'discount', …
    ]))->toThrow(QueryException::class);          // ← :148
});
```

Dumping `errorInfo` from inside that expectation prints:

```
1054
"Unknown column 'bank_account_id' in 'field list'"
```

Not `1644`, which is what MySQL reports for the `SIGNAL SQLSTATE '45000'` the guard raises
(`database/migrations/2026_07_26_140002_add_discount_policy_to_finance_lines.php:84-88`).

The same defect was measured a second way on the twin arm this ticket's author wrote: swapping the
policy for one that should be perfectly legal left the arm **green**, because the insert dies for
the same unrelated reason either way. An assertion that cannot distinguish its own subject from its
opposite is not testing the subject.

## The column does not exist, and its absence is a decision

`SHOW COLUMNS FROM finance_invoice_lines` (local `portal_testing`, migrated from this branch):

```
id  uuid  school_id  invoice_id  description  kind  note  amount_minor  amount_currency
fee_item_id  discount_policy_id  created_by_user_id  created_at  updated_at
```

No migration ever adds it. `grep -rln bank_account_id database/migrations/` returns three files —
`2026_08_07_110000_add_provenance_to_finance_payments.php`,
`2026_08_10_100000_create_finance_bank_accounts_table.php` and
`2026_08_10_120000_finance_bank_account_foreign_keys.php` — and the third names
`finance_invoice_lines` exactly once, in a heading:

```
database/migrations/2026_08_10_120000_finance_bank_account_foreign_keys.php:49
 * ─── finance_invoice_lines — DELIBERATELY NOT IN SCOPE ────────────────────────────
```

with the argument stated beneath it (`:50-62`): the account is meant to travel from the fee item
onto the line as a snapshot, lines are still free text with no fee catalog behind them, and a
nullable column with no writer would be a primitive ahead of its consumer. So the column is absent
on purpose and will stay absent until fee items are actually the source of lines. **The test is
wrong, not the schema**, and a fix that adds the column to satisfy the test would be a change this
ticket argues against.

## Consequence, stated plainly

**Nothing currently proves the `requires_approval` branch of
`finance_invoice_lines_reduction_guard` at the DB layer.** Removing that branch from the trigger
leaves `proof 12 (DB)` green.

`proof 12 (HTTP)` (`:122-132`) still covers the rule, but only through the service: it posts a
reduction citing a `requires_approval = true` policy and asserts 422. That 422 is produced by
`GenerateInvoice`'s catch of driver code 1644 (`app/Finance/Actions/GenerateInvoice.php:257-272`,
`isReductionGuardViolation` at `:478`) — so it exercises the trigger *and* the catch as one unit,
and cannot say which of the two refused. The DB arm exists precisely to separate them, and it does
not.

## The shape a fix takes

`proof 14 (DB)` in the same file, added by U8 commit 1, is the corrected form. Two differences, both
load-bearing:

1. **No `bank_account_id` key.** The insert lists only columns that exist, so it reaches the trigger.
2. **The assertion reads `errorInfo[1]` and requires `1644`**, plus the guard's message, instead of
   accepting any `QueryException`. Any other code means the row died before the trigger ran.

It was bite-proved by swapping in a policy that the guard should accept, which reds it:

```
The insert was not refused by finance_invoice_lines_reduction_guard. A different error code
means the row died before the trigger ran and this arm proves nothing about isolation.
Failed asserting that null is identical to 1644.
```

Applying the same two changes to `proof 12 (DB)` — and bite-proving it by swapping in a
`requires_approval = false` policy, which must then leave `$code` null — closes this.

**Check the other raw-insert arms in the same file while you are there.** This ticket documents the
two that were read; it does not claim they are the only two.

---

## Separate observation — a migration docblock that describes a commit that did not happen

Not the same defect, not a test problem, and recorded here only because it was found in the same
reading. **Do not fix it as part of closing the above**; a migration comment correction is its own
commit.

`database/migrations/2026_08_10_100000_create_finance_bank_accounts_table.php:10` says:

```
 * Commit 2 makes `bank_account_id` NOT NULL on payments, fee items and invoice lines.
```

Commit 2 is `2026_08_10_120000_finance_bank_account_foreign_keys.php`. What it actually does:

| Table | What `…120000` does | Matches the claim? |
|---|---|---|
| `finance_payments` | `$table->foreignId('bank_account_id')->nullable()->after('origin')` (`:92`) | no — **nullable**, not NOT NULL |
| `finance_fee_items` | `$table->foreignId('bank_account_id')->after('school_id')` (`:110`), no `nullable()` | yes |
| `finance_invoice_lines` | nothing — `DELIBERATELY NOT IN SCOPE` (`:49-62`) | no — the column is never added |

So the sentence is wrong about two of the three tables it names, and `…120000`'s own docblock
argues at length for the very thing `…100000` says it does not do. A reader who trusts `…100000`
concludes `finance_invoice_lines.bank_account_id` exists and is required — which is, as it happens,
exactly the belief encoded in the broken test above.

Both migrations have shipped. The correction is a comment change to `…100000:10`, not a schema
change to anything.
