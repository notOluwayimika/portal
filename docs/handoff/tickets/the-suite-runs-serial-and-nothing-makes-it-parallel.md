# TICKET — the suite runs serial, nothing makes it parallel, and the ratchet is both why that is measurable and why it is dangerous

**Status:** open, not implemented. This ticket **schedules** a measurement; it does not perform it. No
suite run was made for it. Every number below was read out of the tree or out of run artefacts that
already existed on disk.

The proposal is not "turn on `--parallel`". It is: find out what the suite's execution order is
currently hiding, using parallelisation as the instrument, and decide afterwards whether the speed is
worth taking. Those are two decisions and this ticket only asks for the first.

## The current state, measured

**Nothing in the repository runs the suite in parallel.** `bin/quality` invokes Pest twice, neither
with `--parallel`:

```
$ grep -n "pest" bin/quality
179:# 4. tsc ratchet — pest/tsc may exit non-zero; the RATCHET script is the gate.
238:check "arch" env DB_DATABASE=portal_testing ./vendor/bin/pest --group=arch
263:QUALITY_LOG="$QUALITY_ARTEFACTS/pest-$QUALITY_RUN_ID.log"
266:env DB_DATABASE=portal_testing ./vendor/bin/pest --log-junit "$QUALITY_JUNIT" >"$QUALITY_LOG" 2>&1 || true
272:ln -sf "$QUALITY_LOG" /tmp/quality-pest.log
276:ls -1t "$QUALITY_ARTEFACTS"/pest-*.log 2>/dev/null | tail -n +21 | xargs -r rm -f
```

`bin/quality:266` is the full suite — step 15, per the `# 15.` header comment at `bin/quality:243`.
`bin/quality:238` is the `--group=arch` run, under `step "architecture tests (§17.1)"` at `:237`;
it sits between the `# 12.` header at `:224` and the `# 15.` at `:243` and carries no numbered
header comment of its own, so no step number is asserted for it here.

**There is no paratest.** The only `--parallel` tokens in `composer.json` belong to Pint:

```
$ grep -n "parallel" composer.json
68:            "pint --parallel"
71:            "pint --parallel --test"

$ grep -n -i "paratest" composer.json
(no paratest)
```

So `./vendor/bin/pest --parallel` is not merely unused — the dependency that would make it work is not
installed. Step 0 of the work below is `composer require --dev brianium/paratest`, and that alone is a
change to `composer.json` that this docs-only branch does not make.

## The database guard is NOT an obstacle — correcting an assumption before anyone re-derives it

`tests/TestCase.php:18-30` refuses to boot against a database whose name does not match `/test/i`:

```php
$database = $_SERVER['DB_DATABASE'] ?? $_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE') ?: null;

if (! is_string($database) || preg_match('/test/i', $database) !== 1) {
    throw new \RuntimeException(sprintf(
        'Refusing to run tests against database [%s]: the test database name must contain "test".',
        var_export($database, true),
    ));
}
```

The name is pinned twice — `phpunit.xml:43` sets `DB_DATABASE=portal_testing`, and `bin/quality:266`
sets it again in the process environment.

**This guard does not block a parallel run.** Laravel's parallel testing gives each token its own
database named `<name>_test_<token>` — `portal_testing_test_1`, `portal_testing_test_2`, and so on.
Every one of those contains `test`, so every one satisfies `preg_match('/test/i', ...)`. State this
plainly because it looks like a blocker and is not: the guard is a name check, and the token naming
scheme happens to be built out of the same word.

## The real obstacle: 24 test files with no database isolation trait

`RefreshDatabase` is **not applied globally**. `tests/Pest.php:27-29` has it commented out:

```php
pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');
```

It is applied per-file instead, and 24 files do not apply it:

```
$ find tests -name "*Test.php" | wc -l
     223

$ grep -rl "RefreshDatabase" tests --include="*Test.php" | wc -l
     199

$ grep -rl "DatabaseTransactions" tests --include="*Test.php" | wc -l
       0
```

