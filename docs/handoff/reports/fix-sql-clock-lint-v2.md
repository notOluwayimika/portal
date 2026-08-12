# Implementation report — `fix/sql-clock-lint-v2`

## Headline

**Done.** The sql-clock lint ships on its own branch with all three open defects closed and
bite-proven, `tests/` decided and argued OUT on a measurement, and `bin/quality` at 15 steps with
the documentation sweep run for spelled-out numerals as well as digits.

Branch `fix/sql-clock-lint-v2`, re-cut from `origin/staging` @ `4928064`. Gate: **PASS 15/15, first
run, no red.**

This is **full-review tier** — it changes a gate and adds a lint. Subagent review attached;
recommend a cold session before merge.

## Deviations from the brief

**1. Defect 3 (`->useCurrent()` blindness) is CLOSED, not recorded as open.** The brief left this as
a decision. I closed it. The rule is *the database server's clock never writes a column*, and
`->useCurrent()` is exactly that on a surface the string-literal matcher is blind to **by
construction** — so leaving it open means a new finance migration ships the original defect
invisibly, past the gate written to stop it. The cost was one extra token pass and three named
exceptions, all of them applied migrations, which is the same class as the exemption the lint
already carried.

Two sub-deviations inside it, both against the sketch in the ticket:

- **Scoped to all four scanned directories, not `database/` only.** `useCurrent` appears nowhere
  outside those three migrations (re-derived over `app/ database/ routes/ bin/ tests/ config/`), so
  the wider scope costs nothing and does not rest on Blueprints only ever living under `database/`.
- **Matched case-insensitively, on `->` + name rather than on the bare word.** PHP method names are
  case-insensitive at the call site, so `->UseCurrent()` runs and a case-sensitive gate is evadable
  by typing. Matching the CALL keeps the docblock that explains the rule from reporting itself.

**2. Defect 1 closed by dropping the requirement outright, not by widening the discriminator to the
enclosing call.** The ticket offered three routes and asked for a measurement. The measurement
decided it (below). I rejected the enclosing-call route explicitly: it is more code and has a real
hole — heredoc SQL assigned to a variable and then passed to `DB::statement($sql)` has no enclosing
`*Raw` call to read.

**3. One existing coverage assertion was inverted, deliberately, and is the price of deviation 2.**
`'The current_date filter'` was asserted NOT reported; it is now reported. I did not quietly delete
that constant — I moved it into its own arm that **asserts the false positive**, so the trade is on
the record. Rewriting the arm to keep it green would have converted a priced decision into an
invisible one.

**General rules I formed, stated as rules so they can be attacked:**

- *A false positive in this lint is strictly cheaper than a false negative.* It is loud, it lands on
  the author's own new line at push time, and EXCEPTIONS is the documented escape hatch. The miss it
  replaces is silent and shipped a timestamp 5.5 hours wrong to a bursar's screen. **This is a
  judgement about this rule, not a general lint principle** — it holds because the measured FP rate
  on the scanned surface is zero.
- *The "SQL is a string, the helper is code" discriminator does not survive contact with `tests/`.*
  Tests put prose and PHP source inside string literals as a matter of course, so under `tests/` the
  matcher degrades to a substring grep. This is the argument for the scope decision, and it is
  structural rather than a count.

## Contradictions of the premise

**One, and it changes the size of the tests/ decision.** The ticket records "**1** live occurrence
in `tests/`". Running the shipped matcher over `tests/` returns **16**. The ticket's figure was a
hand survey for `NOW()` in a raw-insert shape; the matcher also sees assertion messages, PHP source
held in strings, and this lint's own planted fixtures. The extra 15 are all false positives — which
strengthens rather than weakens the ticket's instinct, but a reader sizing the decision on "1 hit"
would have sized it wrong in the other direction.

Everything else in the ticket reproduced. Specifically re-confirmed rather than carried:

- `fix/sql-clock-lint` tip is `ff57312`, the change at `a08ddca` — as the brief said, verified not
  trusted.
- The three `->useCurrent()` sites are exactly the three listed, at the lines listed.
- `bin/quality` on `staging` is 14 steps; it is 15 here.

## What changed

