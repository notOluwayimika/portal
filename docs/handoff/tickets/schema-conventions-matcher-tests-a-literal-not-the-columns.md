# TICKET — the maker≠checker schema invariant matches a literal, not the table's own columns

**Status:** open, deliberately not fixed on
`fix/check-constraints-as-triggers-for-mysql-5-7`. Found by that branch's cold review, which
established the property by listing every table the matcher sweeps rather than by reading the SQL.

## The invariant, and what it actually asserts

`SchemaConventionsTest` finds every table carrying a maker/checker column pair with

```sql
c1.COLUMN_NAME LIKE 'submitted\_by%' AND c2.COLUMN_NAME LIKE 'decided\_by%'
```

and requires each one to carry a `BEFORE INSERT` / `BEFORE UPDATE` trigger pair whose body **names
both columns**. Measured against the real schema it sweeps in exactly six tables and no others:

```
finance_credit_notes              submitted_by,decided_by
finance_discount_policy_changes   submitted_by,decided_by
finance_fee_schedule_changes      submitted_by,decided_by
finance_opening_balance_batches   submitted_by_user_id,decided_by_user_id
finance_void_requests             submitted_by,decided_by
subject_result_statuses           submitted_by,decided_by
```

Those are the six approval documents. Nothing is swept in that should not be. The test is doing its
job today, and the cold review made it go red three ways — pair removed, body emptied, and a `CHECK`
put back in place of the triggers.

## The gap

The body check looks for the **literal strings** `submitted_by` and `decided_by`, not for the column
names of the table it is checking. It passes on `finance_opening_balance_batches` only because
`submitted_by_user_id` happens to contain `submitted_by` as a substring.

So a future approval table naming its columns `submitted_by_x` and `decided_by_y`, guarded by a
trigger that references some *other* table's `submitted_by` column — or that merely mentions the
strings in a comment inside the body — satisfies the invariant while enforcing nothing. The test
would be green and the table unguarded.

This is not reachable with the six tables that exist. It becomes reachable the moment a seventh
approval table is added, which is exactly when this test is supposed to be earning its keep.

## Why it was left

The branch that found it was replacing eight `CHECK` constraints with fourteen triggers days before
cutover. Widening the invariant was already a judgement call on that branch (its deviation 3);
rewriting the matcher to resolve each table's real column names and assert on those is a second,
independent change to a test that currently passes and currently catches everything in front of it.
Two judgement calls in one commit is one too many.

## What a fix looks like

Resolve the pair per table from `information_schema.COLUMNS`, then assert the trigger body contains
*those* names rather than a fixed literal. The seven-table sweep already in the test gives the
resolved names for free.

The trigger for doing it is the addition of a seventh maker-checker table. If that lands before
someone picks this up, the invariant is green and wrong on the exact table it was widened to catch.
