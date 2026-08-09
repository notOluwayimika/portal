# Implementation report — the gate is nondeterministic

## Headline

**The cause was NOT found.** I disproved four hypotheses — including yours and my own — and could not
reproduce the failure in eleven further runs. What ships is the limitation, recorded in ADR 0053 with
its evidence, plus the one change that makes the next occurrence diagnosable instead of unexplainable.

Branch `fix/nondeterministic-test-suite`, base `c5c6061` (`origin/staging`; #226 and #227 merged).

This is the honest version of the outcome the brief anticipated: *"if it does not [land a fix], the
limitation still ships, because an honest floor that names its own hole is worth more than a silent
one."*

## Corrections to things I said in this investigation

Two of my own claims were wrong and were stated confidently before being measured. Both are here
rather than buried, because the reasoning that produced them is the failure mode this week has been
about.

1. **I reported the cause as a leaked `innodb_lock_wait_timeout` and it is not.** See *Hypothesis 3*.
2. **I described the 23 failures as a contiguous alphabetical tail.** They are not —
   `SuperAdminGateTest` and `SuperAdminMatrixTest` pass *between* failing files, and most files after
   `Sequences/` pass. The "poisoned from here on" shape I inferred does not exist.

I also made two operational mistakes worth recording: I ran `--group=arch` against `portal_testing`
while the experiment was in flight, and I left a probe test file in `tests/` during two runs (which
is why `inherited-2` and `inherited-3` report 1493 tests, not 1492 — the failure sets are unchanged).
Neither changed a conclusion, and both were careless.

## Your premises — both confirmed verbatim

| Premise | Result |
|---|---|
| `tests/Pest.php:27` has `RefreshDatabase` commented out globally | ✓ — `// ->use(RefreshDatabase::class)` |
| `bin/quality` runs pest twice against the same database | ✓ — `:206` `--group=arch`, `:214` full suite, both `DB_DATABASE=portal_testing` |

## Hypothesis 1 — inherited database state. DISPROVEN

The table you asked for first, and it already undermines the mechanism: **all eight failing files opt
into `RefreshDatabase`.** They are wrapped in a rolled-back transaction each, so they are victims of
whatever happened, not carriers of it.

| File | Failures in run B | `RefreshDatabase`? |
|---|---|---|
| `Rbac/SuperAdminAuthorityTest` | 6 | YES |
| `Rbac/SharedPermissionsTest` | 5 | YES |
| `Rbac/SeededPermissionCoverageTest` | 5 | YES |
| `Rbac/TwoFactorEnrollmentTest` | 1 | YES |
| `Rbac/SuperAdminBypassExclusionTest` | 1 | YES |
| `Rbac/SchoolUserModuleTest` | 1 | YES |
| `Sequences/IdentifierGeneratorTest` | 2 | YES |
| `Students/StudentIndexFilterTest` | 2 | YES |

Only 11 of 205 Feature files skip it, and of those exactly one writes to the database at all.

**The experiment, six runs.** `db:wipe` before each run (the app's own credentials — I have no mysql
root) versus no wipe:

| Condition | Runs | Tests | Failures | Failure-set md5 |
|---|---|---|---|---|
| fresh | 3 | 1492 | 7 | `f167633e` |
| inherited | 3 | 1492 / 1493 | 7 | `f167633e` |

**Every run produced a byte-identical failure set**, and all seven are exactly the
`tests/ratchet-baseline.txt` entries — the baseline is calibrated to this set.

The structural reason the conditions were never different: `RefreshDatabase` runs `migrate:fresh`
**once per process** (`RefreshDatabaseState::$migrated`), so every pest invocation already begins from
a wiped database regardless of what the previous one left. Wiping first changes nothing. The same
static is shared with `DatabaseTruncation`
(`vendor/laravel/framework/src/Illuminate/Foundation/Testing/DatabaseTruncation.php:32-37`), so
whichever runs first migrates and the other does not.

## Hypothesis 2 — the double pest invocation. RULED OUT

`--group=arch` resolves to exactly three files, none of which touches the database: 23 tests in 2.3s,
no migration. The two-invocation structure is real and inert.

## Hypothesis 3 — a leaked session variable. DISPROVEN, and this was my claim

`tests/Feature/Finance/AccountPaymentConcurrencyTest.php:128` sets
`SET innodb_lock_wait_timeout = 2` on the **default** connection, where its five siblings set it on a
throwaway `$second` connection that `afterEach` purges. It sorts before every one of the 23 failing
files. It looked conclusive, and I reported it as the cause.

It is not. A probe reading `@@innodb_lock_wait_timeout` on the default connection:

```
A: probe alone                                    PROBE innodb_lock_wait_timeout = 50
B: AccountPaymentConcurrencyTest, then the probe   PROBE innodb_lock_wait_timeout = 50
```

Laravel rebuilds the application — and therefore the connections — per test, so session variables do
not survive a test boundary.

**And the asymmetry is not even a defect.** On re-reading, that test needs the short timeout on the
default connection because the default connection is the one that WAITS there (`default = the REAL
account payment … it must WAIT`); the siblings put the waiting operation on `$second`. Different by
necessity. Nothing to ticket.

## Hypothesis 4 — test ordering. RULED OUT

PHPUnit sorts test files (`vendor/phpunit/php-file-iterator/src/Facade.php:48`,
`sort($files)`), and `phpunit.xml` sets no `executionOrder`. Order is deterministic and alphabetical.
Also ruled out: Spatie's permission cache resolves to `array` under test (`phpunit.xml`), so it is
per-process, not a cross-run leak.

## Reproduction attempts

| Attempt | Runs | Result |
|---|---|---|
| Full suite, fresh database | 3 | identical, baseline only |
| Full suite, inherited database | 3 | identical, baseline only |
| Full `bin/quality` | 4 | **PASS ×4** |
| Full suite under saturating CPU load | 1 | abandoned — killed at 10 minutes, no verdict |

**One observed failure in twelve runs.** The load run was a bad experiment: I spawned sixteen
unbounded busy loops with no timeout, it was killed before finishing, and it produced nothing. The
machine was left at a load average of 64 until I killed them. Recorded because it was careless, not
because it informed anything.

## What actually blocked the diagnosis, and the one thing this commit fixes

Every theory above was built from the **names** of the failing tests and their alphabetical order. I
never read a failure *message* from the original 23, because `bin/quality` wrote its junit and suite
output to fixed paths (`/tmp/quality-junit.xml`, `/tmp/quality-pest.log`) and **run C overwrote run
B's evidence before anyone could look at it.**

A gate that destroys the record of its own red is a gate whose reds can only be retried, never
investigated. `bin/quality` step 14 now stamps both artefacts per run, keeps the last 20, and points
at them on a red:

```
This run's suite output: <TMPDIR>/quality-runs/pest-<stamp>-<pid>.log
This run's junit:        <TMPDIR>/quality-runs/junit-<stamp>-<pid>.xml
Kept for the last 20 runs. A red you cannot reproduce is only diagnosable from these.
```

The fixed paths remain as symlinks to the newest run, because `bin/ci-test-ratchet.php`, the docs and
habit all refer to them.

This does not make the suite deterministic. It makes the next occurrence something a person can read.

## The RefreshDatabase question — not reached, so the fuse did not fire

The brief said to stop and report the runtime cost before re-enabling `RefreshDatabase` suite-wide.
That was contingent on the database-state hypothesis, which is disproven, so re-enabling it would
close nothing measured here. I did not change `tests/Pest.php`. For the record, the number that would
have mattered: a full suite run is **~350–440s** across the twelve runs above.

## What changed

| File | What |
|---|---|
| `bin/quality` | Step 14 stamps junit + suite log per run, keeps 20, symlinks the fixed paths, prints them on a red. |
| `docs/adr/0053-local-enforcement-floor.md` | A fifth residual: **the floor is not deterministic**, with the A/B/C table, the four disproven hypotheses, and the twelve-run count. |
| `CLAUDE.md` | The mirrored residuals table gains the same row (ADR 0053's Consequences already establishes the two move together). |

## Proof

Three `bin/quality` runs on the fixed tree — pasted raw below. Note what three greens are and are not:
on a gate with a ~1-in-12 observed failure rate, three passes are **consistent with** the flake still
being present. They are not evidence it is gone, and this commit does not claim to have removed it.

**Run 1**

```
quality gate — base c5c6061

[1/14] dependency integrity (composer.lock vs composer.json vs vendor/)
   ✓ dependency-integrity-lint
[2/14] wayfinder:generate --with-form (must match vite.config.ts formVariants)
   ✓ wayfinder:generate
[3/14] lint changed files (Pint / Prettier / ESLint, check mode)
   ✓ lint-changed
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

**Run 2**

```
quality gate — base c5c6061

[1/14] dependency integrity (composer.lock vs composer.json vs vendor/)
   ✓ dependency-integrity-lint
[2/14] wayfinder:generate --with-form (must match vite.config.ts formVariants)
   ✓ wayfinder:generate
[3/14] lint changed files (Pint / Prettier / ESLint, check mode)
   ✓ lint-changed
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

**Run 3**

```
quality gate — base c5c6061

[1/14] dependency integrity (composer.lock vs composer.json vs vendor/)
   ✓ dependency-integrity-lint
[2/14] wayfinder:generate --with-form (must match vite.config.ts formVariants)
   ✓ wayfinder:generate
[3/14] lint changed files (Pint / Prettier / ESLint, check mode)
   ✓ lint-changed
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

## Not done

- **The cause is unknown.** Four hypotheses disproven, no reproduction in eleven attempts.
- **No fix for the nondeterminism itself**, because there is nothing measured to fix. A speculative
  change here would be a change to every test in the repo, justified by a theory I could not confirm.
- **The 23 failures' messages are permanently lost.** They existed only in a file the next run
  overwrote — which is the defect this commit closes, one occurrence too late.
- **The load hypothesis is untested**, not disproven. It is the only class left standing, and a
  properly bounded version of that experiment is the obvious next step for whoever picks this up.
