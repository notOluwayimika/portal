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
in `tests/`". Running the shipped matcher over `tests/` returns **27**. The ticket's figure was a
hand survey for `NOW()` in a raw-insert shape; the matcher also sees assertion messages, PHP source
held in strings, and this lint's own planted fixtures. The extra 26 are all false positives — which
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

The shipped matcher, `SCANNED_DIRS` set to `['tests']` and `$root` pinned to the repo root, over
`tests/` **as it stands at the shipping commit — i.e. including this branch's own four new coverage
arms**. **27 findings.** Distribution:

| Where | Hits | What they are |
|---|---|---|
| `tests/Arch/SqlClockLintCoverageTest.php` | 21 | this lint's own planted fixtures and the strings it asserts on |
| `tests/Feature/Support/SchoolDayTest.php` | 3 | an assertion MESSAGE quoting `now()` as prose; PHP source held in a string so an arch test can scan it |
| `tests/Feature/Finance/CurrencyShapeConstraintTest.php:54` | 2 | the known fixture insert — two `NOW()` on one line |
| `tests/Feature/Finance/SubledgerClockFrameTest.php:69` | 1 | the `SELECT NOW()` probe that exists TO PROVE the two frames differ |

*(The first version of this report said **16**, with 10 in the coverage test. That count was taken
before this branch's own coverage arms landed and was then written into a permanent docblock — the
same carried-number failure this report corrects the ticket for one section above. Corrected in the
lint, the ticket and here. The extra 11 are all in the coverage test's new fixture strings, so the
categories and the decision are untouched.)*

**Decision: `tests/` stays out.** Not one of the 27 is the defect. Scanning it buys a day-one
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
fifteen`). **Root files: one hit, found and deliberately left** — `CLAUDE.md:136` carries
`PASS 14/14` inside the ADR 0053 determinism row, which records a specific pair of runs against a
14-step gate. It is a dated record, the same class preserved in ADR 0053's own A/B/C table.
*(An earlier version of this line said "Root files: no hits." That was wrong, and the distinction
matters: a found-and-deliberately-left hit and a hit that was never seen read identically from
"no hits", and only the second means the sweep was incomplete.)* `.claude/` produced two, both real —
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

---

# Remediation round — the cold review's four findings

Appended after the subagent review. Each finding was **re-verified against the tree and the
databases before acting**; the brief that requested this remediation was written without repo
access and said so, so nothing below rests on its restatement.

## One restated claim did NOT reproduce, and the correction is load-bearing

The brief (following the reviewer) said `explicit_defaults_for_timestamp` is **"OFF on the
production copy and ON on this host"**. Re-derived from `performance_schema.global_variables`:

```
DB=portaa10_portal  version=8.0.43
  explicit_defaults_for_timestamp = ON
  system_time_zone = WAT
  time_zone = SYSTEM
```

The production copy **is** on this host and reports **ON**. So the setting cannot be read as OFF
anywhere reachable from here, and a ticket asserting it would have been a carried number of its own.

**The finding it supports survives intact, on better evidence.** Same migration, two databases on
this one machine:

```
                  notices.starts_at  —  $table->timestampTz('starts_at');

portaa10_portal   timestamp NOT NULL  default='CURRENT_TIMESTAMP'
                                      extra=DEFAULT_GENERATED on update CURRENT_TIMESTAMP
portal_testing    timestamp NOT NULL  default=NULL   extra=
```

`portal_testing` is freshly migrated here, under `ON`, and gets no implicit default.
`portaa10_portal` is a **copy of production** and carries the DDL its `notices` table was created
with, on a server where the setting was OFF. The evidence for OFF is therefore the *column
definition*, not a variable reading — which is a sharper statement of the point than the original,
because it shows the divergence directly instead of inferring it.

That distinction is written into the new ticket rather than smoothed over.

## What each finding reproduced as

**Finding 1 — reproduces exactly.** Four columns, not three:

```
  audit_logs           created_at   default=CURRENT_TIMESTAMP  extra=DEFAULT_GENERATED
  authz_observations   occurred_at  default=CURRENT_TIMESTAMP  extra=DEFAULT_GENERATED
  failed_jobs          failed_at    default=CURRENT_TIMESTAMP  extra=DEFAULT_GENERATED
  notices              starts_at    default=CURRENT_TIMESTAMP  extra=DEFAULT_GENERATED on update CURRENT_TIMESTAMP
