# Implementation report — `fix/subledger-single-clock-frame`

*Rewritten 2026-08-12 for what this commit now is. It began as a money fix plus a gate; the gate has
been split out. The lint's own measurements moved to `docs/handoff/tickets/sql-clock-lint.md`
rather than being deleted — this report keeps everything that concerns the money fix.*

## Headline

**Done.** `SubledgerPoster` captures one instant and binds it into both writes, so the ledger row
and the account projection are read back in the same clock frame. Branch
`fix/subledger-single-clock-frame`, base `origin/staging` @ `df46dff`, one change commit `c3eb0d9`
plus this report. Not pushed, no PR.

```
$ git diff origin/staging..HEAD --stat        # c3eb0d9, 2026-08-12
 .claude/skills/finance-context/SKILL.md            |   9 +-
 app/Finance/Services/SubledgerPoster.php           |  97 ++++++++-
 .../convergence-migration-target-freeze-brief.md   |   5 +-
 docs/handoff/finance-mvp-cut-brief.md              |   2 +-
 .../reports-must-not-carry-risk-rankings.md        |  55 +++++
 .../reviewer-can-see-implementer-scratchpad.md     |  57 +++++
 docs/handoff/tickets/sql-clock-lint.md             | 233 +++++++++++++++++++++
 docs/handoff/tickets/stored-epoch-offset.md        | 184 +++++++++++++---
 tests/Arch/BoundaryLintCoverageTest.php            |   4 +-
 .../Finance/CurrencyShapeConstraintTest.php        |  14 +-
 tests/Feature/Finance/SubledgerClockFrameTest.php  | 203 ++++++++++++++++++
 tests/Feature/Quality/QualityStepCountTest.php     |  50 +++++
 12 files changed, 875 insertions(+), 38 deletions(-)
```

## The split, and whose error it was

The lint that was in this commit has been removed and parked on `fix/sql-clock-lint` at `a08ddca`;
`docs/handoff/tickets/sql-clock-lint.md` specifies it with its three open defects. The trigger was a
review finding the gate failing to catch the defect it was written for on the dominant raw-SQL
surface — `whereRaw('due_date < CURRENT_DATE')` and `DB::raw('LOCALTIMESTAMP')`, exact synonyms of
`NOW()`, both passing green. It is a **preventive** gate: it guards a violation class with no live
members in the directories it scans, so holding the money fix behind it bought nothing. The
condition for splitting was set by the project lead in advance and met on its own terms.

**The scope error is the advisor's, and naming it precisely matters because it is a rule the advisor
holds.** The lint was added to this commit on instruction, and `finance-method`'s own guidance says:
*don't front-load a primitive ahead of its consumer* — build the consumer, let it demand the
primitive, then extract. The consumer here was one method in one file; the primitive was a
repository-wide gate with a new matcher, a coverage test, a step-count migration across eleven prose
sites, and a taxonomy of MySQL functions. I executed it as briefed and did not push back on the
shape at the time, which is the part that was mine.

## What changed

| File | Δ | What |
|---|---|---|
| `app/Finance/Services/SubledgerPoster.php` | +71/−9 | the fix: one captured instant bound into both writes; docblocks rewritten |
| `tests/Feature/Finance/SubledgerClockFrameTest.php` | +106 (new) | the arm, under a session zone pinned to production's `+05:30` |
| `tests/Feature/Quality/QualityStepCountTest.php` | +43 (new) | ties `bin/quality`'s printed total to its actual `step()` count |
| `docs/handoff/tickets/stored-epoch-offset.md` | +105/−22 | residual closed; offset permanent; **the read-layer rule stated as ungated** |
| `docs/handoff/tickets/sql-clock-lint.md` | +187 (new) | the split-out gate: design history, measurements, three open defects |

The code change, in full:

```php
        // Captured ONCE, before the write, and bound into both — see the docblock's "one clock frame".
        $postedAt = now();
        ...
            'posted_at' => $postedAt,
        ...
        $this->applyToAccount($schoolId, $studentId, $amount, $postedAt);
```

```php
        // Formatted the way Eloquent formats posted_at (Model::fromDateTime → the connection
        // grammar's 'Y-m-d H:i:s'), so the two columns carry the identical string.
        $stamp = $postedAt->toDateTimeString();

        DB::insert(
            'INSERT INTO finance_student_accounts
                (uuid, school_id, student_id, balance_minor, balance_currency, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                balance_minor = balance_minor + VALUES(balance_minor),
                updated_at = VALUES(updated_at)',
            [ ..., $stamp, $stamp ],
        );
```

The currency guard, the `DB::selectOne`, and `balance_minor = balance_minor + VALUES(balance_minor)`
are untouched; the `VALUES()` aliasing style is unchanged, per the brief.

**On byte identity, which the arm asserts rather than assumes:** `LedgerTransaction` casts
`posted_at => 'datetime'` (`app/Finance/Models/LedgerTransaction.php:46`) and declares no
`$dateFormat`, so Eloquent's `fromDateTime()` uses the connection grammar's `'Y-m-d H:i:s'` — the
same string `toDateTimeString()` produces from the same instant.

**On the parameter type:** `applyToAccount`'s new parameter is `Carbon\CarbonInterface`, not
`Illuminate\Support\Carbon`. Not a choice — `now()` returns `CarbonImmutable` here
(`app/Providers/AppServiceProvider.php:159`, `Date::use(CarbonImmutable::class)`), which is not an
instance of `Illuminate\Support\Carbon`. The first run of the arm failed on exactly that:

```
App\Finance\Services\SubledgerPoster::applyToAccount(): Argument #4 ($postedAt) must be of type
Illuminate\Support\Carbon, Carbon\CarbonImmutable given, called in
/Users/mac/Documents/Projects/portal/app/Finance/Services/SubledgerPoster.php on line 93
```

Worth knowing generally: an `Illuminate\Support\Carbon` hint anywhere on a `now()` value is a latent
fatal in this application.

## The watched red

Mutation: both bound parameters removed and `NOW()` restored in both places —
`VALUES (?, ?, ?, ?, ?, NOW(), NOW())` and `updated_at = NOW()`, the exact pre-fix statement.

**Observed delta: 19,800 seconds.** Read off the failure, not predicted:

```
Failed asserting that 19800 is identical to 0.
tests/Feature/Finance/SubledgerClockFrameTest.php:79
```