| File | Lines | What |
|---|---|---|
| `bin/ci-sql-clock-lint.php` | +219 / −63 (new file on this branch) | The lint. `findToken()` returns earliest-occurring and drops the bare-form keyword requirement; new `scanUseCurrent()` code pass; `ddl-default` rule class; three new line-keyed exceptions; `ruleFor()`. |
| `tests/Arch/SqlClockLintCoverageTest.php` | +170 / −18 (new file on this branch) | 4 new arms (bare-in-`*Raw`, the priced residual, position-order, `useCurrent`), 1 arm re-pointed, non-vacuity extended to the three new exemptions. 9 tests, 40 assertions. |
| `bin/quality` | +25 / −12 | Step 12, `sql-clock lint`. Count 14 → 15 and every internal cross-reference with it. |
| `.gitignore` | +5 | Fixture residue patterns, matching the boundary-lint precedent. |
| `tests/Arch/BoundaryLintCoverageTest.php` | +4 / −2 | Re-derived `bin/quality` line numbers (`:225`/`:253` → `:238`/`:266`) and step number. |
| `tests/Feature/Quality/QualityStepCountTest.php` | +5 / −4 | Docblock: the round trip resolved to a real 15. |
| `tests/Feature/Quality/PestNegatedExpectationMessagesTest.php`, `tests/Feature/Finance/PaymentProvenanceTest.php` | +1 / −1 each | "the suite is step 14" → 15. |
| `docs/adr/0053-local-enforcement-floor.md`, `docs/adr/README.md` | 4 sites | Fourteen → Fifteen, Steps 2–14 → 2–15, Thirteen green ticks → Fourteen, the suite step. |
| `docs/handoff/tickets/lint-changed-cannot-see-uncommitted-work.md` | 3 sites | The diff-aware enumeration table renumbered (arch 12→13, larastan 13→14, test-ratchet 14→15, sql-clock inserted at 12). |
| `docs/handoff/tickets/fresh-clone-review-needs-a-built-manifest.md`, `docs/handoff/opening-balance-import-spec.md`, `docs/handoff/finance-mvp-cut-brief.md` | 1 site each | Step 14 → 15. |
| `.claude/skills/finance-context/SKILL.md` | 1 block | 14-step → 15-step, sql-clock at 12, and the moves history corrected to five moves. |
| `docs/handoff/tickets/sql-clock-lint.md` | resolutions | Status CLOSED; a resolution block appended to each of the three defects and to the tests/ question. Original text preserved — the measurements are the asset. |

## Proof

### The measurement that decided defect 1

Bare forms only, keyword requirement dropped, over string literals in PHP and whole `.sql`:

```
=== app database routes bin ===
TOTAL bare-form hits (keyword requirement dropped): 0
=== tests ===
tests/Arch/SqlClockLintCoverageTest.php:157  [CURRENT_DATE]  public const M4 = 'The current_date filter';
TOTAL bare-form hits (keyword requirement dropped): 1
```

Expected: some false-positive rate to trade against. Observed: **zero on the entire scanned
surface**, and the only hit anywhere is the coverage test's own synthetic prose constant — i.e. the
false positive existed only as an example of itself.

### The measurement that decided tests/

