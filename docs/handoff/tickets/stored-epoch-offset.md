# TICKET — stored timestamps are offset by the database server's timezone

**Status:** **OPEN AS A RECORD OF A PERMANENT CONDITION — no work item remains.** ~~open, not
fixed~~ ~~STILL OPEN — but for a different reason than it was opened for.~~ Two things have to be
read separately here, and the second is the one that gets misread:

- **The two-clock residual in `SubledgerPoster` is CLOSED**, 2026-08-11, by
  `fix/subledger-single-clock-frame` — the instant is captured once and bound into both writes.
  Armed by `tests/Feature/Finance/SubledgerClockFrameTest.php` ("lands the ledger row and the
  account projection in ONE CLOCK FRAME, under production's session zone"), which pins the
  session zone to production's `+05:30` rather than depending on the machine's. The arm proves the
  FRAME, not single-capture — single-capture is structural in `post()`. **It arms this method only.**
  A gate for the wider rule was built alongside the fix and then SPLIT OUT unshipped; it lives on
  `fix/sql-clock-lint` and is specified in `docs/handoff/tickets/sql-clock-lint.md`.
  **No open backfill and no open count**: production was measured on 2026-08-12 and holds zero
  finance rows (below).
- **The STORED-EPOCH OFFSET ITSELF IS NOT CLOSED and cannot be.** PHP-written columns still store
  early by the session offset. They round-trip correctly, which is why nothing reads wrong today,
  and that asymmetry is now the permanent architectural reality the project lead ruled — not debt,
  not a deferred fix. Everything below documents it so it is not rediscovered as a bug.

The timezone question is **RULED, 2026-08-11: the server timezone is NOT being changed, it CANNOT be
(shared hosting), and connection pinning is refused.** Raised by `feat/school-day-helper`; the
app-timezone change that would have exposed it was withdrawn.

**THE READ-LAYER RULE, which is what replaced the fix that is not available.** Stored timestamps are
read through Laravel and **never compared to — or written from — MySQL's clock inside raw SQL**.
The round trip is symmetric only when both ends are the application's clock; the moment a SQL-side
clock value enters, two frames meet in one table and the difference is silent.

**The tree honours the rule today at ZERO violations** — verified by the survey below: no MySQL
clock function anywhere in `app/`, and all 57 raw-SQL entry points read by hand for a cross-frame
comparison that names no clock function, with none found. The only occurrence in the scanned
surface is the one-time backfill inside an already-applied migration
(`2026_07_22_190000_create_finance_student_accounts.php:83`), whose rows do not exist.

**NOTHING ENFORCES IT YET.** This is a rule on trust, not a gate. A lint for it was written on
`fix/subledger-single-clock-frame`, reviewed three times, and split out unshipped with three open
defects — `docs/handoff/tickets/sql-clock-lint.md` carries the design, the measurements and the
defects. Until that lands, a clock read added to raw SQL anywhere in this repository fails no
build.

**Owner:** ~~project lead, with DevOps, in a maintenance window.~~ **Nobody — there is nothing to
schedule, nothing to build, and no window would help.** The ORIGINAL scope — the server zone — is
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

## The residual the school-day helper does NOT cover — two clocks inside `SubledgerPoster` — CLOSED 2026-08-11

**CLOSED by `fix/subledger-single-clock-frame`.** `post()` now captures the instant ONCE, before the
ledger write, and binds it into both — `posted_at` on the ledger row and `created_at`/`updated_at`
on the account projection, formatted the way Eloquent formats `posted_at` so the columns are
byte-identical rather than merely close. The shipped `ON DUPLICATE KEY` branch is:

```sql
   balance_minor = balance_minor + VALUES(balance_minor),
   updated_at    = COALESCE(GREATEST(updated_at, VALUES(updated_at)), VALUES(updated_at))
```

`balance_minor` is untouched, so the atomic increment and its create-or-increment race resolution
are exactly as they were. `updated_at` is not: the bound stamp is captured at the top of `post()`,
BEFORE the row lock, where `NOW()` was evaluated after it — so `GREATEST` is what keeps two racing
posts from moving it backwards, and the `COALESCE` keeps a NULL column (both are nullable) from
latching at NULL forever. Everything below in this section is the measurement that produced the fix,
kept as the reason it exists.

The arm is `tests/Feature/Finance/SubledgerClockFrameTest.php`. It **sets** the session zone to
`+05:30` rather than depending on the machine's, because on a UTC-session machine the two clocks
agree and the defect cannot appear — the obvious arm would have been green for the wrong reason. It
asserts the frames genuinely differ on the connection first, then that both columns carry the same
string, on the INSERT branch and on the `ON DUPLICATE KEY` branch. Watched red with `NOW()` restored:
`Failed asserting that 19800 is identical to 0.`

`SchoolDay` fixes application-derived business dates. It does not reach the timestamps a raw SQL
statement writes, and **`SubledgerPoster` wrote from two clocks in the same method call**:

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
`+05:30`, re-confirmed by a fresh `TIMEDIFF` reading on 2026-08-12 (below); the +19,800s figure is
**scaled from the dev reading, not measured on production** — the ZONE has been measured, the
display error itself has not, and this ticket does not claim otherwise.

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

### The fix — DONE, 2026-08-11 (recorded here as it was proposed, then as it landed)

Binding a PHP-supplied timestamp in place of both `NOW()` calls in the upsert puts every
timestamp in the schema in one frame and ends the two-clock reading. It does **not** disturb what the
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

**Whether it was worth touching the single writer of the money projection for a display field was the
project lead's call, and it was made.** It landed in its own commit, with its own arm and its own
watched red — not riding in on a documentation edit. `finance-mvp-cut-brief.md` §7 item 7 answers the
timezone question and defers to this file for the residual, which is now closed.

**THE BACKFILL QUESTION IS CLOSED TOO, and by counting rather than by argument.** The rows carrying
the old two-frame stamp are the rows `finance_student_accounts` held before the fix. On the dev copy
(`portaa10_portal`, 2026-08-11) that table holds **0 rows**, and `finance_ledger_transactions` holds
**0 rows** — nothing has been posted through the projection outside tests. **Production was measured
on 2026-08-12 and holds 0 and 0 as well** (readings below); it is pre-cutover, and that is now a
count rather than a position. There is nothing to repair, no remediation is proposed, and this line
exists so the question is not re-opened as an oversight for FUTURE rows: every one of them is
written in one frame by construction.

**THE SHAPE OF THE EXPOSURE, recorded because it was real before it was measured.** The "the next
post re-stamps it" argument is false under the shipped SQL: it was true of a plain assignment and is
not true of `GREATEST`. The arithmetic is this file's own measurement — a `NOW()`-written column
stores the TRUE instant, a PHP-bound column stores `true − offset` — so a legacy row's stored epoch
is the LARGER of the two and `GREATEST` keeps it. Such a row would go on reading up to the session
offset into the future until that much real time had passed: **the heal delayed, not absent**, on
exactly the rows the fix was meant to repair. `balance_minor` unaffected throughout.

### Measured on PRODUCTION, 2026-08-12, by the project lead

Readings, not assumptions. Structure and counts only; no row contents were read.

```
finance_student_accounts             0 rows
finance_ledger_transactions          0 rows
@@session.time_zone                  SYSTEM
@@global.time_zone                   SYSTEM
TIMEDIFF(NOW(), UTC_TIMESTAMP())     05:30:00
```

Three conclusions, and no more than these three.

**1. THE BACKFILL QUESTION IS CLOSED — by a count, not by inheritance.** Zero accounts and zero
ledger rows means there is no row in production carrying a MySQL-framed timestamp, so there is
nothing for `GREATEST` to delay the healing of. The exposure above was **real in shape and empty in
fact**. No remediation is owed and none is proposed; this is settled by measurement rather than by
the pre-cutover position it used to rest on.

**2. THE DELAYED-HEAL CASE IS UNREACHABLE GOING FORWARD, and this is why rather than merely that.**
Once this merges, `SubledgerPoster` binds a PHP instant on every path it has — so no `NOW()`-framed
row is ever written to `finance_student_accounts` again, so no row can ever hold a stored epoch that
dominates a later bind. The single theoretical producer left is the backfill inside
`2026_07_22_190000_create_finance_student_accounts.php`, which replays only on a fresh database and
selects from an empty ledger, therefore writing nothing. Noted, and left at that — nothing is built
for it.

**3. `+05:30` IS CONFIRMED CURRENT as of 2026-08-12**, not carried forward. The earlier figure was a
single reading taken some time ago; this one is fresh, on the same day as the commit that closes the
residual, and it is what the arm's session-zone pin and every offset in this file rest on. The date
is recorded beside it so a later reader can judge how stale it has become — the zone is not ours to
set (shared hosting) but it is also not ours to assume unchanged.

**And what does NOT keep it closed:** nothing. There is no gate. The one occurrence left in the
scanned surface is the one-time `INSERT … SELECT … NOW(), NOW()` backfill inside
`2026_07_22_190000_create_finance_student_accounts.php`, an already-applied migration (a dated act,
ADR 0052) whose rows the count above shows do not exist — it stays as it is, and it is the exception
any future lint has to argue rather than a violation to repair.

Precisely, because it is easy to overstate in either direction. A backfilled row's **`created_at`**
is never re-stamped at all — the `ON DUPLICATE KEY` branch sets `balance_minor` and `updated_at`
only — so it keeps the MySQL frame permanently; no reader exists for it
(`FinanceAccountController:67` surfaces `updated_at`). And its **`updated_at`** is re-stamped only
once real time overtakes the legacy stored epoch, per the re-opened item above. What carries this
exemption is the row count, not the re-stamp.

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
| `NOW()` in raw SQL | ~~**2** — `SubledgerPoster:117,120`~~ **0 in `app/`** | removed 2026-08-11; `created_at`/`updated_at` on the `finance_student_accounts` upsert are now bound |
| `CURDATE()` `CURRENT_TIMESTAMP` `UNIX_TIMESTAMP` `UTC_TIMESTAMP` | **0** | — |

**Re-surveyed 2026-08-11, case-SENSITIVELY, across `app/`, `database/`, `routes/` and `bin/`** — the
original survey looked only at `app/` and missed one. `NOW()`, `CURDATE()`, `CURRENT_DATE`,
`CURRENT_TIMESTAMP`, `UNIX_TIMESTAMP`, `DATE_SUB`, `DATE_ADD`, `DATEDIFF`, `TIMESTAMPDIFF`: **one
live occurrence remains**, the one-time backfill at
`2026_07_22_190000_create_finance_student_accounts.php:83`, which is the named exception in the lint.
(The survey was run case-SENSITIVELY on purpose: a case-insensitive `NOW\(\)` also matches PHP's
`now()` helper, which is the correct thing to use and appears over a hundred times in `app/` alone.
MySQL function names ARE case-insensitive, so a case-sensitive survey is sound for a one-off reading
by a human and is NOT sound as a permanent rule — that difference, and what it costs to close,
is the subject of `docs/handoff/tickets/sql-clock-lint.md`.)

Separately, all 57 raw-SQL entry points in `app/`
(`whereRaw`/`selectRaw`/`havingRaw`/`orderByRaw`/`groupByRaw`/`DB::raw`/`DB::statement`/`DB::select`/
`DB::insert`/`DB::update`) were read for a time comparison that uses no clock-function name: **none
found.** Six touch a time column at all, and all six are `DATE()` grouping or an `IS NULL` ordering
on a stored column — no SQL-side current time anywhere.

`SubledgerPoster`'s two WERE the live exposure: those two columns were written by MySQL's clock while
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