> **RETRACTED 2026-08-12 — this transcript was captured against a DRAFT of the arm, not the file
> that shipped.** Line 79 is not the assertion; the six-line offset is exactly the non-vacuity block
> added afterwards. The re-run against the committed file, with its real line number, is in the
> remediation section at the end of this report. Read that one, not this one.

That is `expect(strtotime($account->updated_at) - strtotime($postedAt))->toBe(0)` — the projection's
`updated_at` reading 5h30m ahead of the ledger row written beside it, on a machine whose own session
zone is `+01:00`, because the arm pins the connection to production's `+05:30`. It matches the
ticket's scaled prediction exactly.

A first attempt was **not a valid red** and is recorded rather than dropped: I restored `NOW()`
without removing the two now-unused bindings and got `SQLSTATE[HY093]: Invalid parameter number` — a
parameter-count error, not the defect. A green against that mutation would have proven nothing.

Restored and green before every gate run.

## Why the arm sets the session zone, and what it claims

On a machine whose MySQL session zone is UTC the two clocks agree and the defect **cannot appear**,
so "post a row, assert the timestamps match" would be green for the wrong reason. The arm therefore
sets `SET SESSION time_zone = '+05:30'` — production's actual value — restores it in a `finally`
(it persists for the connection, and leaking it would corrupt later tests in ways that look like
flake), and **asserts the frames genuinely differ on that connection before asserting the columns
agree anyway**. Both branches are covered: the INSERT, and the `ON DUPLICATE KEY` update, which is
the one that moves the field the bursar sees.

**The claim is the FRAME, not the INSTANT**, and both the docblock and the test say so. A second
`now()` inside `applyToAccount` would pass this arm whenever the two calls fall in the same second;
an arm that could catch that would fail intermittently, which is indistinguishable from flake.
Single-capture is structural instead — the instant is a local in `post()`, passed down, so there is
no second call to drift from.

## The survey — raw, and it is what the ticket's "zero violations" rests on

Case-sensitive, across `app/ database/ routes/ bin/`, run on the pre-fix tree:

```
$ grep -rn --include='*.php' -E 'NOW\(\)|CURDATE\(\)|CURRENT_DATE|CURRENT_TIMESTAMP|UNIX_TIMESTAMP|DATE_SUB|DATE_ADD|DATEDIFF|TIMESTAMPDIFF' app/ database/ routes/ bin/
app/Finance/Services/SubledgerPoster.php:117:             VALUES (?, ?, ?, ?, ?, NOW(), NOW())
app/Finance/Services/SubledgerPoster.php:120:                updated_at = NOW()',
database/migrations/2026_07_22_190000_create_finance_student_accounts.php:78:        // the app-created path. NOW() stamps both timestamps identically. From here,
database/migrations/2026_07_22_190000_create_finance_student_accounts.php:83:             SELECT UUID(), school_id, student_id, SUM(amount_minor), MAX(amount_currency), NOW(), NOW()
```

Two removed by this commit. `:78` is a comment. `:83` is the one-time backfill in an already-applied
migration (a dated act, ADR 0052) whose rows do not exist. `routes/` and `bin/` are zero; the
non-PHP files in those directories were grepped separately and are also zero.

**This contradicted the brief's premise**, which said the survey starts at zero once the two are
removed — true of `app/`, not of the four directories.

The second half, which no lint could mechanise — 57 raw-SQL entry points in `app/`, read by hand for
a time comparison that names no clock function:

```
$ grep -rn --include='*.php' -E 'whereRaw\(|selectRaw\(|havingRaw\(|orderByRaw\(|groupByRaw\(|DB::raw\(|DB::statement\(|DB::select|DB::insert\(|DB::update\(|DB::unprepared\(' app/ | wc -l
      57
```

Six touch a time-shaped column at all — two are `IS NULL` ordering, four are `DATE()` bucketing of a
stored column, which the ticket already measured as safe. **No site compares a stored column against
a database-computed current time.** Multi-line SQL bodies were checked too; the `DB::select` queries
in `app/Finance/Console/AuditLedgerCoherence.php` contain no `_at` column at all.

## Database observations

Dev copy `portaa10_portal`, read 2026-08-11 — structure and counts only:

```
db=portaa10_portal
finance_student_accounts=0
finance_ledger_transactions=0
session_tz=SYSTEM
```

Zero rows in the projection and zero in the ledger, so the backfill question the ticket left open is
closed by counting: nothing carries the old two-frame stamp. Production is **pre-cutover** — stated
as the project's standing position, not measured; I have no production access and attempted none.

Dev's session zone is `SYSTEM` (= WAT, `+01:00`), which is exactly why the arm pins `+05:30`: an arm
trusting this machine would measure a 3,600s defect, and on a UTC machine none at all.

## `QualityStepCountTest` — the one piece of the removed work that stays, and it passes at 14

Its assertion is **relational**, not a pinned number: it extracts the total from `step()`'s
`[%d/N]` printf, counts `^step "` invocations, asserts they are equal, and asserts the count is
`> 10` first so a broken regex cannot report `0 == 0` as agreement.

```
$ DB_DATABASE=portal_testing ./vendor/bin/pest tests/Feature/Finance/SubledgerClockFrameTest.php tests/Feature/Quality/QualityStepCountTest.php
{"tool":"pest","result":"passed","tests":2,"passed":2,"assertions":11,"duration_ms":10769}
```

`bin/quality` is back to `[%d/14]` with 14 `step` calls, and the test is green against that — which
is the point of keeping it: it is the arm proving the count went back cleanly.

Watched red (run while the branch was at 15): one `step "…"` call appended to `bin/quality` with the
printf left alone —

```
bin/quality prints [%d/15] but calls step() 16 times — a step was added or removed without moving
the total. Every other place that names a step BY NUMBER (ADRs, tickets, test comments) is prose
this test cannot see: move those by hand.
Failed asserting that 15 is identical to 16.
```

Its docblock is explicit that it does **not** cover documentation drift, and now records the
14 → 15 → 14 round trip as the reason it exists: eleven prose sites moved by hand, six missed on the
first pass with no red anywhere in the repo, and the whole set moved back when the step was split
out.

## What came out, and the check that it came out cleanly

