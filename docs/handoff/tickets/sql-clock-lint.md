# TICKET — the read-layer rule has no gate: `sql-clock-lint`, written and split out unshipped

**Status: CLOSED** on `fix/sql-clock-lint-v2`, re-cut from `staging` @ `4928064`. All three defects
below are fixed and bite-proven, `tests/` is decided (OUT, with the measurement), and `bin/quality`
is 15 steps. What each fix cost and what it bought is in
`docs/handoff/reports/fix-sql-clock-lint-v2.md`; the resolutions are appended to each defect section
here so this file stays the one place. **Everything below the resolutions is the original text,
preserved** — the measurements are what made the fourth attempt cheaper than the first three.

**Where the work WAS:** branch `fix/sql-clock-lint`, commit `a08ddca` — reference only, superseded.
Nothing was cherry-picked from it beyond the three lint artifacts; see the box below, which was the
instruction and was followed.

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

> **RESOLVED — `tests/` stays OUT, and the one-hit figure above understated it by twenty-six.**
>
> **What the number counts, and when:** findings the SHIPPED matcher reports with
> `SCANNED_DIRS = ['tests']`, over `tests/` as it stands at the commit that ships this lint — which
> includes this lint's own four new coverage arms. On that basis: **27 hits, none of them the
> defect.**
>
> | Where | Hits | What they are |
> |---|---|---|
> | `tests/Arch/SqlClockLintCoverageTest.php` | 21 | this lint's own planted fixtures and the strings it asserts on |
> | `tests/Feature/Support/SchoolDayTest.php` | 3 | an assertion MESSAGE quoting `now()` as prose, and PHP source held in a string so an arch test can scan it |
> | `tests/Feature/Finance/CurrencyShapeConstraintTest.php:54` | 2 | the known fixture insert — two `NOW()` on one line; no assertion reads a timestamp back |
> | `tests/Feature/Finance/SubledgerClockFrameTest.php:69` | 1 | the `SELECT NOW()` probe that exists TO PROVE the two frames differ |
>
> **A first pass reported 16 and that figure was wrong** — taken before this branch's own coverage
> arms landed, then written into a permanent docblock. Corrected in the lint, here, and in the
> report. The extra 11 are all in the coverage test's new fixture strings, so the categories and the
> decision are unchanged; only the number moved. Recorded rather than quietly overwritten, because
> it is the same carried-number failure this ticket's own §"The count, and its scope" corrects.
>
> The reason is structural rather than a count: the discriminator this lint rests on — **SQL is a
> STRING, the helper is CODE** — is precisely what a test suite breaks. Tests put prose and PHP
> source inside string literals as a matter of course, so under `tests/` the matcher's precision
> collapses to a substring grep. Scanning it would buy a day-one baseline of permanent exemptions,
> including exemptions for the lint's own coverage test, and being free of a baseline is the whole
> reason this rule was worth adding at zero.
>
> `CurrencyShapeConstraintTest:54` is left exactly as it was, for the reason already recorded above.

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

## The three defects — ALL THREE CLOSED on `fix/sql-clock-lint-v2`

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

> **CLOSED — the requirement is dropped outright, on the measurement the ticket asked for.** The
> first option, not the enclosing-call one. Re-measured where the test actually applies — over
> string literals only, with the requirement removed, across `app/`, `database/`, `routes/` and
> `bin/` — the bare forms produce **0 hits on every file in scope**. The 24-to-1 figure that once
> justified the requirement was taken over LINES, where `->update([...])` and `->where(...)` hand
> over UPDATE and WHERE for free; inside a single literal it bought nothing and hid the dominant
> case. The enclosing-call discriminator was considered and rejected as more code with a real hole
> (heredoc SQL assigned to a variable, then passed to `DB::statement($sql)`).
>
> **The residual is priced and pinned, not waved away.** A literal carrying `current_date` or
> `current_time` for a non-SQL reason — prose, or an array key `'current_time' => now()` — is now
> reported, and no lexical test separates `DB::raw('LOCALTIMESTAMP')` from `'current_time'` when
> both are the whole literal. The coverage test now has an arm ASSERTING that false positive, so it
> is a decision on the record rather than a later surprise. It is the safe direction: a false
> positive is loud and lands on the author's own new line; the miss it replaces was silent.
>
> Coverage arms added as instructed — all four shapes from the evidence block, plus the residual.

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