223 files, 199 with `RefreshDatabase`, **zero** with `DatabaseTransactions`. The 24 without either:

```
$ comm -23 <(find tests -name "*Test.php" | sort) \
           <(grep -rl "RefreshDatabase" tests --include="*Test.php" | sort)
tests/Arch/ArchitectureBoundaryTest.php
tests/Arch/BoundaryLintCoverageTest.php
tests/Arch/NotificationsArchTest.php
tests/Arch/SqlClockLintCoverageTest.php
tests/Feature/ExampleTest.php
tests/Feature/Finance/ApprovalsQueueFeedCoverageTest.php
tests/Feature/Finance/CreditNoteConcurrencyTest.php
tests/Feature/Notifications/NotificationDeepLinkRouteTest.php
tests/Feature/Quality/PestNegatedExpectationMessagesTest.php
tests/Feature/Quality/QualityStepCountTest.php
tests/Feature/Rbac/ActiveSchoolRunForTest.php
tests/Feature/Rbac/ForcingMigrationsDoNotStripLaterGrantsTest.php
tests/Feature/Rbac/GrantsConvergenceLintTest.php
tests/Feature/Rbac/MigrationsDoNotReadTheSeederMapTest.php
tests/Feature/Rbac/RouteMiddlewareBaselineTest.php
tests/Feature/ScheduleTest.php
tests/Feature/Support/SchoolDayTest.php
tests/Unit/Casts/MoneyCastTest.php
tests/Unit/CurriculumGradingModeTest.php
tests/Unit/ExampleTest.php
tests/Unit/Finance/ApprovalRequirementTest.php
tests/Unit/MarkingSchemeResolutionTest.php
tests/Unit/MoneySplitTest.php
tests/Unit/Support/MoneyTest.php
```

Several are genuinely stateless — the four `tests/Arch/`, the `Money` unit tests, `ExampleTest`. Several
are not: `CreditNoteConcurrencyTest`, `ApprovalsQueueFeedCoverageTest`, `ActiveSchoolRunForTest`,
`ForcingMigrationsDoNotStripLaterGrantsTest` and `RouteMiddlewareBaselineTest` all touch the database
and none of them resets it.

**That is the mechanism, and it is worse under parallelism than under a rename.** A file with no
isolation trait reads whatever the previous test left behind. Today "the previous test" is a fixed
thing: serial execution over a stable file order. Parallelisation does not merely change which test
ran before — it makes several tests run *concurrently against the same token database*, so the state
a trait-less file observes is no longer even deterministic within one run. Triage each of the 24 as
stateless-or-not before the first parallel run, or the results are unreadable.

## The ratchet: what it actually does

`bin/ci-test-ratchet.php` keys the failing set on each `<testcase>`'s `file` attribute
(`bin/ci-test-ratchet.php:34-43`):

```php
foreach ($xml->xpath('//testcase') as $tc) {
    if (isset($tc->failure) || isset($tc->error)) {
        $id = trim((string) $tc['file']);
        if ($id !== '') {
            $failing[$id] = true;
        }
    }
}
```

Pest writes that attribute as `path::test name`, not as a bare path — which is why the baseline's
entries match it. From a stored artefact:

```
<testcase name="users are rate limited" file="tests/Feature/Auth/AuthenticationTest.php::users are rate limited" class="Tests\Feature\Auth\AuthenticationTest" ...
```

The committed baseline has 7 entries, six of them in exactly two files:

```
$ cat tests/ratchet-baseline.txt
tests/Feature/ActivityLog/ActivityLogApiTest.php::it blocks users without activity_log.view
tests/Feature/ActivityLog/ActivityLogApiTest.php::it does not leak activity across schools
tests/Feature/ActivityLog/ActivityLogApiTest.php::it hides sensitive entries without view_sensitive
tests/Feature/ActivityLog/ActivityLogApiTest.php::it returns a paginated scoped feed
tests/Feature/Auth/AuthenticationTest.php::users are rate limited
tests/Feature/GuardianProfileTest.php::it returns empty activity list when no events exist
tests/Feature/GuardianProfileTest.php::it sends a password reset notification to the guardian email
```

`ActivityLogApiTest` (4), `GuardianProfileTest` (2), `AuthenticationTest.php::users are rate limited` (1).

### CORRECTION — the ratchet is bidirectional, and the brief for this ticket had it wrong

The framing this ticket was commissioned under said the ratchet "fails only on an entry not already in
the baseline", and therefore that under a different execution order "a baselined failure may pass (the
ratchet permits shrink)".

**It does not permit shrink.** `bin/ci-test-ratchet.php:55-68`:

```php
$new = array_values(array_diff($failing, $baseline));
$fixed = array_values(array_diff($baseline, $failing));

// Baselines only SHRINK — enforced, not remembered. ...
if ($fixed) {
    fwrite(STDERR, "\nratchet: ".count($fixed)." baselined test(s) now PASS (good!) — lock it in by removing them from tests/ratchet-baseline.txt:\n");
    ...
    exit(1);
}
```

A baselined entry that starts passing exits **1**, before the new-failure check is even reached. The
gate goes red in *both* directions.

**This strengthens the ticket rather than weakening it.** Under a shuffled or parallel order the
ratchet is red if a previously-green test fails *and* red if a baselined failure passes — and the
second is the more likely of the two, because four of the seven baselined entries are rate-limiter and
activity-log tests, exactly the class whose failure depends on what ran before them. Expect the first
parallel run to be red for the "good" reason, and do not read that red as a regression.

**Neither red is a reason to edit the baseline.** Both are the measurement.

## What a run costs today — one figure, correctly labelled, plus the band it sits in

The 553s attributed to step 15 is **one run, on one machine, at sha 87b3702**
(`feat(finance): a bulk invoice run, and a record that accounts for every billable student`). It is not
a benchmark. It must be re-derived before it prices anything.

For scale only, here is what the run artefacts already on disk say. `bin/quality:259` keeps the last 20
runs, and each junit's root `<testsuite>` carries the wall time PHPUnit measured. **These are different
shas — the test count moves from 1736 to 1811 — so this is not a controlled variance measurement.** It
establishes a band, nothing more:

```
$ for f in $(ls -1t "$TMPDIR/quality-runs"/junit-*.xml | head -14); do sed -n '3p' "$f"; done
# each line is the root <testsuite …> element; rendered as a table below, attribute values verbatim
run                              time(s)   tests  errors  failures  skipped
junit-20260820-103614-5438.xml   733.6     1811   1       6         10
junit-20260820-094857-89532.xml  678.5     1811   1       6         10
junit-20260820-090403-74655.xml  445.0     1811   1       6         10
junit-20260820-085457-69217.xml  448.5     1767   1       6         10
junit-20260819-222817-35619.xml  639.6     1763   1       6         10
junit-20260819-221627-30433.xml  (empty artefact — run did not complete)
junit-20260819-220542-93788.xml  587.1     1763   1       6         10
junit-20260819-215302-46025.xml  698.8     1763   1       6         10
junit-20260819-214220-3820.xml   689.7     1763   63      7         10
junit-20260819-201435-51124.xml  519.1     1744   1       6         10
junit-20260819-200637-21936.xml  424.4     1744   1       6         10
junit-20260819-190657-5716.xml   551.3     1744   1       6         10
junit-20260819-185552-3894.xml   575.7     1744   1       6         10
junit-20260819-001526-81597.xml  468.5     1736   1       6         10
```