Removed, or reverted to `origin/staging` exactly: `bin/ci-sql-clock-lint.php`,
`tests/Arch/SqlClockLintCoverageTest.php`, `bin/quality` (step 12 and the count, back to 14),
`docs/adr/0053-local-enforcement-floor.md`, `docs/adr/README.md`,
`docs/handoff/opening-balance-import-spec.md`,
`docs/handoff/tickets/lint-changed-cannot-see-uncommitted-work.md`,
`tests/Feature/Finance/PaymentProvenanceTest.php`,
`tests/Feature/Quality/PestNegatedExpectationMessagesTest.php`, and
`docs/handoff/tickets/sql-clock-lint-blind-to-usecurrent.md` (folded into the new ticket, so there
is one place).

**`.gitignore` — the five lines go, and here is the determination rather than a guess.** The block
this branch added was:

```
+
+# Same, for tests/Arch/SqlClockLintCoverageTest.php — same plant-and-remove shape,
+# same residue on a SIGKILL.
+app/Finance/SqlClockLintFixture*.php
+database/sql/SqlClockLintFixture*.sql
```

`grep -rn "SqlClockLintFixture"` over the tree returns only those two `.gitignore` lines and
`tests/Arch/SqlClockLintCoverageTest.php` itself — the file being removed. They exist solely for
that test's scratch fixtures and serve nothing else, so they leave with it. The
`BoundaryLintFixture*` entry above them is untouched.

The check, **as it stood at the split** — kept as the record of that moment, and superseded by the
stat in the headline, which is the branch as it ships:

```
$ git diff origin/staging..HEAD --stat        # c7c8279, 2026-08-12, the split commit
 app/Finance/Services/SubledgerPoster.php          |  71 +++++++-
 docs/handoff/tickets/sql-clock-lint.md            | 187 ++++++++++++++++++++++
 docs/handoff/tickets/stored-epoch-offset.md       | 127 ++++++++++++---
 tests/Feature/Finance/SubledgerClockFrameTest.php | 106 ++++++++++++
 tests/Feature/Quality/QualityStepCountTest.php    |  43 +++++
 5 files changed, 507 insertions(+), 27 deletions(-)
```

Only the files the split was meant to keep. Two remediation rounds have landed since — adding
`CurrencyShapeConstraintTest.php` (a corrected comment) and
`reports-must-not-carry-risk-rankings.md`, and growing three of the five — so the headline stat
is the current one and this block is history.

## The ticket, rewritten

`docs/handoff/tickets/stored-epoch-offset.md` no longer describes a gate that does not exist. It now
says, in these words, that the read-layer rule holds **on trust**:

> **NOTHING ENFORCES IT YET.** This is a rule on trust, not a gate. A lint for it was written on
> `fix/subledger-single-clock-frame`, reviewed three times, and split out unshipped with three open
> defects — `docs/handoff/tickets/sql-clock-lint.md` carries the design, the measurements and the
> defects. Until that lands, a clock read added to raw SQL anywhere in this repository fails no
> build.

The two-clock residual stays CLOSED; the stored-epoch offset stays PERMANENT; every measurement is
kept, including the connection-pinning refusal. `docs/handoff/tickets/sql-clock-lint.md` carries the
design history with its measurements (24-to-1 for the line-based keyword discriminator; 25-to-0 for
the token boundary; the clock-read / frame-conversion / removed-arithmetic taxonomy) and the three
open defects with the reviews' evidence verbatim — so the lint branch starts from the ticket rather
than from memory. `SubledgerPoster`'s docblock carries the same warning at the point of use.

## Finding 3 from the third review, fixed here rather than ticketed

"The single writer of the money projection" was inaccurate.
`app/Finance/Console/ReconcileAccounts.php:84` calls `$account->save()` under `--fix`, and
`StudentAccount` declares no `$timestamps` override, so that path stamps `updated_at` too. Both
re-derived, not taken from the review. No behavioural consequence — it writes through Eloquent, in
the application's frame, which is the frame this commit unifies on — so the table is single-frame by
**two** writers. The docblock now says that and names the `--fix` path, so the next auditor does not
have to find it.

## Gate — raw, unedited, 14 steps

```
quality gate — base df46dff

[1/14] dependency integrity (composer.lock vs composer.json vs vendor/)
   ✓ dependency-integrity-lint
[2/14] wayfinder:generate --with-form (must match vite.config.ts formVariants)
   ✓ wayfinder:generate
[3/14] lint changed files (Pint / Prettier / ESLint, check mode)
   ✓ lint-changed
       Pint (check) on 3 changed PHP file(s)
       Prettier: no changed frontend files
       ESLint: no changed JS/TS files
[4/14] types (tsc ratchet vs tsc-baseline)
   ✓ tsc-ratchet
[5/14] frontend build (vite — catches what the tsc ratchet structurally cannot)
   ✓ build
[6/14] authorization guard (no new commented-out checks)
   ✓ authz-lint
[7/14] boundary lint (§17.2)
   ✓ boundary-lint
[8/14] grants-convergence lint (a pre-existing permission added to grantsMap() ships a migration)
   ✓ grants-convergence-lint
[9/14] money lint (UI: money via formatNaira, no JS money math)
   ✓ money-lint
[10/14] runtime-zero lint (S7 legacy access sources)
   ✓ runtime-zero-lint
[11/14] identifier-generation bypass guard (1.4b)
   ✓ identifier-generation-lint
[12/14] architecture tests (§17.1)
   ✓ arch
[13/14] static analysis (Larastan level 5 vs baseline)
   ✓ larastan
[14/14] tests (failure ratchet vs tests/ratchet-baseline.txt)
   ✓ test-ratchet

✓ quality: PASS — per-push floor. Promoting to main? run bin/quality-promote.
```

**One run, PASS on the first attempt, and the step numbering is back where it was** — step 12 is
the arch tests again, not the lint. Suite inside it: **1588** tests, 6 failures + 1 error, the same
baselined set every run on this branch has produced (4 × `ActivityLogApiTest`, 2 ×
`GuardianProfileTest`, 1 × `AuthenticationTest` "users are rate limited"). The arithmetic across the
branch: 1587 with the clock-frame arm, 1591 and then 1593 as the lint's coverage arms landed, 1588
now that the five lint arms are gone and `QualityStepCountTest` stays. `SubledgerClockFrameTest`
and `QualityStepCountTest` are both in that junit and neither is among the failures; no
sql-clock-lint arm is present, which is the removal confirmed from the run rather than from the
diff.

## Not done, and what I could not verify

- **The read-layer rule ships ungated.** That is the deliberate outcome of the split, stated in the
  ticket and in `SubledgerPoster`'s docblock. The arm covers this method; a clock read added
  anywhere else fails no build today.
