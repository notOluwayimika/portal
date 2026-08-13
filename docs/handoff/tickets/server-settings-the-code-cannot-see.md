# TICKET — server settings the code cannot see, and no source-reading gate ever will

**Status:** OPEN, and open as a *class* rather than a defect. There is nothing to fix in the
repository today; there is something to stop claiming, and a shape to recognise the next time it
appears. **Two members so far.**

**Why it is a ticket and not a lint:** every member of this class is a **server-level MySQL
variable** that differs between environments and changes what the database does with a `TIMESTAMP`.
None of them is visible to anything that reads source. `bin/quality` reads source. The only
instrument that could observe any of them is a **schema-level check against a live database**, which
`bin/quality` is not and was never designed to be (ADR 0053).

## The shape

> A migration and a source file can be identical across two environments, and the resulting column
> can behave differently, because a setting neither file mentions decided the difference.

That sentence is the whole ticket. Both members below fit it exactly, and both were found the same
way — by reading a **deployed schema**, after a source-only survey had already reported clean.

## Member 1 — the session time zone (`time_zone`)

Fully written up in [`stored-epoch-offset.md`](stored-epoch-offset.md); summarised here only so the
two members sit side by side.

`config/database.php` pins no connection timezone, so every connection inherits the server's. On the
shared production host that is `+05:30` and is not ours to set. A PHP-written column stores early by
the session offset and reads back exact; a `NOW()`-written column stores the true instant and reads
back ahead by it. That shipped once, in the single writer of the money projection, and put
`last_activity` five and a half hours in the future.

**What has enforcement:** `bin/ci-sql-clock-lint.php` (`bin/quality` step 12) — and it enforces the
**source** half only, which is exactly the point being made here. It cannot see the setting; it
enforces a rule that exists *because of* the setting.

Re-derived on this host at the time of writing: `time_zone = SYSTEM`, `system_time_zone = WAT`
(`+01:00`). Not `+05:30`. **The two environments differ, and nothing in the repository records
which one you are on.**

## Member 2 — `explicit_defaults_for_timestamp`

When this variable is **OFF**, MySQL takes a `NOT NULL` `TIMESTAMP` column declared with no default
and silently gives it `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`. The column is then
written *and re-written* by the **server's** clock — the defect the sql-clock lint exists to prevent
— from a declaration that contains no clock function, no `->useCurrent()`, and nothing whatsoever
distinguishing it from the safe case.

**This is observed, not reasoned.** The same migration file produces two different columns:

```
                  notices.starts_at   —   $table->timestampTz('starts_at');
                  (database/migrations/2026_06_27_000001_create_notices_tables.php:32)

portaa10_portal   timestamp  NOT NULL  default='CURRENT_TIMESTAMP'
                                       extra=DEFAULT_GENERATED on update CURRENT_TIMESTAMP
portal_testing    timestamp  NOT NULL  default=NULL   extra=
```

One declaration, two databases on the same machine, different DDL. No source-reading check can
distinguish them because there is nothing in the source to read.

**Correct the causal story before repeating it.** `explicit_defaults_for_timestamp` reads **`ON`**
on this host, for both databases — so the local value is not what produced the `notices` column.
`portaa10_portal` is a **copy of production**, and its `notices` table carries the schema it was
created with, on a server where the setting was OFF. The evidence for OFF is therefore the *column
definition itself*, not a variable reading, and that distinction is worth keeping: the setting
cannot be read for the environment that matters from here at all.

### The four declaration sites

Every column in `portaa10_portal` carrying a server-clock default, with where it comes from:

| Column | Declared as | Origin | Status |
|---|---|---|---|
| `jobs.failed_at` | `$table->timestamp('failed_at')->useCurrent()` | explicit | named exception in the lint |
| `audit_logs.created_at` | `$table->timestampTz('created_at')->useCurrent()` | explicit | named exception in the lint |
| `authz_observations.occurred_at` | `$table->timestamp('occurred_at')->useCurrent()` | explicit | named exception in the lint |
| `notices.starts_at` | `$table->timestampTz('starts_at')` | **implicit — the server added it** | invisible to the lint, and always will be |

