# TICKET — the read-layer rule has no gate: `sql-clock-lint`, written and split out unshipped

**Status:** OPEN. Preventive — **zero live violations in the four scanned directories, one in
`tests/`** (see "The count, and its scope" below) — so it can take its time.

**Where the work is:** branch `fix/sql-clock-lint`, commit `a08ddca`. Nothing below has to be
rebuilt from memory; it is all there, with three known defects that must be fixed before it ships.

> **`a08ddca` IS A REFERENCE, NOT A BASE TO BRANCH FROM.** It holds sixteen files against staging
> and only three of them are the lint. The other thirteen are a SUPERSEDED copy of the money fix
> that shipped separately on `fix/subledger-single-clock-frame`, and a merge or conflict resolved
> that branch's way would silently reinstate work that has since been corrected. Specifically stale
> on `a08ddca`:
>
> | Path | Why it is stale |
> |---|---|
> | `tests/Feature/Finance/SubledgerClockFrameTest.php` | the pre-fix arm — its ON DUPLICATE KEY block is **same-second-vacuous** and passes green while `updated_at = VALUES(updated_at)` is deleted. `git show fix/sql-clock-lint:… \| grep -c travel` → `0` |
> | `app/Finance/Services/SubledgerPoster.php` | superseded docblock: claims the arm proves more than it does, cites the lint as shipped, and calls this the "single writer" |
> | `docs/handoff/tickets/stored-epoch-offset.md` | superseded: describes the lint as shipped and gating the read-layer rule |
> | `tests/Feature/Quality/QualityStepCountTest.php` | superseded: its docblock predates the 14 → 15 → 14 round trip |
> | `docs/handoff/tickets/sql-clock-lint-blind-to-usecurrent.md` | deleted — folded into THIS file, so there is one place |
>
> **Re-cut from `staging` after the money fix merges, and carry across ONLY three things:**
> `bin/ci-sql-clock-lint.php`, `tests/Arch/SqlClockLintCoverageTest.php`, and the `bin/quality`
> step (plus its `.gitignore` fixture lines). Take nothing else from `a08ddca`.
>
> The remaining files on `a08ddca` — `docs/adr/0053-local-enforcement-floor.md`, `docs/adr/README.md`,
> `docs/handoff/opening-balance-import-spec.md`,
> `docs/handoff/tickets/lint-changed-cannot-see-uncommitted-work.md`,
> `tests/Feature/Finance/PaymentProvenanceTest.php`,
> `tests/Feature/Quality/PestNegatedExpectationMessagesTest.php` — are the 14 → 15 step-count sweep.
> Those edits will be needed again, but **re-derive them, do not cherry-pick them**: staging is back
> at 14, six of them were missed on the first pass, and the sweep has to be re-run for spelled-out
> numerals as well as digits. See "When this ships" below.

**Why it is not on `fix/subledger-single-clock-frame`:** the lint rode in with the money fix and
three review rounds produced eleven findings on the lint and none on the money fix. The third round
found it failing to catch the defect it was written for on the dominant raw-SQL surface. The money
fix was being held by a preventive gate guarding a violation class with no live members in the
directories the gate scans, so the two were split.

## The rule, and why it needs a gate

Stored timestamps are read through Laravel and **never compared to — or written from — MySQL's
clock inside raw SQL**. `docs/handoff/tickets/stored-epoch-offset.md` is the full condition; the
short form is that `config/database.php` pins no connection timezone, so every connection inherits
the database server's zone (production: `+05:30`, shared hosting, not ours to set), and the two
write paths fail in **opposite** directions:

- a PHP-written column stores early by the session offset and reads back **exact**;
- a `NOW()`-written column stores the true instant and reads back **ahead** by that offset.

That shipped once, in the single writer of the money projection: `finance_student_accounts.updated_at`
was written by MySQL's `NOW()` while the ledger row it projects was written by PHP's `now()`, and
`updated_at` is surfaced to staff as `last_activity`
(`app/Finance/Http/Controllers/FinanceAccountController.php:67`) — five and a half hours in the
future on production. Fixed in `SubledgerPoster`; nothing stops the next one.

**A rule with no gate is wallpaper.** The scanned surface is at zero violations today, which is
close to the only moment a rule like this is free to add — every hit it would produce would be new
work, not a baseline.

### The count, and its scope — read them together

| Scope | Live occurrences | Surveyed? |
|---|---|---|
| `app/`, `database/`, `routes/`, `bin/` | **0** (after this branch removed the two in `SubledgerPoster`) | yes, case-sensitively, at `c7c8279` |
| `tests/` | **1** — `tests/Feature/Finance/CurrencyShapeConstraintTest.php:54`, `VALUES (?, ?, ?, ?, ?, NOW(), NOW())` | **not surveyed when the lint was designed**; surveyed 2026-08-12 |

The one occurrence in `tests/` is a fixture that raw-inserts a `finance_student_accounts` row to trip
the `balance_currency` CHECK. No assertion in that file reads a timestamp, so the frame is
irrelevant to what it proves and it was deliberately left as it is; its comment, which used to claim
it mirrored `SubledgerPoster`, was corrected instead. The only other `NOW()` in `tests/` is
`SubledgerClockFrameTest`'s own `SELECT NOW()` probe, which reads the session clock **in order to
prove the two frames differ** — the opposite of a violation, and the reason the shipped lint
excluded `tests/` from its scanned directories.