- **Production is an inherited input** — `+05:30` and pre-cutover, from the ticket and the project's
  standing position. The fix is correct whatever the offset is; the 19,800s figure is scaled from a
  dev reading, not measured on production.
- **The 57-site survey is a human reading**, done once, at this commit. Nothing re-runs it.
- **The three open lint defects are unfixed by design**, recorded in the new ticket. Two of them
  were found by review rather than by any arm I wrote; the third (`useCurrent`) I found and
  ticketed.
- I did not touch `config/database.php` and did not open it.

---

# Remediation 3 — 2026-08-12

Three findings from the fourth review, all fixed. Branch is now `02944b0` (the change) plus this
report. Gate: **PASS 14/14, first run**, 1588 tests, same seven baselined failures.

A method rule came out of this round and is recorded where it belongs, as a standalone document
rather than inside a report:
**`docs/handoff/tickets/reports-must-not-carry-risk-rankings.md`**.

## The retraction, stated plainly

**Both earlier watched reds for `SubledgerClockFrameTest` were captured against a DRAFT of the arm,
not the file that shipped.** The transcript cited `SubledgerClockFrameTest.php:79`; in the committed
file line 79 is a `DB::selectOne` and the assertion is at **86**. The six-line offset is exactly the
non-vacuity block (the `@@session.time_zone` assertion and the `19700` frame check) that was added
after the red was taken. So the arm as committed had been observed green and never red — the one
state this project's method treats as proving nothing.

**This is the second time on this branch that a red was recorded against something other than what
was committed.** The first was Remediation 1's `str_contains`-vs-`strpos` instruction, which named a
mutation that could not produce the red I had actually run. The pattern in both: I mutated, watched,
restored, then kept editing the file the red was about. The fix is procedural and I am stating it so
it can be checked — **re-run every watched red as the last action before committing, not as the
action that convinced me.**

Everything below was run against the committed file.

## FIX 1 — the ON DUPLICATE KEY branch is no longer same-second-vacuous

Both posts ran back to back, so `$secondPostedAt` was the same `Y-m-d H:i:s` string as `$postedAt`
and all three ODKU expectations were satisfied by one frozen value. The arm now imposes a gap:

```php
        test()->travel(90)->seconds();
        // …second post…
        expect(strtotime($secondPostedAt) - strtotime($postedAt))->toBe(90)   // the imposed gap actually landed
            ->and(strtotime($account->updated_at) - strtotime($secondPostedAt))->toBe(0)
            ->and($account->updated_at)->toBe($secondPostedAt)
            ->and($account->created_at)->toBe($postedAt); // the INSERT's instant, untouched by the update
```

Deterministic, not raced — the difference is imposed by the test clock, which is why this is not the
same-instant flakiness refused earlier. Ordering is load-bearing and the comment says so: the
SQL-vs-PHP frame check must run **before** the travel, because MySQL's `NOW()` does not follow
Laravel's test clock. `test()->travelBack()` sits in the same `finally` as the session-zone restore,
for the same reason — a leaked offset is cross-test poison.

**Watched red, against the committed file — delete `updated_at = VALUES(updated_at)` from the
upsert:**

```
tests/Feature/Finance/SubledgerClockFrameTest.php:119
Failed asserting that -90 is identical to 0.
```

−90: `updated_at` frozen ninety seconds behind the second post's `posted_at`, which on the accounts
index is `last_activity` stuck at the account's first movement forever. Restored; green.

**The control that proves the arm needed fixing.** The same mutation, run against the arm exactly as
it was committed at `c7c8279` (travel removed, gap assertion removed):

```
{"tool":"pest","result":"passed","tests":1,"passed":1,"assertions":8}
```

Green. That is the finding reproduced rather than accepted: the shipped arm did not cover the branch
its own comment claimed to cover.

## FIX 2 — every watched red re-run against the committed file

**Red A — the original defect.** Restore `NOW()` in both places and drop the two bindings (the exact
pre-fix statement):

```
tests/Feature/Finance/SubledgerClockFrameTest.php:86
Failed asserting that 19800 is identical to 0.
```

Line **86**, not 79. Same 19,800s, now demonstrated on the file that ships.

**Red B — FIX 1's mutation**, above: line **119**, `-90`.

Restored between each, and green after both:

```
{"tool":"pest","result":"passed","tests":1,"passed":1,"assertions":9}
```

Nine assertions, up from eight — the imposed-gap check is the new one.

## FIX 3 — the false comment, and the scope claim I would have shipped wrong

**(a)** `tests/Feature/Finance/CurrencyShapeConstraintTest.php`'s helper said it raw-inserts a row
"the way `SubledgerPoster` does". It does not, and this commit is why. The comment now says both
halves: that the method binds a PHP-captured instant now, and that the `NOW(), NOW()` in the fixture
is **deliberately left alone** — it exists to trip the `balance_currency` CHECK, no assertion in that
file reads a timestamp, and changing the SQL would be churn. The SQL is untouched.

**(b) The `tests/` survey, raw — including what is not a violation:**

```
$ grep -rn --include='*.php' -E 'NOW\(\)|CURDATE\(\)|CURRENT_DATE|CURRENT_TIMESTAMP|UNIX_TIMESTAMP|DATE_SUB|DATE_ADD|DATEDIFF|TIMESTAMPDIFF|SYSDATE|UTC_TIMESTAMP|LOCALTIME' tests/
tests/Feature/Finance/CurrencyShapeConstraintTest.php:43: * and updated_at, because MySQL's NOW() is in the session zone and every other timestamp in the
tests/Feature/Finance/CurrencyShapeConstraintTest.php:44: * schema is in app.timezone (…). The NOW(), NOW() here is
tests/Feature/Finance/CurrencyShapeConstraintTest.php:54:         VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
tests/Feature/Finance/SubledgerClockFrameTest.php:24: * session zone differs from app.timezone: `NOW()` is evaluated in the session zone and stores the
tests/Feature/Finance/SubledgerClockFrameTest.php:68:        $sqlClock = strtotime(DB::selectOne('SELECT NOW() AS n')->n);
tests/Feature/Finance/SubledgerClockFrameTest.php:74:        $poster = app(SubledgerPoster::class);
tests/Feature/Finance/SubledgerClockFrameTest.php:101:        // MySQL's NOW() does not follow Laravel's test clock. travelBack() in the finally, for the
```

