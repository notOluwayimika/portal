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

### The exhaustive reading — one row, not "the four we knew about"

**This is the claim the ticket rests on, so it is a schema-wide query rather than a set of spot
checks.** Proposed by the project lead and re-run here against the production copy, 2026-08-13:

```sql
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_DEFAULT, EXTRA
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = 'portaa10_portal'
   AND EXTRA LIKE '%on update CURRENT_TIMESTAMP%'
   AND COLUMN_NAME NOT IN ('updated_at');
```

```
notices  starts_at  default=CURRENT_TIMESTAMP  extra=DEFAULT_GENERATED on update CURRENT_TIMESTAMP
```

**Exactly one row, in the entire schema.** "We checked the four declarations we knew about" and
"there is one in the whole database" are different claims, and only the second lets this ticket call
its LIVE set complete.

**The `updated_at` carve-out turns out to be unnecessary, which strengthens the reading rather than
weakening it.** Re-run without it, the same query returns **1** — no `updated_at` column in this
schema carries `ON UPDATE CURRENT_TIMESTAMP` at all (Laravel's `timestamps()` emits nullable
columns, which the implicit rule does not touch). So the exclusion is hiding nothing.

The wider query — **any** server-clock default, not just `ON UPDATE` — returns four:

```
audit_logs           created_at   default=CURRENT_TIMESTAMP  extra=DEFAULT_GENERATED
authz_observations   occurred_at  default=CURRENT_TIMESTAMP  extra=DEFAULT_GENERATED
failed_jobs          failed_at    default=CURRENT_TIMESTAMP  extra=DEFAULT_GENERATED
notices              starts_at    default=CURRENT_TIMESTAMP  extra=DEFAULT_GENERATED on update CURRENT_TIMESTAMP
```

### LIVE — one

**`notices.starts_at`.** The only column in the schema whose value the server rewrites on **every
UPDATE that does not assign it**. Declared `$table->timestampTz('starts_at')`
(`2026_06_27_000001_create_notices_tables.php:32`) with no default; the clause is the server's.
Its live consequence: [`notice-end-destroys-starts-at.md`](notice-end-destroys-starts-at.md).

### The three explicit `->useCurrent()` columns are NOT the latent set

Stated separately because it is easy — and wrong — to fold them in as "the other three".

`jobs.failed_at`, `audit_logs.created_at` and `authz_observations.occurred_at` carry
`DEFAULT CURRENT_TIMESTAMP` **by design**, written by `->useCurrent()` in their migrations. They are
**not environment-dependent**: a freshly-migrated `portal_testing` on this host carries exactly the
same three, and no others. They carry **no `ON UPDATE` clause**, so nothing rewrites them. Their only
hazard is an INSERT that omits the column, and every writer supplies it (re-derived above). They are
visible to `scanUseCurrent()` and exempt with their reasons.

Nothing about them is latent. They are the *explicit* set.

### LATENT — a different set, three, and it is a SOURCE-side count

The genuinely latent members are declarations that are clean here and would materialise with
`DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` wherever the DDL is created under
`explicit_defaults_for_timestamp = OFF`: **`NOT NULL` `TIMESTAMP` columns declared with no default**.
That is a property of the migration, not of any schema, so it is counted from source
(`database/migrations/`, excluding `->nullable()`, `->useCurrent()` and the `timestamps()` helpers):

| Declaration | Migration | State |
|---|---|---|
| `$table->timestampTz('starts_at')` | `2026_06_27_000001_create_notices_tables.php:32` | **already materialised** — this is the LIVE row |
| `$table->timestamp('posted_at')->after('narration')` | `2026_08_09_120000_finance_capture_columns_s2_s3.php:74` | latent — `finance_ledger_transactions`, see below |
| `$table->timestampTz('expires_at')` | `2026_08_04_140000_create_notification_actions.php` | latent |
| `$table->timestampTz('registration_deadline')` | `2026_04_26_120713_create_curricula_table.php` | latent |

Four declarations of the shape; one has already gone live; **three remain latent**. They are latent
in the environment sense — clean in both databases on this machine, and they would not be clean
where the DDL was created under the other setting. `notices.starts_at` is the proof that the
distinction is real rather than theoretical.

*(This departs from the shape first proposed for this section, which had the three exempted
`->useCurrent()` columns as the latent set. They are not: they are explicit, identical across
environments, and carry no `ON UPDATE`. The latent set is derived from source, and it happens also
to number three — a coincidence worth not glossing over, because it is a different three.)*

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