**Whether `tests/` belongs in scope at all is a decision for this branch, not a conclusion handed to
it.** What is handed over is the fact: one hit, at that file and line, and a survey that never
looked there until now. A reader who takes a bare "zero" at face value will size the rollout wrong.

## Design history, and why each step was taken

Keep these measurements. They are what makes the next attempt cheaper than the last three.

1. **Case-sensitive token grep over whole files** (v1). Correct on this tree by luck: `app/` had
   exactly two hits and both were the defect. **Wrong as a permanent rule** — MySQL function names
   are case-insensitive, so `set updated_at = now()` is valid MySQL and invisible to it. A gate that
   claims more than it delivers.
2. **Case-insensitive over whole lines, requiring a SQL keyword on the line** (rejected, measured).
   `->update(['updated_at' => now()])` carries UPDATE; `->where('created_at', '>=', now())` carries
   WHERE. **24 false positives against 1 true one**, because Eloquent's builder methods are named
   after SQL keywords. A bare case-insensitive match without the keyword filter is worse still —
   PHP's `now()` helper is the CORRECT thing to use and appears 100+ times in `app/` alone.
3. **`token_get_all()`, searching string literals only** (what `a08ddca` ships). The distinction that
   holds is not case and not keywords: **SQL is a string, the helper is code.** PHP's `now()` is
   never inside a string literal; a raw SQL clock read always is. Comments fall out for free —
   the tokeniser returns them as comment tokens, so a docblock explaining the rule cannot trip it.
   Heredoc/nowdoc bodies and interpolated segments are covered; multi-line strings report the line
   of the match, not of the string.
4. **Token boundary** (`\bTOKEN\s*\(`, `\bTOKEN\b` for the forms MySQL accepts bare). Without it,
   `NOW` matches inside "Unknown" and "you are now acting as": **25 findings on the clean tree, 0
   with the boundary.**
5. **Two rule classes, two messages.** `clock-read` (NOW, SYSDATE, CURDATE, CURTIME, UTC_TIMESTAMP,
   UTC_DATE, UTC_TIME, CURRENT_TIMESTAMP, CURRENT_DATE, CURRENT_TIME, LOCALTIME, LOCALTIMESTAMP)
   reads the server clock; `frame-conversion` (UNIX_TIMESTAMP, FROM_UNIXTIME) passes a stored value
   through the session zone. **DATE_SUB, DATE_ADD, DATEDIFF, TIMESTAMPDIFF, ADDDATE and SUBDATE were
   REMOVED**: they compute from their arguments, so `TIMESTAMPDIFF(DAY, i.due_date, ?)` is entirely
   frame-safe, and the driver in `DATE_SUB(NOW(), …)` is caught by the clock-read group in the same
   expression anyway. A rule that refuses safe code teaches the next developer that the rule is
   arbitrary.
6. **`.sql` files scanned whole**, not by string literal — a `.sql` file is not PHP with SQL inside
   it. There are **zero `.sql` files in `app/`, `database/`, `routes/`, `bin/`** (the repo's only one
   is a runbook under `docs/`), so that path has never run against a real file.
7. **One named exception, keyed to a line rather than a file**: the one-time
   `INSERT … SELECT … NOW(), NOW()` backfill in
   `database/migrations/2026_07_22_190000_create_finance_student_accounts.php:83`. An applied
   migration is a dated act (ADR 0052) and the rows it wrote do not exist (0 on the dev copy,
   production pre-cutover).

## The three OPEN defects — all must be closed before this ships

### 1. Bare forms need a SQL keyword in the literal, and Laravel's raw fragments never carry one

The keyword IS the method name, so `whereRaw('due_date < CURRENT_DATE')` has no keyword inside the
string. **Eight tokens are affected**: `UTC_TIMESTAMP`, `UTC_DATE`, `UTC_TIME`, `CURRENT_TIMESTAMP`,
`CURRENT_DATE`, `CURRENT_TIME`, `LOCALTIME`, `LOCALTIMESTAMP` — and `LOCALTIMESTAMP` and
`CURRENT_TIMESTAMP` are exact synonyms of `NOW()`. The review's evidence, verbatim:

```
### ARM 5: DB::raw('LOCALTIMESTAMP') — exact synonym of NOW(), writing the projection column
    'updated_at' => DB::raw('LOCALTIMESTAMP'),                 exit=0   (not reported)

### ARM 4: whereRaw / orderByRaw / selectRaw against the server clock
        ->whereRaw('due_date < CURRENT_DATE')                  exit=0   (not reported)
        ->orderByRaw('CURRENT_TIMESTAMP - posted_at')          exit=0   (not reported)
        ->selectRaw('CURRENT_DATE AS today')                   exit=0   (not reported)

### ARM 4b: control — the same comparison written with NOW()
        ->whereRaw('due_date < NOW()')                         exit=1   ✗ [NOW — clock-read]
```