**Exactly one live occurrence: `CurrencyShapeConstraintTest.php:54`.** Everything else is a comment,
except `SubledgerClockFrameTest.php:68`, which reads the session clock **in order to prove the two
frames differ** — the opposite of a violation, and the reason the shipped lint excluded `tests/`.
(The line moved from `:42` to `:54` because the corrected comment in (a) is longer than the one it
replaced; the brief cited the pre-edit number.)

`docs/handoff/tickets/sql-clock-lint.md` no longer says "zero live violations" flat. It now carries
a scope-and-count table — **0 in the four scanned directories, 1 in `tests/` at that file and line,
`tests/` not surveyed when the lint was designed** — and states explicitly that whether `tests/`
belongs in scope is a decision for that branch, not a conclusion handed to it.

## Gate — raw, unedited, 14 steps

```
quality gate — base df46dff

[1/14] dependency integrity (composer.lock vs composer.json vs vendor/)
   ✓ dependency-integrity-lint
[2/14] wayfinder:generate --with-form (must match vite.config.ts formVariants)
   ✓ wayfinder:generate
[3/14] lint changed files (Pint / Prettier / ESLint, check mode)
   ✓ lint-changed
       Pint (check) on 4 changed PHP file(s)
       Prettier: no changed frontend files
       ESLint: no changed JS/TS files
[4/14] types (tsc ratchet vs tsc-baseline)
   ✓ tsc-ratchet
[5/14] frontend build (vite — catches what the tsc ratchet structurally cannot)
   ✓ build
[6/14] authorization guard (no new commented-out checks)
   ✓ authz-lint
[7/14] boundary lint (§17.2)
   ✓ boundary-lint
[8/14] grants-convergence lint (a pre-existing permission added to grantsMap() ships a migration)
   ✓ grants-convergence-lint
[9/14] money lint (UI: money via formatNaira, no JS money math)
   ✓ money-lint
[10/14] runtime-zero lint (S7 legacy access sources)
   ✓ runtime-zero-lint
[11/14] identifier-generation bypass guard (1.4b)
   ✓ identifier-generation-lint
[12/14] architecture tests (§17.1)
   ✓ arch
[13/14] static analysis (Larastan level 5 vs baseline)
   ✓ larastan
[14/14] tests (failure ratchet vs tests/ratchet-baseline.txt)
   ✓ test-ratchet

✓ quality: PASS — per-push floor. Promoting to main? run bin/quality-promote.
```