The shipped matcher, `SCANNED_DIRS` set to `['tests']` (16 findings; the full output is in the
ticket's resolution block). Distribution:

| Where | Hits | What they are |
|---|---|---|
| `tests/Arch/SqlClockLintCoverageTest.php` | 10 | this lint's own planted fixtures and the strings it asserts on |
| `tests/Feature/Support/SchoolDayTest.php` | 3 | an assertion MESSAGE quoting `now()` as prose; PHP source held in a string so an arch test can scan it |
| `tests/Feature/Finance/CurrencyShapeConstraintTest.php:54` | 2 | the known fixture insert — two `NOW()` on one line |
| `tests/Feature/Finance/SubledgerClockFrameTest.php:69` | 1 | the `SELECT NOW()` probe that exists TO PROVE the two frames differ |

**Decision: `tests/` stays out.** Not one of the 16 is the defect. Scanning it buys a day-one
baseline of permanent exemptions — including exemptions for the lint's own coverage test — and being
free of a baseline is the entire reason this rule was worth adding at zero.
`CurrencyShapeConstraintTest:54` is left exactly as it was.

### The `useCurrent` writers, re-derived not assumed

```
database/migrations/2026_04_26_122249_create_audit_logs_table.php:19  $table->timestampTz('created_at')->useCurrent();
database/migrations/0001_01_01_000002_create_jobs_table.php:44        $table->timestamp('failed_at')->useCurrent();
database/migrations/2026_07_17_100000_create_authz_observations_table.php:34  $table->timestamp('occurred_at')->useCurrent();
```

- `jobs.failed_at` — `vendor/laravel/framework/src/Illuminate/Queue/Failed/DatabaseFailedJobProvider.php:57`, `$failed_at = Date::now();`
- `audit_logs.created_at` — `App\Models\AuditLog` sets `UPDATED_AT = null` only, so Eloquent still supplies `created_at` on create; the only writer in the tree is a seeder.
- `authz_observations.occurred_at` — `app/Support/Authz.php:78`, `'occurred_at' => now(),`

All three write from PHP on every live path, so the DDL default is inert today. They are exempt as
**history** (applied migrations, ADR 0052), not as an argument that the pattern is acceptable.

### The gate

```
quality gate — base 4928064

[1/15] dependency integrity (composer.lock vs composer.json vs vendor/)
   ✓ dependency-integrity-lint
[2/15] wayfinder:generate --with-form (must match vite.config.ts formVariants)
   ✓ wayfinder:generate
[3/15] lint changed files (Pint / Prettier / ESLint, check mode)
   ✓ lint-changed
       Pint: no changed PHP files
       Prettier: no changed frontend files
       ESLint: no changed JS/TS files
[4/15] types (tsc ratchet vs tsc-baseline)
   ✓ tsc-ratchet
[5/15] frontend build (vite — catches what the tsc ratchet structurally cannot)
   ✓ build
[6/15] authorization guard (no new commented-out checks)
   ✓ authz-lint
[7/15] boundary lint (§17.2)
   ✓ boundary-lint
[8/15] grants-convergence lint (a pre-existing permission added to grantsMap() ships a migration)
   ✓ grants-convergence-lint
[9/15] money lint (UI: money via formatNaira, no JS money math)
   ✓ money-lint
[10/15] runtime-zero lint (S7 legacy access sources)
   ✓ runtime-zero-lint
[11/15] identifier-generation bypass guard (1.4b)
   ✓ identifier-generation-lint
[12/15] sql-clock lint (no MySQL clock functions in raw SQL — two frames, one table)
   ✓ sql-clock-lint
[13/15] architecture tests (§17.1)
   ✓ arch
[14/15] static analysis (Larastan level 5 vs baseline)
   ✓ larastan
[15/15] tests (failure ratchet vs tests/ratchet-baseline.txt)
   ✓ test-ratchet

✓ quality: PASS — per-push floor. Promoting to main? run bin/quality-promote.
```

One run, green, no re-run. **Read step 3 rather than the tick**: `Pint: no changed PHP files` is
`lint-changed` being unable to see uncommitted work — the known ticket. Pint was therefore run by
hand over the six changed PHP files (`{"tool":"pint","result":"passed"}`); step 3's green covers
nothing on this branch.

### The step-count sweep

```
grep -n '^\s*step "' bin/quality  →  15 calls
printf '%s[%d/15]%s %s\n'          →  the one literal, in step()
```

Sweep run over `docs/ tests/ bin/ .githooks/ .claude/` **and** `CLAUDE.md CONTRIBUTING.md README.md`
for both digits (`14`, `x/14`, `step 14`) and spelled-out numerals (`twelve|thirteen|fourteen|
fifteen`). Root files: no hits. `.claude/` produced two, both real —
`.claude/skills/finance-context/SKILL.md:162` and `:165` — and that directory was outside every
earlier sweep, which is the shape the brief warned about.

**Two things the sweep found that a step-number search would not have:**

1. **`bin/quality` LINE numbers are cited by two coverage tests** for the runs-sequentially
   argument. `BoundaryLintCoverageTest` said `:225`/`:253`; correct values are now `:238` (arch,
   step 13) and `:266` (plain pest, step 15). Nothing mechanises this.
2. **The `a08ddca` version of that same file was itself wrong** — it had been swept to `:153`/`:161`,
   which are step 1's lines. Re-deriving rather than cherry-picking, as the ticket instructed, is
   what caught it.

**What I deliberately did NOT sweep**, stated because it looks like a miss: `PASS 14/14` in ADR
0053's A/B/C table, the five sites in `docs/handoff/u1-fee-schedules-brief.md`, and every hit under
`docs/handoff/reports/`. Those record runs that happened against a 14-step gate. Rewriting them
falsifies history. Only claims about the CURRENT gate were moved. One borderline call:
`lint-changed-cannot-see-uncommitted-work.md:85` narrates a specific past incident ("scrolled past
inside fourteen steps of gate output") and was left; `:91` and `:108` state properties of the gate
today and were moved.

## The watched red

Four planted regressions, each run against the lint **as ported from `a08ddca`** and against this
branch's. Every plant was removed and the tree confirmed green after.

### Defect 1 — bare forms in `*Raw` / `DB::raw`

Planted `app/Finance/SqlClockLintProbe.php` with `whereRaw('due_date < CURRENT_DATE')`,
`orderByRaw('CURRENT_TIMESTAMP - posted_at')`, `selectRaw('CURRENT_DATE AS today')` and
`'updated_at' => DB::raw('LOCALTIMESTAMP')`.

```
----- BEFORE (lint as ported from a08ddca) -----
sql-clock-lint: OK — no SQL-side clock reads (1 named exception(s)).
exit=0
----- AFTER (this branch) -----

sql-clock-lint: 4 SQL-side clock read(s) / frame conversion(s) / server-clock column default(s) — MySQL's clock and frame are not the application's:
  ✗ app/Finance/SqlClockLintProbe.php:12  [CURRENT_DATE — clock-read]  ->whereRaw('due_date < CURRENT_DATE')
  ✗ app/Finance/SqlClockLintProbe.php:13  [CURRENT_TIMESTAMP — clock-read]  ->orderByRaw('CURRENT_TIMESTAMP - posted_at')
  ✗ app/Finance/SqlClockLintProbe.php:14  [CURRENT_DATE — clock-read]  ->selectRaw('CURRENT_DATE AS today')
  ✗ app/Finance/SqlClockLintProbe.php:21  [LOCALTIMESTAMP — clock-read]  'updated_at' => DB::raw('LOCALTIMESTAMP'),
exit=1
----- plant removed, AFTER lint back to green -----
sql-clock-lint: OK — no SQL-side clock reads (4 named exception(s)).
exit=0
```

The message names the token and the rule class, and the fourth line is the original money defect
re-spelled — `LOCALTIMESTAMP` is an exact synonym of `NOW()` writing the projection column.

### Defect 2 — the whole-gate false green, against the REAL exempted migration

Inserted `SELECT * FROM (SELECT UTC_TIMESTAMP) x WHERE 1=0 UNION ALL` **above** the exempted line,
inside the same string literal, in
`database/migrations/2026_07_22_190000_create_finance_student_accounts.php`.

```
----- BEFORE (lint as ported from a08ddca) -----
sql-clock-lint: OK — no SQL-side clock reads (1 named exception(s)).
exit=0
----- AFTER (this branch) -----

sql-clock-lint: 1 SQL-side clock read(s) / frame conversion(s) / server-clock column default(s) — MySQL's clock and frame are not the application's:
  ✗ database/migrations/2026_07_22_190000_create_finance_student_accounts.php:83  [UTC_TIMESTAMP — clock-read]  SELECT * FROM (SELECT UTC_TIMESTAMP) x WHERE 1=0 UNION ALL
exit=1
----- migration restored -----
sql-clock-lint: OK — no SQL-side clock reads (4 named exception(s)).
exit=0
```

Note the count: **one** finding, not two. The exempted `NOW(), NOW()` line at `:84` is still exempt,
so the fix restored the guarantee without weakening the exemption. Migration restored via
`git checkout --`; `git status` clean for that path afterwards.

### Defect 3 — `->useCurrent()` / `->useCurrentOnUpdate()`

```
----- BEFORE (lint as ported from a08ddca) -----
sql-clock-lint: OK — no SQL-side clock reads (1 named exception(s)).
exit=0
----- AFTER (this branch) -----

sql-clock-lint: 2 SQL-side clock read(s) / frame conversion(s) / server-clock column default(s) — …
  ✗ app/Finance/SqlClockLintProbe.php:13  [useCurrent — ddl-default]  $table->timestamp('posted_at')->useCurrent();
      useCurrent compiles to a CURRENT_TIMESTAMP column default, so the SERVER's clock writes the column whenever the application does not
  ✗ app/Finance/SqlClockLintProbe.php:14  [useCurrentOnUpdate — ddl-default]  $table->timestamp('changed_at')->useCurrentOnUpdate();
exit=1
```

### The new exceptions are keyed to a LINE, not a file

Two mutations of `database/migrations/0001_01_01_000002_create_jobs_table.php`, both against this
branch's lint:

```
########## A SECOND useCurrent IN AN EXEMPTED FILE
  ✗ database/migrations/0001_01_01_000002_create_jobs_table.php:45  [useCurrent — ddl-default]  $table->timestamp('noticed_at')->useCurrent();
exit=1

########## THE EXEMPTED LINE REWORDED (added ->nullable())
  ✗ database/migrations/0001_01_01_000002_create_jobs_table.php:44  [useCurrent — ddl-default]  $table->timestamp('failed_at')->nullable()->useCurrent();
exit=1
```

Both restored; lint green.

### The coverage arms themselves, watched red

The strongest of the five, because it proves the ARMS are load-bearing and not just the lint. Same
test file, `bin/ci-sql-clock-lint.php` swapped for the `a08ddca` version and back:

```
########## coverage test run against the PRE-FIX matcher
{"tool":"pest","result":"failed","tests":9,"passed":5,"failed":4, …
  · it_reports_a_BARE_clock_form_inside_a__Raw_fragment…      Failed asserting that 0 is identical to 1.
  · it_reports_a_bare_token_in_NON_SQL_prose_too…             Failed asserting that 0 is identical to 1.
  · it_reports_EVERY_clock_read_in_one_string_literal…        …contains "SqlClockLintFixtureQpiZTX6d.php:13".
  · it_reports___useCurrent___and___useCurrentOnUpdate__…     Failed asserting that 0 is identical to 1.

########## restored — same test file, fixed matcher
{"tool":"pest","result":"passed","tests":9,"passed":9,"assertions":40}
```

The third failure message is the mechanism in one line: the pre-fix matcher's output contains
`:14` **twice** and `:13` never — first-listed instead of first-occurring, exactly as the ticket
described.

## Database observations

None. This change touches no schema, no data and no query. The three exempted columns were reasoned
about from their writers in source, not from row counts; no database was read.

## Not done

- **`.sql` file scanning has still never run against a real file.** There are zero `.sql` files in
  the scanned directories. It is exercised only by the planted coverage arm. Unchanged from the
  ticket, restated so a green is not over-read.
- **The token-blind half is still a reading exercise.** A cross-frame comparison that names no clock
  function is invisible to this lint and always will be. The hand survey of all 57 raw-SQL entry
  points in `app/` was **not** re-run on this branch — it was run at the commit that added the lint,
  and `app/` has moved since. If that matters it is a fresh survey, not a re-read of this report.
- **The residual false positive is unmeasured against future code, by definition.** I measured that
  nothing in the tree pays it today. I cannot measure what an author writes next. An array key
  `'current_time' => now()` is the shape most likely to hit it.
- **`QualityStepCountTest` still cannot see prose.** The documentation half of the step count stays
  unmechanised, and this branch moved eleven prose sites by hand. Whether that is worth a lint is a
  separate decision I did not take.
- **Choices I made rather than escalated:** dropping the keyword requirement rather than widening to
  the enclosing call; scanning `useCurrent` across all four directories rather than `database/`;
  exempting the three sites rather than converting the columns; leaving dated records unswept. Each
  is argued above; each is reversible.

## Findings raised, not fixed

- `tests/Arch/BoundaryLintCoverageTest.php:29` — **ticket.** The `bin/quality` line numbers this
  file cites are load-bearing for its sequential-run argument and are pinned by nothing. They were
  wrong on `a08ddca` and stale on `staging`. `SqlClockLintCoverageTest` now carries the same
  exposure. A test asserting `bin/quality:<N>` matches `pest --group=arch` would close both.
- `bin/quality:177` (step 3, `lint-changed`) — **ticket, already open.** Its green means nothing for
  uncommitted work, and it prints `Pint: no changed PHP files` while sitting next to a tick. On this
  branch that reads as coverage and is not. Existing ticket:
  `docs/handoff/tickets/lint-changed-cannot-see-uncommitted-work.md`.
- `docs/handoff/reports/fix-subledger-single-clock-frame.md:240` — **ticket.** Points at
  `docs/handoff/tickets/sql-clock-lint-blind-to-usecurrent.md`, which is deleted. Harmless (the same
  line says it was folded), noted so it is not mistaken for a live pointer.