```

`bin/ci-sql-clock-lint.php` said "the tree is at zero in BEHAVIOUR as well as in tokens". False, and
false in the gate file itself. Corrected.

**Finding 2 — reproduces exactly.** 27, not 16: 21 / 3 / 2 / 1 across the same four files.

**Finding 3 — reproduces exactly.** `CLAUDE.md:136` carries `PASS 14/14`.

**Finding 4 (notices) — the code reproduces; the behaviour is still unobserved.**
`NoticeController.php:206-209`, `routes/endpoints/notice.php:14` and the migration line are all as
described.

## Fix 1 — the claim is corrected, and `ddl-default` was NOT grown

**`scanUseCurrent()` is unchanged.** Extending it was rejected on the reviewer's own reasoning: the
implicit default is created by the **server**, from a declaration byte-identical to the safe case,
so a source-reading check can never distinguish them. A rule that tried would have to flag every
bare `$table->timestamp(...)` in the repository — refusing safe code, which is the failure mode this
lint's design history spent three rounds removing.

What changed instead:

- `bin/ci-sql-clock-lint.php` — the exemption block now asserts zero **in tokens** and says plainly
  that behaviour is not observable from source. The old sentence is quoted as having been false
  rather than deleted, so a reader who saw it can tell it was corrected.
- Its **"WHAT IT CANNOT SEE"** block gained the implicit-default origin as a second, numbered item,
  with the two-database observation inline — that block is the one place a reader looks before
  trusting a green.
- `docs/handoff/tickets/sql-clock-lint.md` — the defect-3 resolution carries the same correction.

**New ticket: `docs/handoff/tickets/server-settings-the-code-cannot-see.md`.** The class, with two
members — the session time zone (member 1, `stored-epoch-offset.md`) and
`explicit_defaults_for_timestamp` (member 2). One shape: a server-level MySQL variable that differs
between environments, changes what the database does with a `TIMESTAMP`, and is invisible to every
source-reading gate. It records that the only instrument that could observe either is a
schema-level check against a live database, which `bin/quality` is not; it names all four
declaration sites; and it cross-references `stored-epoch-offset.md`, which now links back.

**The finance declaration, and why it is benign for a reason.**
`2026_08_09_120000_finance_capture_columns_s2_s3.php:74` declares
`$table->timestamp('posted_at')->after('narration')` on `finance_ledger_transactions` — exactly the
shape that acquires the implicit default. Three independent reasons, each re-derived:

1. **`ON UPDATE` cannot fire.** From `information_schema.TRIGGERS`:
   `finance_ledger_transactions_no_update  (UPDATE)` and
   `finance_ledger_transactions_no_delete  (DELETE)`. The append-only triggers refuse the UPDATE
   with SQLSTATE 45000 before any `ON UPDATE CURRENT_TIMESTAMP` could rewrite the column. The
   data-destroying half is structurally unreachable on this table.
2. **The `DEFAULT` is moot** — every writer supplies the column:
   `app/Finance/Services/SubledgerPoster.php:113` (`'posted_at' => $postedAt`) and
   `app/Finance/Actions/PostOpeningBalanceBatch.php:310` (`'posted_at' => now()`).
3. **On this host it carries no default at all** — `default=NULL, extra=''` on `portaa10_portal`,
   because the migration ran here under `ON`.

Reasons 1 and 2 hold on production too. Reason 3 would not, and the ticket says so.

## Fix 2 — the count, corrected with its provenance

16 → **27** in all three places (the lint docblock, the ticket resolution, the report section
above). Each now states **what it counts** (findings this matcher reports with
`SCANNED_DIRS = ['tests']`) and **when it was taken** (at the shipping commit, including this
branch's own coverage arms), plus the command to reproduce it — so a different figure later reads
as drift or as a different question rather than as a contradiction.

The wrong figure is recorded as having been wrong, not silently overwritten. **The decision does not
move**: it rests on the structural argument, and the 11 extra hits are all in the coverage test's
new fixture strings.

## Fix 3 — "Root files: no hits", corrected

The sweep **did** hit `CLAUDE.md:136`, and leaving it was right — it is a dated record of two
specific runs, the same class as ADR 0053's A/B/C table. The report now says found-and-left, with
the reason. A found-and-deliberately-left hit and a hit never seen read identically from "no hits",
and only the second means the sweep was incomplete.

## Not fixed here — the notices bug, filed

**`docs/handoff/tickets/notice-end-destroys-starts-at.md`.** Outside Finance, and it does not belong
in a lint commit. Filed with the full derivation and with **"DERIVED, NOT OBSERVED"** in its status
line and again as its first heading: the **first task on whatever branch takes it is to reproduce
it against the production copy's schema**, not to fix it. A fix for an unproven cause is a guess
with a commit message — and the reproduction has to run against the copy specifically, because
`portal_testing` does not carry the `ON UPDATE` clause and the bug cannot occur there.

The ticket flags that it is a **live route** (`POST /notices/{notice:uuid}/end`) touching **data a
user typed**, with the suspected loss silent and unrecoverable, and that scheduling urgency is the
project lead's call. Both candidate fixes are named with the trade-off; neither is applied.

## A limit of the review, recorded as one

**The cold review did not run `bin/quality` end to end.** `dependency-integrity`,
`wayfinder:generate`, `tsc-ratchet`, the vite `build` and `larastan` — five of fifteen steps — rest
on my transcript alone and were not independently reproduced. That is a limit of the review, not a
defect in the work, and it is recorded here so the next reader does not read "reviewed" as "every
gate step re-run by a second party". The reviewer did independently reproduce defect 2's bite-proof
in an isolated sandbox, re-derive every number in the report, and confirm the exemption writers.

Its other stated limits, carried forward verbatim in substance: it did not read production; every
database fact in it is from `portaa10_portal` and `portal_testing`; and finding 4's `ON UPDATE`
firing is derived from the column definition and documented MySQL semantics, not observed. That last
one is now the first line of the ticket it produced.

## Gate, remediation round — raw, 15 steps

```
quality gate — base 4928064