> **CLOSED exactly as prescribed.** `findToken()` now scans the whole map and returns the lowest
> offset; `scanPhp()` already walked every match, and with the earliest-first return the walk is
> correct. Bite-proven against the REAL exempted migration, not a synthetic: with
> `SELECT * FROM (SELECT UTC_TIMESTAMP) x WHERE 1=0 UNION ALL` inserted above the exempted line,
> the pre-fix lint printed `OK — no SQL-side clock reads` and `exit=0`; the fixed lint reports
> `…create_finance_student_accounts.php:83 [UTC_TIMESTAMP — clock-read]` and `exit=1`, **with the
> exempted line still exempt** — one finding, not two. Migration restored, gate back to green.
>
> The coverage arm does not depend on EXCEPTIONS (a planted fixture cannot be in the map), so it
> pins the mechanism instead: one literal, `UTC_TIMESTAMP` on line 13 and `NOW()` on line 14, both
> lines asserted. The pre-fix matcher reports `:14` twice and never `:13` — that is the arm's red.

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

> **CLOSED, not deferred** — `scanUseCurrent()` is that second pass. Two deviations from the sketch
> above, both deliberate:
>
> 1. **Scoped to all four scanned directories, not just `database/`.** `useCurrent` appears
>    nowhere outside those three migrations (re-derived over `app/ database/ routes/ bin/ tests/
>    config/`), so the wider scope costs nothing and does not depend on Blueprints only ever living
>    under `database/`.
> 2. **It matches on `->` followed by the name, and compares case-insensitively.** Matching the
>    method CALL rather than the word keeps the docblock explaining this rule from reporting itself;
>    matching case-insensitively is because PHP method names are case-insensitive at the call site,
>    so `->UseCurrent()` runs and a case-sensitive gate would be evadable by typing.
>
> The three sites are named as exceptions, keyed to the line, each with its writer **re-derived
> rather than assumed**: `DatabaseFailedJobProvider.php:57` (`Date::now()`) for `jobs.failed_at`;
> Eloquent's own timestamp handling for `audit_logs.created_at` (`App\Models\AuditLog` sets
> `UPDATED_AT = null` only, so `created_at` is still supplied on create); `app/Support/Authz.php:78`
> for `authz_observations.occurred_at`. The three are exempt as history rather than as an argument
> that the pattern is acceptable.
>
> **A first version of this resolution said "the tree is at zero in BEHAVIOUR as well as in tokens".
> That was false and is corrected** — a fourth column, `notices.starts_at`, carries
> `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` on the production copy from a bare
> `$table->timestampTz('starts_at')`, with no token and no `->useCurrent()` anywhere in source.
> `ddl-default` was NOT grown to chase it, deliberately: the default is added by the SERVER, so no
> source-reading check can ever see it. What this gate asserts is zero **in tokens**.
> `docs/handoff/tickets/server-settings-the-code-cannot-see.md` carries the class.
>
> Bite-proven three ways: a planted `->useCurrent()` / `->useCurrentOnUpdate()` pair goes `exit=0`
> pre-fix → `exit=1` post-fix; a SECOND `->useCurrent()` added to an exempted file is reported
> (the exception is keyed to a line, not a file); and rewording an exempted line makes the
> exemption stop matching and the gate fail — the safe direction.

## What the lint could never see, gate or no gate

It matches **tokens**. A cross-frame comparison that names no clock function — a stored column
compared against a value the database computed some other way — is invisible to it. That half was
surveyed by hand across all 57 raw-SQL entry points in `app/` and found clean; it stays a reading
exercise.

## When this ships — it did

`bin/quality` gained a step and **the step count moved 14 → 15 for real** (sql-clock lint is step
**12**; arch 13, larastan 14, suite 15). The sweep was run for spelled-out numerals as well as
digits, across `docs/ tests/ bin/ .githooks/ .claude/` plus the repo-root files, and the sites moved
are listed in the report. Two things that were not obvious going in:

- **Dated records must NOT be swept.** `PASS 14/14` in ADR 0053's A/B/C table, the merged briefs and
  every file under `docs/handoff/reports/` record runs that happened against a 14-step gate.
  Rewriting those falsifies history; only claims about the CURRENT gate were moved.
- **Two coverage tests cite `bin/quality` LINE numbers**, not just step numbers, for the
  runs-sequentially argument — `BoundaryLintCoverageTest` and `SqlClockLintCoverageTest`. Inserting a
  step moved them. Those are re-derived here (`:238` arch, `:266` plain pest);
  `QualityStepCountTest` cannot see them, and neither can any other gate.

## Related

- `docs/handoff/tickets/stored-epoch-offset.md` — the permanent condition, and the read-layer rule
  this gate would enforce.
- `app/Finance/Services/SubledgerPoster.php` — the defect that motivated it, fixed.
- `tests/Arch/BoundaryLintCoverageTest.php` — the shape the coverage test follows.
