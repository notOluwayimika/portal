# TICKET — stored timestamps are offset by the database server's timezone

**Status:** **STILL OPEN — but for a different reason than it was opened for.** ~~open, not fixed~~
The timezone question is **RULED, 2026-08-11: the server timezone is NOT being changed, it CANNOT be
(shared hosting), and connection pinning is refused.** What keeps the ticket open is the residual
that ruling leaves behind — the two-clock exposure in `SubledgerPoster`, whose remedy is a **code
fix in its own commit** and has not been made. Raised by `feat/school-day-helper`; the app-timezone
change that would have exposed it was withdrawn.

**Owner:** ~~project lead, with DevOps, in a maintenance window.~~ **No window — there is nothing to
schedule, and no window would help.** The open half is now a code change with a named shape (below),
which is the opposite of this line's original instruction; the ORIGINAL scope — the server zone — is
closed and must still not be anticipated in code.

## The ruling — 2026-08-11, project lead

**Production stays as it is. Do not plan a maintenance window; one is not coming, and one would not
help.** Two reasons, in this order.

**First, and it is the one that makes this permanent rather than deferred: the deployment is SHARED
HOSTING.** The global database server clock is physically restricted and is not ours to set. This
ticket was written as though the change were available and merely unscheduled. It is not available at
all, and every sentence below that reads like a scheduling problem should be read against that.

**Second, the reason this ticket had already derived and could not decide on its own: changing the
server zone reinterprets data already written.** Every historical row is read differently by the
offset — 273,564 rows across 44 non-empty tables on dev, more on production at 5.5 hours — and the
two bodies of history that would most need correcting, `activity_log` and the finance tables, are
**append-only by trigger** and cannot be corrected to match. So even with the access, the answer
would be the same. "Pin it going forward and correct nothing" was recorded below as the *likely*
resolution; the correct-nothing half is now the resolution, and the pin-it-going-forward half is
refused outright — see below.

**`App\Support\SchoolDay` absorbs the boundary permanently, not as an interim measure.** Business
dates the application derives come from the school's timezone rather than the server's, and that is
now the whole answer for the half that matters. The multi-school condition in "Related" is unchanged
and is still a separate ticket.

**What this ticket becomes: a record of a permanent condition, plus ONE open code fix.** Read what
follows as facts about the system rather than as debt — with one exception, which is the next section
and which is worse than this ticket previously said, and which is the reason the ticket is still
open.

Recorded so nobody re-opens the timezone change as an oversight. It is a decision, and on the shared
host it is not even ours to reverse.

## The residual the school-day helper does NOT cover — two clocks inside `SubledgerPoster`

`SchoolDay` fixes application-derived business dates. It does not reach the timestamps a raw SQL
statement writes, and **`SubledgerPoster` writes from two clocks in the same method call**:

| Written where | By | Column |
|---|---|---|
| `SubledgerPoster:70` | PHP `now()` (app.timezone = UTC) | `finance_ledger_transactions.posted_at` |
| `SubledgerPoster:117,120` | MySQL `NOW()` (session zone) inside the raw upsert | `finance_student_accounts.created_at` / `updated_at` |

The measurements already in this file cover the **PHP-written** path only. They said nothing about
the `NOW()`-written one, and the difference is not a matter of degree.

### Measured 2026-08-11, on the dev copy — a reading, not a derivation

Both write paths reproduced on a scratch `TEMPORARY TABLE` with `TIMESTAMP` columns — the same
`DATA_TYPE` `finance_student_accounts.updated_at` carries, verified against
`information_schema.COLUMNS`. No finance row was written. Dev session zone `SYSTEM` = WAT (+01:00),
`app.timezone` = UTC.

```
TRUE instant (PHP time())        : 1786483731
Laravel wrote the string         : 2026-08-11 21:28:51

--- PHP-written column (posted_at shape) ---
  stored epoch                   : 1786480131   drift vs true: -3600s
  rendered back                  : 2026-08-11 21:28:51
  Laravel reads that as          : 1786483731   display error:     0s

--- NOW()-written column (finance_student_accounts shape) ---
  stored epoch                   : 1786483731   drift vs true:     0s
  rendered back                  : 2026-08-11 22:28:51
  Laravel reads that as          : 1786487331   display error: +3600s
```

**The two paths fail in OPPOSITE directions, and this is the finding.** The PHP-written column is
stored an hour early and reads back correctly — that is the symmetry this ticket already documented.
The `NOW()`-written column is the mirror image: **the stored instant is exactly right, and the
value read back is wrong, an hour into the FUTURE.** `NOW()` is a properly typed value in the session
zone, so MySQL's conversion into the column is exact; it renders back into the session zone, and
Laravel then parses that string as UTC. Nothing is symmetric about that round trip.