[1/15] dependency integrity (composer.lock vs composer.json vs vendor/)
   ✓ dependency-integrity-lint
[2/15] wayfinder:generate --with-form (must match vite.config.ts formVariants)
   ✓ wayfinder:generate
[3/15] lint changed files (Pint / Prettier / ESLint, check mode)
   ✓ lint-changed
       Pint (check) on 6 changed PHP file(s)
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

One run, green, no re-run. **Step 3 now reads `Pint (check) on 6 changed PHP file(s)`** rather than
the `no changed PHP files` of the first round — the earlier commit landed, so `lint-changed` can see
those files against the base. That is the same limitation reported in the first round, seen from the
other side: its green covers committed work only, and this round's uncommitted edits were again
Pint-checked by hand (`{"tool":"pint","result":"passed"}`) before the run.

`tests/` count re-confirmed at this commit: **27** (21 / 3 / 2 / 1), matching what is now written in
the lint, the ticket and this report.

---

# Addendum — the exhaustive reading, and the notices ticket un-stale

Two items, both re-derived here rather than taken from the addendum brief.

## 1. The completeness claim now rests on a schema-wide query

The point is accepted and was a real gap: the previous round verified **four declaration sites it
already knew about**, which establishes those four and says nothing about a fifth. Re-run against
the production copy, 2026-08-13 (query proposed by the project lead):

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

**Exactly one row.** Reproduces. Written into the ticket as the exhaustive reading, dated and
attributed.

**One addition to it.** Re-run **without** the `NOT IN ('updated_at')` carve-out, the query still
returns **1** — no `updated_at` column in this schema carries `ON UPDATE CURRENT_TIMESTAMP` at all,
because Laravel's `timestamps()` emits nullable columns and the implicit rule does not touch those.
Worth recording: it means the exclusion is hiding nothing, which is what makes the one-row result a
completeness claim rather than a filtered one.

### A departure — "LATENT: three" is not the three exempted columns

The addendum's restructure had the LIVE row plus **the three exempted `->useCurrent()` columns** as
the latent set. That does not hold, and folding them in would have made the ticket wrong in a way
that reads plausible:

- They carry `DEFAULT CURRENT_TIMESTAMP` **by design**, written by `->useCurrent()` in their
  migrations — not by the server.
- They are **not environment-dependent**: a freshly-migrated `portal_testing` on this host carries
  exactly the same three and no others.
- They carry **no `ON UPDATE` clause**, so nothing rewrites them.

Nothing about them is latent; they are the *explicit* set, and the ticket now says so under its own
heading.

**The genuinely latent set is a SOURCE-side count**, because the property is of the migration rather
than of any schema: `NOT NULL` `TIMESTAMP` columns declared with no default, which materialise with
`DEFAULT … ON UPDATE …` wherever the DDL is created under the other setting. Measured over
`database/migrations/` (excluding `->nullable()`, `->useCurrent()`, and the `timestamps()` helpers):
**four declarations of the shape**, of which one — `notices.starts_at` — has already materialised.

| Declaration | Migration | State |
|---|---|---|
| `$table->timestampTz('starts_at')` | `2026_06_27_000001_create_notices_tables.php:32` | already materialised — the LIVE row |
| `$table->timestamp('posted_at')->after('narration')` | `2026_08_09_120000_finance_capture_columns_s2_s3.php:74` | latent |
| `$table->timestampTz('expires_at')` | `2026_08_04_140000_create_notification_actions.php` | latent |
| `$table->timestampTz('registration_deadline')` | `2026_04_26_120713_create_curricula_table.php` | latent |

So **three remain latent** — the same number as proposed, and a **different three**. The ticket
flags the coincidence explicitly so a later reader does not merge the two sets back together.

## 2. The notices ticket, un-staled

**The attribute is now OBSERVED** — `COLUMN_DEFAULT = CURRENT_TIMESTAMP`,
`EXTRA = DEFAULT_GENERATED on update CURRENT_TIMESTAMP`, read off the copy. **The code-path
consequence stays DERIVED**: nobody has ended a notice and watched the value move. The ticket now
carries the two as a two-row table with separate evidence and separate status, so they cannot
collapse into one claim.

**Damage assessment recorded — real defect, no victims.** Reproduced under the privacy rule:

```
notices rows = 3
  notice#1  school#1  edited=no    starts_at − created_at = -130,185 s
  notice#2  school#1  edited=no    starts_at − created_at =      -171 s
  notice#3  school#1  edited=yes   starts_at − created_at = -911,310 s
                                   starts_at − updated_at = -912,844 s
```

One notice has ever been edited and its `starts_at` is untouched — 911,310 s before its own
`created_at`, a human back-date. Had `ON UPDATE` fired it would sit at roughly
`updated_at + 19,800` (the session offset); it sits 912,844 s behind. **The clause has never fired
on any row that exists.**

The ticket also now records *why*, so it does not read as luck: `NoticeController::update()`
(`:170-175`) sends `starts_at`, so a normal edit assigns the column and `ON UPDATE` does not apply.
Only `end()` omits it. And it records what the data cannot show — whether `end()` was ever called on
a row since deleted.

**Ownership named:** `fix/notices-starts-at-server-clock`, and the ticket closes when that merges.
The reproduction is no longer a blocking prerequisite, since the cause is established rather than
guessed; what remains is the route's effect, which that branch's arm covers.

### What I could NOT verify about the fix branch, and it is in the ticket

`fix/notices-starts-at-server-clock` **exists locally**, but
`git log origin/staging..fix/notices-starts-at-server-clock` returned **nothing** and its working
tree was clean — so on this machine it carries **no commits over `staging`**. The migration, the
code change and the arm are either uncommitted or live elsewhere. The ticket names the owner and
carries that caveat in a block quote, because "owned" and "written and waiting" are different
states and only the first is supported from here.

### And a branch-state note about this session

**HEAD was on `fix/notices-starts-at-server-clock` at the start of this addendum**, not on
`fix/sql-clock-lint-v2`. The working tree was clean, nothing was written while it was checked out,
and the first write of this round came after switching back — verified `d42812f` still at the tip of
`fix/sql-clock-lint-v2` before editing. Recorded because a reader reconstructing this branch's
history from reflog would otherwise find an unexplained checkout in the middle of it.
