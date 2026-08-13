# TICKET — ending a notice may destroy the start time a user typed

**Status:** OPEN, **being fixed on `fix/notices-starts-at-server-clock`** — see "Ownership" below.
**Real defect, no victims.**

**Two claims here, with different evidence. Do not let them collapse into one.**

| Claim | Evidence | Status |
|---|---|---|
| The column carries `ON UPDATE CURRENT_TIMESTAMP` on the production copy | `information_schema.COLUMNS`: `COLUMN_DEFAULT = CURRENT_TIMESTAMP`, `EXTRA = DEFAULT_GENERATED on update CURRENT_TIMESTAMP` | **OBSERVED**, 2026-08-13 |
| `end()` would therefore overwrite a user-entered `starts_at` | the column attribute plus documented MySQL `ON UPDATE` semantics | **DERIVED** — nobody has ended a notice and watched the value move |

The first was derived when this ticket was written and has since been read directly off the schema.
The second has not, and until someone exercises the route against a schema carrying the attribute,
it stays derived. That is the half the fix branch's test arm will finally close.

**Severity: the project lead's call, and it is scheduling rather than triage.** This is a **live
route** (`POST /notices/{notice:uuid}/end`) acting on **data a user typed by hand** with no other
copy in the schema, and the suspected loss is **silent and unrecoverable**. It is not money,
isolation or audit, so it does not block anything; it also does not get quieter by waiting.

**Not in scope for `fix/sql-clock-lint-v2`**, where it was found. It is outside Finance and does not
belong in a lint commit.

## Damage assessment — 2026-08-13. Real defect, no victims.

Read off the production copy, under the privacy rule (counts and deltas, no content):

```
notices rows = 3
  notice#1  school#1  edited=no    starts_at − created_at = -130,185 s
  notice#2  school#1  edited=no    starts_at − created_at =      -171 s
  notice#3  school#1  edited=yes   starts_at − created_at = -911,310 s
                                   starts_at − updated_at = -912,844 s
```

**One notice has ever been edited, and its `starts_at` is untouched.** It sits 911,310 s (~10.5
days) *before* its own `created_at` — a human back-date, exactly what an admin scheduling a notice
would produce. Had `ON UPDATE` fired at that edit, the column would have been rewritten from the
server's clock at that moment and would sit at roughly `updated_at + 19,800` (the session offset),
not 912,844 s behind it. It does not. **The clause has never fired on any row that exists.**

Why not, and it is worth knowing rather than assuming luck: `NoticeController::update()`
(`:170-175`) **does** send `starts_at`, so a normal edit assigns the column and `ON UPDATE` does not
apply. Only `end()` omits it. So the defect is real and its one trigger has not yet been pulled on a
row anybody would miss.

**What this does not establish:** whether `end()` has ever been called at all. The data shows no row
carrying the signature; it cannot show that the route was never exercised on a row since deleted.

## The mechanism — observed column, derived consequence

The column attribute below is **read off the schema**. The step from it to "`end()` overwrites the
value" is the derived half, and is what the fix branch's arm will settle. The attribute is also the
sole row returned by the schema-wide `ON UPDATE` query in
[`server-settings-the-code-cannot-see.md`](server-settings-the-code-cannot-see.md) — there is no
second column like it anywhere in the database.

**The write.** `app/Http/Controllers/NoticeController.php:206-209`:

```php
public function end(Notice $notice)
{
    $notice->update(['ends_at' => now()]);
```

The update assigns `ends_at`, and Eloquent adds `updated_at`. It does **not** assign `starts_at`.
Route: `routes/endpoints/notice.php:14`,
`Route::post('/{notice:uuid}/end', [NoticeController::class, 'end']);`.

**The column — OBSERVED.** Declared at
`database/migrations/2026_06_27_000001_create_notices_tables.php:32` as a bare
`$table->timestampTz('starts_at');` — `NOT NULL`, no default. On the production copy
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

## Ownership — this ticket is NOT unowned

**`fix/notices-starts-at-server-clock`** carries the fix: the migration, the code change, and a test
arm that **imposes the column attribute before exercising the route** — which is what will finally
observe the derived half of the claim above, since a freshly-migrated database does not carry the
clause and the bug cannot occur there.

**This ticket closes when that branch merges.**

> **What could be verified about that branch from here, 2026-08-13, and what could not.** The branch
> **exists locally**. `git log origin/staging..fix/notices-starts-at-server-clock` returned
> **nothing** and its working tree was clean, so at the moment of writing it carries **no commits
> over `staging` on this machine** — the work is either uncommitted, or in progress elsewhere.
> Recorded rather than smoothed over: this line names an owner, and a reader who takes it as "the
> fix is written and waiting" would be reading more than the repository supports. Re-check the
> branch before assuming the arm exists.

## The two candidate fixes

Kept because the choice is the substance of the fix, and a reviewer of that branch should see what
was weighed.

1. **Assign `starts_at` explicitly in `end()`.** One line, immediate, and leaves the DDL hazard in
   place for the next writer of that table.
2. **Take the `ON UPDATE` clause off the column with a new migration.** Durable, removes the class
   from this table for every future write path, and is what the sql-clock lint's own remediation
   text prescribes for a `ddl-default` finding. Costs a migration that must be written to be
   idempotent across environments, since the clause is present on one and absent on the other.

(2) is the better shape, and is what the branch name implies was chosen.

**The reproduction is no longer a blocking prerequisite** — the column attribute is observed, so the
cause is established rather than guessed. What remains unobserved is the route's effect, and the fix
branch's arm covers exactly that: impose the attribute, call `end()`, watch `starts_at` move. **If
it does not move, that is the finding**, and this ticket closes with the reason recorded instead of
with a fix.

## Related

- [`server-settings-the-code-cannot-see.md`](server-settings-the-code-cannot-see.md) — the class.
  This is the first live consequence found of member 2.
- [`stored-epoch-offset.md`](stored-epoch-offset.md) — the frame half, and why the connection cannot
  simply be pinned.
- `docs/handoff/reports/fix-sql-clock-lint-v2.md` — where this was found, by the cold review of a
  change that had nothing to do with notices.