**One run, PASS on the first attempt.** 1588 tests, 6 failures + 1 error — the same baselined set
(4 × `ActivityLogApiTest`, 2 × `GuardianProfileTest`, 1 × `AuthenticationTest` "users are rate
limited"). Count unchanged from the split run, as expected: this round added assertions, not tests.

## What I could not verify, this round

- **The travel does not leak, but I proved it narrowly.** `travelBack()` is in the `finally`, and
  the whole `tests/Feature/Finance` directory passes, but nothing asserts the absence of a leaked
  offset the way the session-zone restore is asserted by the arm's own non-vacuity check. A leak
  would surface as unrelated time-sensitive failures elsewhere in the suite, and the full run is
  clean.
- **`updated_at` has no other writer that could mask a frozen column.** Re-derived: neither
  `created_at` nor `updated_at` on `finance_student_accounts` carries `ON UPDATE CURRENT_TIMESTAMP`,
  so the red above is attributable to the deleted clause alone.
- **Production remains an inherited input** — `+05:30`, pre-cutover. Unchanged across all four
  rounds.

---

# Final round — 2026-08-12

Four findings from the fifth review, all fixed. Branch is `b885256` (the change) plus this report.
Gate: **PASS 14/14, first run**, 1588 tests, same seven baselined failures.

## What changed

| | |
|---|---|
| `tests/Feature/Finance/SubledgerClockFrameTest.php` | each block now pins its write to the **application's instant**, not only to the other columns |
| `docs/handoff/tickets/sql-clock-lint.md` | `a08ddca` marked a REFERENCE, not a base; the five superseded paths named; the step-count sweep marked re-derive-not-cherry-pick |
| `docs/handoff/tickets/reports-must-not-carry-risk-rankings.md` | new — the method rule, as a standalone record |
| this report | rankings removed; both `--stat` blocks corrected |

**The pin, and why it was missing something real.** Every prior assertion compared the four columns
to each other, and a *uniform* conversion satisfies all of them: shifting the captured instant keeps
the columns byte-identical and the 90-second gap exact while `last_activity` reads 19,800s into the
future again. One assertion in each block closes it:

```php
->and(abs(strtotime($postedAt) - now()->getTimestamp()))->toBeLessThan(5);
```

In both, not one: `now()` is travelled with the second post, so the comparison holds on either side
of the imposed gap, and the ODKU branch is the one that feeds the screen.

**Where the method note went.** `docs/handoff/tickets/reports-must-not-carry-risk-rankings.md`. It
cites both instances without restating which files they concerned, and it is a standalone document
because the first attempt to write it lived inside a report and became the anchor it was warning
about. Every ranking sentence has been removed from this report; the two retractions stay, because
those are findings about the work.

## Every watched red, re-run against the committed tree as the last action before this commit

That is the procedural fix recorded last round, discharged. All four run against `b885256`, each
restored before the next.

**Red 1 — the original defect.** `NOW()` restored in both places, the two bindings dropped:

```
tests/Feature/Finance/SubledgerClockFrameTest.php:86
Failed asserting that 19800 is identical to 0.
```

**Red 2 — the ODKU simplification.** `updated_at = VALUES(updated_at)` deleted:

```
tests/Feature/Finance/SubledgerClockFrameTest.php:127
Failed asserting that -90 is identical to 0.
```

**Red 3 — the uniform frame conversion, the mutation the new pin exists for.** `$postedAt =
now()->setTimezone('+05:30')`, so **both** writes carry the shifted instant and the columns stay
byte-identical:

```
tests/Feature/Finance/SubledgerClockFrameTest.php:96
Failed asserting that 19800 is less than 5.
```

Six assertions pass before it — the columns do agree with each other, exactly as the finding
predicted — and the pin is what fails. A first attempt at this red mutated only `$stamp`, which
shifts the account columns alone and is caught by the old line-86 assertion; that is a different
mutation and would not have exercised the new one. Recorded because it is the same class of
not-quite-the-right-mutation error as the two retractions above.

**Red 4 — `QualityStepCountTest`, against the committed 14-step `bin/quality`.** One `step "…"`
call appended, the printf left alone:

```
tests/Feature/Quality/QualityStepCountTest.php:37
bin/quality prints [%d/14] but calls step() 15 times — a step was added or removed without moving
the total. Every other place that names a step BY NUMBER (ADRs, tickets, test comments) is prose
this test cannot see: move those by hand.
Failed asserting that 14 is identical to 15.
```

The previous transcript for this guard was taken at 15 steps against an earlier version of the test.
This one is the committed file at the committed step count.

**All four restored, then green:**

```
{"tool":"pest","result":"passed","tests":2,"passed":2,"assertions":14}
```

Eleven assertions in the arm (nine plus the two new pins) and three in the step-count guard.

## Gate — raw, unedited, 14 steps

```
quality gate — base df46dff

[1/14] dependency integrity (composer.lock vs composer.json vs vendor/)
   ✓ dependency-integrity-lint
[2/14] wayfinder:generate --with-form (must match vite.config.ts formVariants)
   ✓ wayfinder:generate
[3/14] lint changed files (Pint / Prettier / ESLint, check mode)
   ✓ lint-changed
       Pint (check) on 4 changed PHP file(s)
       Prettier: no changed frontend files
       ESLint: no changed JS/TS files
[4/14] types (tsc ratchet vs tsc-baseline)
   ✓ tsc-ratchet
[5/14] frontend build (vite — catches what the tsc ratchet structurally cannot)
   ✓ build
[6/14] authorization guard (no new commented-out checks)
   ✓ authz-lint
[7/14] boundary lint (§17.2)
   ✓ boundary-lint
[8/14] grants-convergence lint (a pre-existing permission added to grantsMap() ships a migration)
   ✓ grants-convergence-lint
[9/14] money lint (UI: money via formatNaira, no JS money math)
   ✓ money-lint
[10/14] runtime-zero lint (S7 legacy access sources)
   ✓ runtime-zero-lint
[11/14] identifier-generation bypass guard (1.4b)
   ✓ identifier-generation-lint
[12/14] architecture tests (§17.1)
   ✓ arch
[13/14] static analysis (Larastan level 5 vs baseline)
   ✓ larastan
[14/14] tests (failure ratchet vs tests/ratchet-baseline.txt)
   ✓ test-ratchet

✓ quality: PASS — per-push floor. Promoting to main? run bin/quality-promote.
```

One run. The gate ran before the four reds above; the reds were run afterwards against the same
committed tree and every mutation was reverted, leaving the tree byte-identical to what the gate
measured (`git status` clean but for this report).

## What I could not verify

- **Production** — `+05:30` and pre-cutover remain inherited inputs across all five rounds. No
  access, none attempted.
- **The 57-site raw-SQL survey** is a human reading taken once. Nothing re-runs it, and no gate
  ships that would.
- **The read-layer rule ships ungated**, by decision. `docs/handoff/tickets/stored-epoch-offset.md`
  says so in those words.

---

# Close-out — 2026-08-12

Four findings from the sixth review. Three fixed; the fourth filed as a ticket and not acted on,
because it is not a repo change. Branch is `c5fff98` (the change) plus this report. Gate:
**PASS 14/14, first run**.

## FIX 1 — `updated_at` can no longer go backwards, and it IS armed

```sql
updated_at = GREATEST(updated_at, VALUES(updated_at))
```

Binding a stamp captured at the top of `post()` replaced `NOW()` evaluated by the server at
statement time — i.e. after the row lock — so two posts racing one account can reach the upsert in
the opposite order to their capture, and a plain assignment lets `last_activity` show a time before
the payment that just landed. The docblock's "behave exactly as before" is corrected rather than
softened: it is true of `balance_minor` and was false of the timestamp, and the docblock now says
which changed, why, and what `GREATEST` restores.

**I could arm it, deterministically, without staging a race.** The arm does not try to interleave
two transactions — it stages the *property* instead. After the second post it travels **backward**
150 seconds and posts a third time, so that post binds an instant **earlier** than the row's current
`updated_at` — exactly the value a losing racer would carry:

```php
        test()->travel(-150)->seconds();   // 60s BEFORE the first post, from the +90 position
        …
        expect(strtotime($thirdPostedAt) - strtotime($secondPostedAt))->toBe(-150)  // it really did bind earlier
            ->and($account->updated_at)->toBe($secondPostedAt)   // …and updated_at did NOT move down
            ->and($account->created_at)->toBe($postedAt)
            ->and((int) $account->balance_minor)->toBe(5_000);   // the balance still moved
```

The balance assertion is there because `GREATEST` must not become an excuse for the row not
updating: 10000 − 4000 − 1000.

**Watched red — `GREATEST` reduced to the plain assignment:**

```
tests/Feature/Finance/SubledgerClockFrameTest.php:159
Failed asserting that two strings are identical.
--- Expected
+++ Actual
@@ @@
-'2026-08-12 17:39:16'
+'2026-08-12 17:36:46'
```

150 seconds backwards, which is the defect exactly.

## FIX 2 — the step-count guard now counts indented calls

`'/^\s*step "/m'`, not `'/^step "/m'`.

**Watched red with an indented call** — a `step` inside `if true; then … fi`, which is the shape a
flagged or optional step would take:

```
tests/Feature/Quality/QualityStepCountTest.php:44
bin/quality prints [%d/14] but calls step() 15 times — a step was added or removed without moving
the total. …
Failed asserting that 14 is identical to 15.
```

**And the control, which is the part that matters** — the identical plant against the old
column-zero anchor:

```
{"tool":"pest","result":"passed","tests":1,"passed":1,"assertions":3}
```

Green. The undercount agrees with the unmoved printf and the guard passes while the gate would
print `[15/14]`. That is the blind spot reproduced rather than reasoned about.

## FIX 3 — the two stale files, and the sweep that should have found them

Corrected: `.claude/skills/finance-context/SKILL.md:162` (13 → **14** steps, and grants-convergence
7 → **8**) and `docs/handoff/finance-mvp-cut-brief.md:69` (13 → **14**). The substrate skill's line
now also says to **re-derive** the number rather than read it, and names the two commands — that
file's whole job is to be read by the next agent, and it has been wrong at least twice.

**`.claude/` was outside every previous sweep, which is why these survived.** The sweep re-run
across `docs/ tests/ bin/ .claude/ CONTRIBUTING.md CLAUDE.md README.md`, excluding
`docs/handoff/reports/`, returns 44 lines. Everything matching a step count is listed below; the
rest are unrelated counts (enum counts, role counts, screen counts, index counts) and are named as
such rather than dropped silently.

**Correct at 14 today, no action:** `bin/quality:16, :38, :59, :70, :146, :147, :278`;
`docs/adr/0053-local-enforcement-floor.md:33, :41, :105`; `docs/adr/README.md:65`;
`docs/handoff/opening-balance-import-spec.md:560`; `docs/handoff/u1-fee-schedules-brief.md:180,
:384, :555, :676`; `bin/ci-dependency-integrity-lint.php:36`;
`tests/Feature/Quality/PestNegatedExpectationMessagesTest.php:68`;
`tests/Feature/Finance/PaymentProvenanceTest.php:51`.

**Two further stale references found by the same sweep, fixed with the two named** — both were live
instructions rather than dated records, which is why I did not leave them:

- `docs/handoff/convergence-migration-target-freeze-brief.md:221` said a test "runs under
  `bin/quality` step 13". The suite is 14. Rewritten to name the step by role, with the old number
  kept as history — the same drift-proof form `bin/quality:39-40` already uses ("the suite is the
  last step, whatever its number").
- `tests/Arch/BoundaryLintCoverageTest.php:31` cited `bin/quality:153` and `:161` as the two Pest
  invocations. Re-derived: they are `:225` and `:253`. Its "step 13" now reads "either", for the
  same reason.

**Left alone, deliberately:** `tests/Arch/BoundaryLintCoverageTest.php:11` and
`docs/handoff/opening-balance-import-spec.md:813` both say a defect "passed all thirteen quality
steps" — a dated account of an event, true when written.

## TICKET 4 — the scratchpad

`docs/handoff/tickets/reviewer-can-see-implementer-scratchpad.md`. Records by **name pattern only**
what the reviewer could see, that it did not open any of it, and why the exposure matters: the cold
review's value rests on re-derivation, and a shared scratchpad makes that bypassable silently and
undetectably after the fact. It states that this is a harness question for the project lead and
proposes no repo change. Filed and stopped there.

## Every red, re-run against the committed tree as the last action before this commit

Five, each restored before the next, then all green together:

| Mutation | Red |
|---|---|
| `NOW()` restored in both places, bindings dropped | `SubledgerClockFrameTest.php:86` — `19800 is identical to 0` |
| `updated_at` clause deleted from the ODKU | `:127` — `-90 is identical to 0` |
| uniform frame conversion (`now()->setTimezone('+05:30')` at capture) | `:96` — `19800 is less than 5` |
| `GREATEST` → plain assignment | `:159` — `17:39:16` vs `17:36:46` |
| indented `step` call added to `bin/quality` | `QualityStepCountTest.php:44` — `14 is identical to 15` |

```
{"tool":"pest","result":"passed","tests":2,"passed":2,"assertions":18}
```

Eighteen assertions — fifteen in the arm, three in the guard. `git status` clean but for this
report.

## Gate — raw, unedited, 14 steps

```
quality gate — base df46dff

[1/14] dependency integrity (composer.lock vs composer.json vs vendor/)
   ✓ dependency-integrity-lint
[2/14] wayfinder:generate --with-form (must match vite.config.ts formVariants)
   ✓ wayfinder:generate
[3/14] lint changed files (Pint / Prettier / ESLint, check mode)
   ✓ lint-changed
       Pint (check) on 5 changed PHP file(s)
       Prettier: no changed frontend files
       ESLint: no changed JS/TS files
[4/14] types (tsc ratchet vs tsc-baseline)
   ✓ tsc-ratchet
[5/14] frontend build (vite — catches what the tsc ratchet structurally cannot)
   ✓ build
[6/14] authorization guard (no new commented-out checks)
   ✓ authz-lint
[7/14] boundary lint (§17.2)
   ✓ boundary-lint
[8/14] grants-convergence lint (a pre-existing permission added to grantsMap() ships a migration)
   ✓ grants-convergence-lint
[9/14] money lint (UI: money via formatNaira, no JS money math)
   ✓ money-lint
[10/14] runtime-zero lint (S7 legacy access sources)
   ✓ runtime-zero-lint
[11/14] identifier-generation bypass guard (1.4b)
   ✓ identifier-generation-lint
[12/14] architecture tests (§17.1)
   ✓ arch
[13/14] static analysis (Larastan level 5 vs baseline)
   ✓ larastan
[14/14] tests (failure ratchet vs tests/ratchet-baseline.txt)
   ✓ test-ratchet

✓ quality: PASS — per-push floor. Promoting to main? run bin/quality-promote.
```

One run. The five reds above were run after it, against the same committed tree, and every mutation
was reverted.

## What I could not verify

- **The race in Finding 1 is derived from the statement semantics, not observed.** The arm proves
  the property `GREATEST` guarantees — an earlier-binding post cannot move `updated_at` down — not
  that two transactions actually interleave that way. There is no deterministic red for the race
  and none was fabricated.
- **Production** — `+05:30`, pre-cutover, inherited across all six rounds. No access, none
  attempted.
- **The read-layer rule ships ungated**, by decision, with the gate specified on `fix/sql-clock-lint`
  and its three open defects recorded.

---

# Record correction — 2026-08-12

Three findings from the seventh review, one of which touches the executable path by one token.
Branch is `c3eb0d9` (the change) plus this report. Gate: **PASS 14/14, first run**.

**Both of the first two originate in instructions given to this hand, and that is the finding worth
carrying forward.** `GREATEST` was ordered without checking it against the legacy rows the adjacent
ticket describes — the check is one paragraph of that same ticket's own arithmetic. The "the column
is NOT NULL" claim that justified omitting a null guard was asserted from recollection rather than
read; `information_schema` says otherwise and `FinanceAccountController:67` had been writing `?->`
against it all along. Both are corrections to the record, which is what this commit's last four
rounds have been.

## FIX 1 — `GREATEST` protects the rows it was meant to heal, and the ticket now says so

The clause **stays** — the monotonicity regression it closes is real. What was wrong was the record:
`stored-epoch-offset.md` still printed the pre-`GREATEST` statement and closed the backfill question
on the grounds that "the first `post()` re-stamps its `updated_at`". Under `GREATEST` it does not: a
legacy `NOW()`-written row stores the TRUE instant, a PHP-bound write stores `true − offset`, so the
legacy value is the larger one and is kept. The heal is **delayed, not absent**, and delayed on
exactly the rows the fix was for.

The ticket now prints the shipped SQL, states that mechanism, and — per the addendum — closes the
question by measurement rather than leaving it as an action:

```
Measured on PRODUCTION, 2026-08-12, by the project lead

finance_student_accounts             0 rows
finance_ledger_transactions          0 rows
@@session.time_zone                  SYSTEM
@@global.time_zone                   SYSTEM
TIMEDIFF(NOW(), UTC_TIMESTAMP())     05:30:00
```

Recorded as readings with that date and attribution, supporting exactly three conclusions:

1. **The backfill question is CLOSED by a count, not by inheritance.** Zero accounts, zero ledger
   rows: no production row carries a MySQL-framed timestamp, so there is nothing for `GREATEST` to
   delay the healing of. The exposure was **real in shape and empty in fact**.
2. **The delayed-heal case is unreachable going forward**, and the ticket says why rather than just
   that: after this merges `SubledgerPoster` binds a PHP instant on every path, so no `NOW()`-framed
   row is ever written to that table again, so none can dominate a later bind. The one theoretical
   producer left is the backfill migration itself, which replays only on a fresh database against an
   empty ledger and therefore writes nothing. Noted; nothing built for it.
3. **`+05:30` is confirmed current as of 2026-08-12**, not carried. The date sits beside it so a
   later reader can judge its staleness.

I did not run any of these and could not have — no production access. They are the project lead's
readings and the ticket attributes them.

## FIX 2 — the columns are nullable, and `GREATEST` would have latched NULL forever

```sql
updated_at = COALESCE(GREATEST(updated_at, VALUES(updated_at)), VALUES(updated_at))
```

`$table->timestamps()` emits `->nullable()`; `information_schema` on the dev copy returns
`IS_NULLABLE: YES` for both columns, and the table carries **zero triggers**. `GREATEST` returns
NULL if any argument is NULL, so a NULL `updated_at` would have been set to NULL by every subsequent
post — permanently, where the `NOW()` it replaced self-healed such a row on the first write. A
permanent latch is a worse failure shape than the transient one `GREATEST` was added to fix.

**It is armed, honestly.** The state is reachable — nullable columns, no trigger — so the arm plants
it rather than reasoning about it: insert a row with `updated_at = NULL` directly, assert the
precondition is real, post through `SubledgerPoster`, assert the column recovered to that post's
`posted_at` and the balance moved. No path in `app/` produces such a row today, which is stated in
both the docblock and the test; the arm exists for the future hand-written INSERT.

The docblock's `NOT NULL` claim is corrected to what the schema says, with the reason the `COALESCE`
is there written beside it so it does not read as noise.

## FIX 3 — the hedge restored

The ticket said the +19,800s figure was "scaled from the dev reading, not measured on production";
the docblock stated it flat. Both now carry the same words — and the addendum sharpens them: the
**zone** is measured (`+05:30`, re-confirmed 2026-08-12), the **display error** is still scaled from
dev. The defect never depended on the size of the offset, only on its existence.

## Every red, re-run against the committed tree as the last action

Six. Each restored before the next; all green together afterwards.

| Mutation | Red |
|---|---|
| `NOW()` restored, bindings dropped | `SubledgerClockFrameTest.php:87` — `19800 is identical to 0` (and `:201`, the null-recovery arm, at an hour's difference on the dev zone) |
| `updated_at` clause deleted from the ODKU | `:128` — `-90 is identical to 0` |
| uniform frame conversion at capture | `:97` — `19800 is less than 5` |
| `GREATEST`/`COALESCE` → plain assignment | `:160` — `18:31:53` vs `18:29:23` (150s backwards) |
| `COALESCE` dropped, `GREATEST` kept | `:200` — `Expecting null not to be null` |
| indented `step` added to `bin/quality` | `QualityStepCountTest.php:44` — `14 is identical to 15` |

The sixth has a control, and it is the half that matters: the identical plant against the old
column-zero anchor passes (`"passed":1`), which is the blind spot reproduced rather than argued.

```
{"tool":"pest","result":"passed","tests":3,"passed":3,"assertions":22}
```

`git status` clean but for this report; `git diff HEAD` empty.

## Gate — raw, unedited, 14 steps

```
quality gate — base df46dff

[1/14] dependency integrity (composer.lock vs composer.json vs vendor/)
   ✓ dependency-integrity-lint
[2/14] wayfinder:generate --with-form (must match vite.config.ts formVariants)
   ✓ wayfinder:generate
[3/14] lint changed files (Pint / Prettier / ESLint, check mode)
   ✓ lint-changed
       Pint (check) on 5 changed PHP file(s)
       Prettier: no changed frontend files
       ESLint: no changed JS/TS files
[4/14] types (tsc ratchet vs tsc-baseline)
   ✓ tsc-ratchet
[5/14] frontend build (vite — catches what the tsc ratchet structurally cannot)
   ✓ build
[6/14] authorization guard (no new commented-out checks)
   ✓ authz-lint
[7/14] boundary lint (§17.2)
   ✓ boundary-lint
[8/14] grants-convergence lint (a pre-existing permission added to grantsMap() ships a migration)
   ✓ grants-convergence-lint
[9/14] money lint (UI: money via formatNaira, no JS money math)
   ✓ money-lint
[10/14] runtime-zero lint (S7 legacy access sources)
   ✓ runtime-zero-lint
[11/14] identifier-generation bypass guard (1.4b)
   ✓ identifier-generation-lint
[12/14] architecture tests (§17.1)
   ✓ arch
[13/14] static analysis (Larastan level 5 vs baseline)
   ✓ larastan
[14/14] tests (failure ratchet vs tests/ratchet-baseline.txt)
   ✓ test-ratchet

✓ quality: PASS — per-push floor. Promoting to main? run bin/quality-promote.
```

One run, on the tree that includes the production readings. The six reds above were run after it and
every mutation reverted.

## What I could not verify

- **The race `GREATEST` guards is not staged.** The arm proves the property — an earlier-binding
  post cannot move `updated_at` down — not that two transactions interleave that way. No
  deterministic red exists for the race and none was fabricated.
- **The production readings are the project lead's**, taken 2026-08-12. I have no production access,
  attempted none, and did not verify them; the ticket attributes them rather than presenting them as
  mine.
- **The read-layer rule still ships ungated**, by decision, with the gate and its three open defects
  specified on `fix/sql-clock-lint`.