**424.4s to 733.6s across thirteen completed runs — a 1.7× spread on a serial suite that nobody
changed the concurrency of.** Whatever parallelisation is worth, it has to be measured against a
baseline this noisy, which is itself an argument for ten runs rather than one.

Twelve of the thirteen report `errors=1 failures=6` — seven failing, matching the baseline exactly.
The latest run's failing keys are the baseline, line for line:

```
$ python3 -c "…extract failing testcase/@file from junit-20260820-103614-5438.xml…"
7
  tests/Feature/ActivityLog/ActivityLogApiTest.php::it blocks users without activity_log.view
  tests/Feature/ActivityLog/ActivityLogApiTest.php::it does not leak activity across schools
  tests/Feature/ActivityLog/ActivityLogApiTest.php::it hides sensitive entries without view_sensitive
  tests/Feature/ActivityLog/ActivityLogApiTest.php::it returns a paginated scoped feed
  tests/Feature/Auth/AuthenticationTest.php::users are rate limited
  tests/Feature/GuardianProfileTest.php::it returns empty activity list when no events exist
  tests/Feature/GuardianProfileTest.php::it sends a password reset notification to the guardian email
```

### The thirteenth run is the ticket's argument, already recorded

`junit-20260819-214220-3820.xml` reports **63 errors across 11 files** where every other run reports 1:

```
distinct failing files: 11
   15  tests/Feature/Rbac/SuperAdminMatrixTest.php
   14  tests/Feature/Rbac/SchoolUserModuleTest.php
   13  tests/Feature/Rbac/SuperAdminAuthorityTest.php
    5  tests/Feature/Rbac/SeededPermissionCoverageTest.php
    5  tests/Feature/Rbac/SharedPermissionsTest.php
    5  tests/Feature/Rbac/TwoFactorEnrollmentTest.php
    4  tests/Feature/ActivityLog/ActivityLogApiTest.php
    4  tests/Feature/Rbac/SuperAdminBypassExclusionTest.php
    2  tests/Feature/GuardianProfileTest.php
    2  tests/Feature/Rbac/SchoolRbacConsoleTest.php
```

Eight of those eleven files are not in the baseline. **The serial suite already produces runs whose
failing set is nothing like the baseline**, on a machine where the only variable is the machine. This
ticket is not proposing to introduce that class of variance — it is proposing to stop taking it one
accidental sample at a time.

## The argument

The ratchet is what makes parallelisation **observable** and what makes it **dangerous**, and it is
the same property doing both: it is the only artefact in the repository that records what the suite's
failing set is supposed to be. Change the execution order and the ratchet tells you immediately that
something moved. It also cannot tell you *which direction is good* — a baselined failure that starts
passing and a green test that starts failing both exit 1, and both are information.

A green suite under one fixed order is a statement about that order. It is not a statement about the
tests.

## Acceptance criteria

1. **Ten serial runs, unchanged, at one sha.** Establish the current failing set and its variance —
   both the set and the wall time. Keep every junit artefact.
2. **Ten runs with a shuffled order, parallel, at the same sha.** This requires `brianium/paratest` in
   `require-dev` first; that is part of the work, not a precondition someone else supplies.
3. **The ticket's output names which tests move between the two sets.** A test that moves is either
   order-dependent or was passing for the wrong reason. Both need naming. Neither is a rerun.

A test that moves and is then made to stop moving by re-running until it does not is not fixed; per
ADR 0053, a red cannot be told from a flake by looking at it.

## Explicitly not decided here

Whether to adopt parallel execution at all; whether `bin/quality` step 15 changes; what the 24
trait-less files should become; whether `RefreshDatabase` goes back into `tests/Pest.php:28` globally.
The one thing this ticket asks for is the two sets of ten runs and the diff between them.

## See also

- `docs/handoff/tickets/a-green-pre-push-hook-does-not-mean-the-push-landed.md` — the other place a
  green line is a statement about the wrong thing.
- ADR 0053 — the local enforcement floor, and its determinism residual.