So the exact defect this whole line of work exists to prevent passes the gate when spelled
`LOCALTIMESTAMP`. `*Raw`/`DB::raw` is the dominant raw-SQL surface here (57 entry points in `app/`).

**Why the condition was added:** `\bcurrent_date\b` alone reports `'The current_date filter'` — a
plausible JSON key or prose string. **Why it is wrong anyway:** the 24-to-1 measurement that
justified a keyword requirement was taken over *lines*; inside a single string literal it is a
different test on different input, and it was never measured there.

**Closing it** (options, undecided): drop the requirement for the bare forms and measure the real
false-positive rate over string literals; or widen the discriminator to the enclosing call
(`whereRaw`/`selectRaw`/`orderByRaw`/`havingRaw`/`groupByRaw`/`DB::raw`); or find another
looks-like-SQL test. Whichever: **add coverage arms planting `whereRaw('due_date < CURRENT_DATE')`
and `DB::raw('LOCALTIMESTAMP')` and asserting exit 1** — the shapes that pass today. The current
coverage test has no arm asserting a bare form is CAUGHT; its only bare-form arm asserts one is not.

### 2. `findToken()` returns the first LISTED token, not the first OCCURRING one

It iterates the token map in declaration order and returns on the first match anywhere in the
haystack; `scanPhp` then advances past that match. A violation positioned **earlier** in the string
but **later** in the map is skipped. Three violations in one literal report one. The consequence
that matters is a whole-gate false green, against the real exempted migration:

```
'INSERT INTO finance_student_accounts
    (uuid, …, updated_at, synced_at)
 SELECT * FROM (SELECT UTC_TIMESTAMP) x WHERE 1=0 UNION ALL
 SELECT UUID(), …, NOW(), NOW()          <- the exempted line
   FROM finance_ledger_transactions
  GROUP BY school_id, student_id'

sql-clock-lint: OK — no SQL-side clock reads (1 named exception(s)).
exit=0

--- control: the same UTC_TIMESTAMP read moved AFTER the exempted line ---
  ✗ …create_finance_student_accounts.php:84  [UTC_TIMESTAMP — clock-read]
exit=1
```

The lint, the ticket and the report all state that "an exception is keyed to the ONE line it was
argued for, so a second clock read in the same file still fails". **That guarantee is false** as
written — whether the other read is seen depends on its position relative to the map's declaration
order. The bite-proof that was run planted another `NOW()` after the exempted line: the same token,
so the loop reached it for reasons unrelated to the guarantee being tested — a red that fails for
the right reason by accident.

**Closing it:** collect all matches and return the lowest offset; walk every match in `scanPhp`.
Then plant the arrangement above as a coverage arm — a violation *before* the exempted line,
asserting exit 1.

### 3. `->useCurrent()` is invisible (folded in from the separate ticket, now deleted)

`$table->timestamp('x')->useCurrent()` compiles to `DEFAULT CURRENT_TIMESTAMP` and
`->useCurrentOnUpdate()` to `ON UPDATE CURRENT_TIMESTAMP`. Both make the **database server's clock**
write the column, and neither puts a banned token in PHP source. Three live uses, re-derived
2026-08-11 (`grep -rn "useCurrent" database/ app/`):

```
database/migrations/0001_01_01_000002_create_jobs_table.php:44          $table->timestamp('failed_at')->useCurrent();
database/migrations/2026_04_26_122249_create_audit_logs_table.php:19    $table->timestampTz('created_at')->useCurrent();
database/migrations/2026_07_17_100000_create_authz_observations_table.php:34   $table->timestamp('occurred_at')->useCurrent();
```

None on a `finance_` table, and each column is written explicitly by PHP on the live path (e.g.
`app/Support/Authz.php` supplies `occurred_at`), so the DDL default does not fire in practice.

**Closing it:** scan `useCurrent` / `useCurrentOnUpdate` in **code** rather than in string literals,
scoped to `database/` — the matcher reads string literals precisely so PHP's `now()` does not flood
it, so this needs a second, narrower pass. The three sites above then need naming as exceptions with
their reason, or converting.

## What the lint could never see, gate or no gate

It matches **tokens**. A cross-frame comparison that names no clock function — a stored column
compared against a value the database computed some other way — is invisible to it. That half was
surveyed by hand across all 57 raw-SQL entry points in `app/` and found clean; it stays a reading
exercise.

## When this ships

`bin/quality` gains a step, and **the step count moves 14 → 15**. That is not a detail: it took
eleven prose sites moved by hand last time and six were missed on the first pass. Two things make it
cheaper now — `tests/Feature/Quality/QualityStepCountTest.php` (shipped with the money fix) ties the
printed total to the actual `step()` count, and the sweep must be run for **spelled-out numerals**
("fourteen", "thirteen") as well as digits, which is the shape that was missed.

## Related

- `docs/handoff/tickets/stored-epoch-offset.md` — the permanent condition, and the read-layer rule
  this gate would enforce.
- `app/Finance/Services/SubledgerPoster.php` — the defect that motivated it, fixed.
- `tests/Arch/BoundaryLintCoverageTest.php` — the shape the coverage test follows.
