# TICKET — stored timestamps are offset by the database server's timezone

**Status:** open, not fixed. Raised by `feat/school-day-helper`; the app-timezone change that would
have exposed it was withdrawn.

**Owner:** project lead, with DevOps, in a maintenance window. Not a code change, and it must not be
anticipated in code.

## The defect

`config/database.php` sets **no** connection timezone, so every connection inherits the database
server's `SYSTEM` zone. Nothing in the repository pins it, and the two environments already differ:

| Environment | MySQL session zone | Stored epochs are |
|---|---|---|
| dev copy | `SYSTEM` = **WAT (+01:00)** | **1 hour early** |
| production | **+05:30** (IST/Asia-Kolkata, confirmed by `TIMEDIFF`) | **5.5 hours early** |

Laravel writes a UTC wall-clock string; MySQL parses it as a wall clock in the *session* zone and
converts to UTC for storage, so the stored instant is early by the offset. Measured on dev:

```
PHP true instant (epoch)   : 1786301501
Laravel writes the string  : 2026-08-09 18:51:41   (app.timezone=UTC)
MySQL stores that as epoch : 1786297901            (parsed in session tz = WAT)
DRIFT stored vs reality    : -3600 seconds
ROUND TRIP error today     : 0 seconds
```

## What is NOT broken, corrected from the brief

The brief anticipated that `whereDate()` and raw `DATE()` would file rows under the wrong day, and
that **is not what happens**. The conversion is symmetric: MySQL renders the stored value back into
the same session zone it parsed it in, returning the identical string Laravel wrote. Measured:

```
row#179516
  MySQL DATE(created_at)      : 2026-08-09
  Laravel-side date of the row: 2026-08-09
  AGREE on the stored column? : YES
```

So SQL-side date functions **on stored columns** agree with Laravel-side comparisons. The bug hunt
the brief asked for was run and its result is that those call sites are safe:

| Pattern | Sites | Verdict |
|---|---|---|
| `whereDate(` | 4 — `GuardianController:550,551`, `GuardianService:74,75` | safe (symmetric render) |
| raw `DATE(` | 4 — `ModuleClassificationService:158,159,268,271` | safe (symmetric render) |
| `whereTime(` `whereDay(` `whereMonth(` `whereYear(` `DATE_FORMAT(` | **0** | — |
| date-based `groupBy` | 0 beyond the `groupByRaw('DATE(...)')` counted above | — |

## What IS broken

The asymmetry appears wherever a SQL-side **current time** meets an application-side one, because
`NOW()` is in the session zone and PHP's `now()` is in the app zone:

```
MySQL NOW() : 2026-08-09 20:46:52
PHP   now() : 2026-08-09 19:46:52     ← one hour apart on dev, 5.5 on production
```

| Pattern | Sites | Note |
|---|---|---|
| `NOW()` in raw SQL | **2** — `SubledgerPoster:117,120` | `created_at`/`updated_at` on the `finance_student_accounts` upsert |
| `CURDATE()` `CURRENT_TIMESTAMP` `UNIX_TIMESTAMP` `UTC_TIMESTAMP` | **0** | — |

`SubledgerPoster`'s two are the live exposure: those two columns are written by MySQL's clock while
every other timestamp in the schema is written by PHP's, so the account projection's `created_at` is
offset from the ledger rows it was derived from — by one hour on dev and 5.5 on production. It is a
projection's own bookkeeping columns rather than a business date, which is why this is a ticket and
not a stop.

## Why correcting the data is probably the wrong answer

`activity_log` is **immutable by design** — 177,164 rows behind triggers that refuse UPDATE. Any
correction of historical timestamps therefore *cannot* include the audit trail, which would leave the
audit log and the records it describes offset from each other by a different amount than they are
today. Two inconsistent histories are worse than one consistently offset one.

The same holds for the finance tables, which carry the same append-only triggers.

So the likely resolution is: **pin the session timezone going forward, document the historical
offset, and correct nothing.** Recorded here so that decision is made deliberately rather than
discovered.

## The one thing that must not happen by accident

Aligning `app.timezone` with the connection timezone — in either direction, by changing either one —
reinterprets every stored row by the offset. On dev that is 273,564 rows across 44 non-empty tables;
on production it is more, by 5.5 hours. Whoever changes the MySQL server zone must know that Laravel
will then read every historical row differently, and that `config/app.php` must move in the same
change or the two will disagree in a new way.

## Related

- `App\Support\SchoolDay` fixes the half that does not need a maintenance window: business dates the
  *application* derives now come from the school's timezone rather than the server's.
- The multi-school condition is a separate ticket: `SchoolDay::TIMEZONE` is a constant, correct while
  every school is in Nigeria, and must become a per-school column with this value as its default the
  day a school outside West Africa is onboarded.
