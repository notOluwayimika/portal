# `BelongsToSchool` issues an `information_schema` query on EVERY insert, of EVERY school-owned model

**Raised by** `perf/manual-run-batch-enrollment-resolve`, while measuring what remained of
`StartManualInvoiceRun`'s cost after the per-student resolver was replaced by a batch read.
**Not a defect in that branch** — it predates it, it is repo-wide, and the branch deliberately did
not fix it. It is written down here because it is now the _dominant_ cost of that Action and of every
other bulk insert path in the codebase, and because nothing else records it.

## The mechanism

```php
app/Concerns/BelongsToSchool.php:15   bootBelongsToSchool
    static::creating(function ($model) {
        if (Schema::hasColumn($model->getTable(), 'school_id') &&
            ! $model->school_id &&
            ($schoolId = ActiveSchool::id())
        ) {
            $model->school_id = $schoolId;
        }
    });
```

`Schema::hasColumn()` is `Schema\Builder::getColumnListing()`, which is `getColumns()`, which is a
live query against the catalogue:

```sql
select column_name as `name`, data_type as `type_name`, column_type as `type`,
       collation_name as `collation`, is_nullable as `nullable`, column_default as `default`,
       column_comment as `comment`, generation_expression as `expression`, extra as `extra`
  from information_schema.columns
 where table_schema = schema() and table_name = ?
 order by ordinal_position asc
```

**Laravel does not cache it.** The neighbouring framework call on the same `creating` path _is_
cached — `GuardsAttributes::isGuardableColumn()` memoises into a static `$guardableColumns` keyed by
class, so it costs one query per model class per process. `Schema::hasColumn()` has no such cache, so
it costs one query per **row**.

It is also the FIRST operand of the `&&`, so it runs even when there is no ambient School and nothing
would have been filled — the two cheap tests that could short-circuit it are both to its right.

## What it costs, measured

`portal_testing`, MySQL 8.0.43, one `StartManualInvoiceRun` over 611 students (611 target rows, plus
the run row and one line row — 613 model inserts):

|                                                     | queries  |
| --------------------------------------------------- | -------- |
| `finance_manual_invoice_run_targets` inserts        | 611      |
| run + line inserts                                  | 2        |
| the batch enrollment resolve (root + 7 eager loads) | 8        |
| **`Schema::hasColumn()` from this hook**            | **613**  |
| **total**                                           | **1234** |

So **half of the Action's round trips are this hook**, and they are the half that scales with the
selection. Before the batch resolve landed the same run was 6030 queries and the hook was invisible
inside the resolver's 4888; it is now the thing to fix next if this path is made to matter.

Every school-owned model pays it on every insert, so the reach is far wider than one Action: the
opening-balance import, the bulk invoice run's rows, the ledger writes, every seeder.

## Why it was not fixed on that branch

Scope. That branch replaced one resolver call and was explicitly bounded to it. This fix touches the
trait every school-owned model in the repository uses, so it earns its own change with its own
proofs — in particular, proof that whatever memoisation is added is invalidated correctly under
`RefreshDatabase` and `migrate:fresh`, which is exactly where a stale "this table has no `school_id`"
answer would be silently wrong and would stop the column being filled.

## The shape a fix probably takes

Memoise per table for the process, the way `isGuardableColumn()` already does per class — or reorder
the `&&` so `ActiveSchool::id()` and `! $model->school_id` are evaluated first, which removes the
query from every insert that happens outside a School context but keeps it on the ones that matter.
The reorder is cheap and partial; the memo is the real fix. **Do not simply delete the check**: it is
what stops the hook writing `school_id` onto a model whose table has no such column.

## What already points at this

`tests/Feature/Finance/ManualInvoiceRunScreenTest.php` § 7 excludes schema-catalogue reads from its
query-shape arm by FROM-clause and names this ticket's mechanism in its preamble. It deliberately
does **not** assert the hook's magnitude — pinning a defect's size is how a defect gets preserved —
so that arm stays green on the day this is fixed.