The first three are visible to `scanUseCurrent()` and exempt with their reasons. The fourth is this
ticket.

### The finance declaration, and why it is benign for a reason

`database/migrations/2026_08_09_120000_finance_capture_columns_s2_s3.php:74` declares
`$table->timestamp('posted_at')->after('narration')` on **`finance_ledger_transactions`** — a bare,
`NOT NULL`, defaultless `TIMESTAMP`, i.e. exactly the declaration shape that acquires the implicit
default. It is benign, and **not by luck**:

1. **`ON UPDATE` cannot fire.** The table is append-only, enforced by named MySQL triggers —
   `finance_ledger_transactions_no_update` (UPDATE) and `finance_ledger_transactions_no_delete`
   (DELETE), both re-derived from `information_schema.TRIGGERS`. An UPDATE is refused with SQLSTATE
   `45000` before any `ON UPDATE CURRENT_TIMESTAMP` could rewrite the column. The half of this
   hazard that destroys data is structurally unreachable on this table.
2. **The `DEFAULT` is moot.** Every writer supplies `posted_at` explicitly:
   `app/Finance/Services/SubledgerPoster.php:113` (`'posted_at' => $postedAt`, the single captured
   instant) and `app/Finance/Actions/PostOpeningBalanceBatch.php:310` (`'posted_at' => now()`). A
   default only fires for an INSERT that omits the column, and none does.
3. **On this host the column carries no default at all** —
   `information_schema.COLUMNS` reports `default=NULL, extra=''` for it on `portaa10_portal`,
   because the migration ran here with the setting ON.

So the money column is safe on both counts that matter, and safe for reasons a reader can check
rather than because nobody has hit it yet. **What is NOT established is what that column looks like
on production**, where the migration has not run and where the setting is evidently OFF. Reason 1
holds regardless — the triggers ship in the same migration stream — and reason 2 holds regardless,
because it is a property of the writers. Reason 3 is the one that would not.

## What follows from this — and what does not

**Does not follow:** growing `bin/ci-sql-clock-lint.php`. The implicit default is created by the
server, not written in the migration. A source lint that tries to infer it would have to flag every
bare `$table->timestamp(...)` in the repository — a rule that refuses safe code, which is the exact
failure mode this lint's design history spent three rounds removing.

**Does follow, if anyone wants a mechanism:** a schema-level check against a migrated database,
asserting the set of columns carrying a `CURRENT_TIMESTAMP` default matches a named list. On a
freshly-migrated `portal_testing` that set is exactly the three explicit ones, so such a check would
land at zero today and would be real. It belongs with `bin/quality-clean-db` rather than in the
per-push floor, because it needs a database, and it would still only observe **this machine's**
setting — never production's.

**Follows immediately, and is done:** stop claiming behaviour that source cannot establish.
`bin/ci-sql-clock-lint.php` said "the tree is at zero in BEHAVIOUR as well as in tokens"; it now says
zero **in tokens**, and names this class in its "WHAT IT CANNOT SEE" block.

## Related

- [`stored-epoch-offset.md`](stored-epoch-offset.md) — member 1, in full. The permanent condition and
  the read-layer rule.
- [`sql-clock-lint.md`](sql-clock-lint.md) — the gate that enforces the source half of member 1, and
  where the corrected claim lives.
- [`notice-end-destroys-starts-at.md`](notice-end-destroys-starts-at.md) — a live consequence of
  member 2 on `notices.starts_at`, derived and **not yet reproduced**.
- `docs/adr/0053-local-enforcement-floor.md` — why the floor is local, and the residuals it already
  accepts. This class is adjacent to "Clean-room OS/env" and is arguably a fifth residual: *the
  database's own settings are part of the environment the floor cannot control.*