**On production the reading error is +5.5 hours (+19,800s), and it is in front of the bursar.**
`finance_student_accounts.updated_at` is returned as `last_activity` on the accounts index
(`app/Finance/Http/Controllers/FinanceAccountController.php:67`). An account touched a moment ago
shows a last activity **five and a half hours in the future**. The production zone is
`+05:30`, confirmed by `TIMEDIFF` above; the +19,800s figure is **scaled from the dev reading, not
measured on production** — production has not been measured and this ticket does not claim it has.

**This corrects what "What IS broken" says below.** That section called these columns *"a
projection's own bookkeeping columns rather than a business date, which is why this is a ticket and
not a stop"*. The first half is true of the column's purpose and false of its reach: it is surfaced
on a staff screen. The conclusion — ticket, not stop — still holds, because nothing decides money on
it. What does not hold is the implication that no user ever sees it.

### Connection pinning — CONSIDERED AND REFUSED, recorded so it is not re-proposed

Adding `'timezone' => '+01:00'` to the `mysql` block in `config/database.php` is the standard
Laravel answer for shared hosting, and it has already been proposed once. **It is refused, and the
measurement above is why.**

**The session zone governs RENDERING as well as storage, so pinning re-renders every `TIMESTAMP` row
already written.** Read the PHP-written path in the measurement: stored −3600s, reads back 0s. Those
two errors are not independent — the second cancels the first, and **pinning breaks the
cancellation** while leaving the stored value exactly where it is. On production, where the session
zone is `+05:30`, pinning to `+01:00` makes every PHP-written historical timestamp render **4.5 hours
earlier than it does today** — payments, invoices, ledger transactions and the activity log. All of
them are append-only by trigger and refuse the UPDATE that would correct them, so the re-rendering is
not something a follow-up migration can tidy.

**And it does not close the gap it targets.** The `NOW()`-written error would shrink from the scaled
+19,800s to +3,600s — the offset between `+01:00` and `app.timezone` = UTC. Smaller, still wrong,
still in the future, and now paid for with a 4.5-hour shift in every other timestamp in the database.

**The remedy is the code fix below, not a connection setting.**

### The candidate fix, recorded and NOT done

Binding a PHP-supplied timestamp in place of both `NOW()` calls in the upsert would put every
timestamp in the schema in one frame and end the two-clock reading. It does **not** disturb what the
statement exists for: `balance_minor = balance_minor + VALUES(balance_minor)` stays exactly as it is,
so the skew-free atomic increment and the ON DUPLICATE KEY create-or-increment race resolution are
untouched. It is a two-parameter change to a string.

**The blast radius is two lines and two tables, and this is narrower than the ticket first implied.**
`NOW()` appears **exactly twice in the whole of `app/Finance`**, and both are the upsert:

```
$ grep -rn "NOW()" app/Finance/
app/Finance/Services/SubledgerPoster.php:117:             VALUES (?, ?, ?, ?, ?, NOW(), NOW())
app/Finance/Services/SubledgerPoster.php:120:                updated_at = NOW()',
```

The columns they write are on `finance_student_accounts`, which is the one finance table that could
accept a correction at all: *"DELIBERATELY NOT AppendOnly. Every other finance model is append-only
(immutable facts); this one is the single mutable projection — the balance moves as the ledger
grows"* (`app/Finance/Models/StudentAccount.php:17-19`). So the rows carrying this error are also the
only finance rows in the database an `UPDATE` could ever repair. That is a fact about scope, **not an
argument for repairing them** — a backfill is its own decision with its own reasoning, and it is not
proposed here.

**Whether it is worth touching the single writer of the money projection for a display field is the
project lead's call, not this ticket's.** Recorded so the option is known and so nobody does it
casually on the strength of the paragraph above. **It lands in its own commit, with its own tests and
its own watched red — never riding in on a documentation edit.** Until it does, this ticket stays
OPEN; `finance-mvp-cut-brief.md` §7 item 7 answers the timezone question and defers to this file for
the residual.

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

**Corrected 2026-08-11 — this paragraph was written before the `NOW()` path was measured, and it
understates the reach.** The two paths fail in *opposite* directions, and `updated_at` is surfaced to
staff as `last_activity` on the accounts index. See "The residual the school-day helper does NOT
cover" above for the reading. The ticket-not-a-stop conclusion is unchanged.

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
