# TICKET — ending a notice may destroy the start time a user typed

**Status:** OPEN. **DERIVED, NOT OBSERVED** — read the next section before scheduling any work on
it.

**Severity: the project lead's call, and it is scheduling rather than triage.** This is a **live
route** (`POST /notices/{notice:uuid}/end`) acting on **data a user typed by hand** with no other
copy in the schema, and the suspected loss is **silent and unrecoverable**. That combination is
what makes it worth a ticket rather than a note. It is not money, isolation or audit, so it does not
block anything; it also does not get quieter by waiting.

**Not in scope for `fix/sql-clock-lint-v2`**, where it was found. It is outside Finance and does not
belong in a lint commit.

## THE FIRST TASK IS TO REPRODUCE IT, NOT TO FIX IT

Everything below is derived from **the column definition plus documented MySQL `ON UPDATE`
semantics**. Nobody has watched the rewrite happen. A fix applied to an unproven cause is a guess
with a commit message, and the two obvious fixes differ depending on which cause is real.

So the first commit on whatever branch takes this is a reproduction against the **production copy's
schema** — not production, and not a freshly-migrated `portal_testing`, because the two differ on
exactly the point at issue (see below). Suggested shape: insert a `notices` row with a known
`starts_at`, run an UPDATE that assigns only `ends_at` and `updated_at`, and read `starts_at` back.

**If it does not reproduce, that is the finding**, and this ticket closes with the reason recorded.

## The derivation

**The write.** `app/Http/Controllers/NoticeController.php:206-209`:

```php
public function end(Notice $notice)
{
    $notice->update(['ends_at' => now()]);
```

The update assigns `ends_at`, and Eloquent adds `updated_at`. It does **not** assign `starts_at`.
Route: `routes/endpoints/notice.php:14`,
`Route::post('/{notice:uuid}/end', [NoticeController::class, 'end']);`.

**The column.** Declared at `database/migrations/2026_06_27_000001_create_notices_tables.php:32` as
a bare `$table->timestampTz('starts_at');` — `NOT NULL`, no default. On the production copy
`portaa10_portal` it has materialised as:

```
notices.starts_at   timestamp  NOT NULL
                    default = 'CURRENT_TIMESTAMP'
                    extra   = DEFAULT_GENERATED on update CURRENT_TIMESTAMP
```

**Why the column looks like that**, and why it is not the same everywhere: with
`explicit_defaults_for_timestamp` **OFF**, MySQL adds
`DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` to a `NOT NULL` `TIMESTAMP` declared without
one. On a freshly-migrated `portal_testing` **on this machine**, where the setting is `ON`, the same
migration produces `default=NULL, extra=''` — no `ON UPDATE` clause, and this bug cannot occur.
That divergence is the reason the reproduction must be run against the copy.
Full class: [`server-settings-the-code-cannot-see.md`](server-settings-the-code-cannot-see.md).

**The two consequences, and the first is the serious one.**

1. **Data loss.** `starts_at` is the scheduled start time an admin entered. `ON UPDATE
   CURRENT_TIMESTAMP` rewrites it from the server's clock on any UPDATE that does not assign it.
   The schema holds no second copy, so the original is gone. Silent — the endpoint returns 200 with
   a `NoticeResource`.
2. **Frame.** `ends_at` is written by PHP in `app.timezone` (`config/app.php:68`, UTC) while a
   server-written `starts_at` lands in the session zone. On production (`+05:30`) the ended notice's
   `starts_at` would read **later than its own `ends_at`** — the same two-frames-one-table defect as
   [`stored-epoch-offset.md`](stored-epoch-offset.md), arriving through DDL instead of through raw
   SQL.

**Reach.** `NoticeController::update()` (`:170-175`) does send `starts_at`, so it is unaffected.
`end()` is the only reachable path that updates the row without it. `notices` holds 3 rows on the
copy. Both `Notice` model scopes that filter on the column —
`app/Models/Notice.php:70` and `:86` — read a value this path can corrupt, and the table's only
composite index is `(school_id, starts_at, ends_at)`.

## The two candidate fixes — pick after reproducing

1. **Assign `starts_at` explicitly in `end()`.** One line, immediate, and leaves the DDL hazard in
   place for the next writer of that table.
2. **Take the `ON UPDATE` clause off the column with a new migration.** Durable, removes the class
   from this table for every future write path, and is what the sql-clock lint's own remediation
   text prescribes for a `ddl-default` finding. Costs a migration that must be written to be
   idempotent across environments, since the clause is present on one and absent on the other.

(2) is the better shape. Do not do either before (0), the reproduction.

## Related

- [`server-settings-the-code-cannot-see.md`](server-settings-the-code-cannot-see.md) — the class.
  This is the first live consequence found of member 2.
- [`stored-epoch-offset.md`](stored-epoch-offset.md) — the frame half, and why the connection cannot
  simply be pinned.
- `docs/handoff/reports/fix-sql-clock-lint-v2.md` — where this was found, by the cold review of a
  change that had nothing to do with notices.
