# Implementation report — `->not->` discards custom failure messages

## Headline

**Done.** Every custom failure message written under a negated Pest expectation is
gone from `tests/`, and a gate stops the next one — with its blind spot named,
demonstrated, and left open on purpose.

Branch `fix/pest-not-discards-messages`, base `0974f06` (`origin/staging`, with
#221 merged). No PR at the time of writing this section; see the end.

**Two things to read before anything else**, because both change what the rest of
this report means:

1. **One of the 18 rewrites could not be made to fail** —
   `RouteAccessParityTest:75`. The arm is vacuous on today's tree and was equally
   vacuous before this branch touched it. Reported loudly, below.
2. **`SuperAdminBypassExclusionTest:52` was not a lost message. It was a vacuous
   assertion**, and the before/after proof shows the arm not firing at all while
   the precondition it guards was genuinely violated. That is a worse defect than
   the one this commit is named for.

## Deviations from the brief

**One, and it is the whole shape of FIX C.** The brief diagnosed the dead
diagnostic as `toContain` being variadic. That is half of it. Moving the message
onto `->not->toBe($x, "…")` — which *does* declare `string $message` — still did
not print it:

```
Expecting '' not to be '' 'The [opening_balance] entry d…T url.'.
```

`OppositeExpectation::__call`
(`vendor/pestphp/pest/src/Expectations/OppositeExpectation.php:770-784`) runs the
POSITIVE assertion and, when it passes — i.e. the `not` has failed — discards the
exception and calls `throwExpectationFailedException($name, $arguments)`, which
at `:811-825` runs `Exporter::shortenedExport()` over **every** argument, message
included.

**The general rule, stated as a rule so it can be checked:** on this Pest version
`->not->anyMatcher(…, "message")` discards the message on **every** matcher, not
only the variadic ones. Every fix in this branch is therefore a rewrite to a
POSITIVE expectation, never a re-placement of the message argument.

## Contradictions of the premise

**None in the brief.** Three in my own earlier numbers, which the brief inherited
and which are worth recording because the pattern is the point.

The count went **4 → 139 → 17 → 18**, over one unchanged tree, and every move was
a change to the *rule*:

| Count | Rule | Why it was wrong |
|---|---|---|
| 4 | single-line grep with `[^)]*` | Cannot see a wrapped or nested call. A floor, as the brief itself said. |
| 139 | reflection rule **with a bug**: the call's opening parenthesis was counted as an argument | Every no-argument `->not->toBeNull()` in the repo — correct code, no message — read as carrying one. 122 of the 139 were false. |
| 17 | the rule stated correctly | — |
| 18 | + one instance no signature-based rule can reach | `SuperAdminBypassExclusionTest:52`, found by reading. |

Two further scanner bugs were found by adversarial probing rather than by
reading, and both are now fixed in the shipped gate:

- **Adjacency.** `expect($x)\n    ->not\n    ->toBeNull(…)` was invisible, because
  the token match required `->`, `not`, `->`, name, `(` to be adjacent. No such
  form exists in the repo today, so the count did not move — but the detector had
  the same blind spot the original grep did.
- **Brackets inside interpolated strings.** A `T_ENCAPSED_AND_WHITESPACE` chunk
  whose whole value is `[` — what `"[{$basename}] …"` produces — was counted as an
  opening bracket, so the depth never closed and two scans ran past the closing
  parenthesis and swallowed the rest of the file.

## The exclusion audit

Requested before any fix, to test whether 139 → 17 was a discriminator narrowed
until the number was *correct* or narrowed until it was *small*. All 300 `->not->`
calls in `tests/`, classified:

| Class | Count | What a reader sees when that assertion fails |
|---|---|---|
| **CAUGHT** | 17 | The exported argument instead of the sentence. The defect. |
| **A** — zero arguments | 122 | `->not->toBeNull()` / `->not->toBeEmpty()`. **No message exists to lose.** PHPUnit's default is what the author asked for. |
| **B** — arguments supplied, none reaching `$message` | 46 | `->not->toBe($corrupt)` — `$message` is parameter #2, one supplied. `toThrow` (11 of these) has `$exceptionMessage` at #2 and `$message` at #3, so a 2-arg call supplies a real assertion input. |
| **C** — variadic matcher | 115 | `toContain(...$needles)` has **no `$message` parameter**, so `->not->` discards nothing. 113 supply ≤1 argument. |
| **D** — no `$message`, not variadic | 0 | Empty class. |
| **E** — matcher not on `Mixins\Expectation` | 7 | All `->not->toUse('App\Finance')`, Pest's arch expectation. One argument, a target; arch renders its own failure naming it. |

**Class C is where the audit paid.** Only 2 of its 115 supply 2+ arguments:

```php
// tests/Feature/Finance/SchemaConventionsTest.php:196 — CORRECT CODE, untouched
->not->toContain('finance_student_accounts_no_update', 'finance_student_accounts_no_delete')

// tests/Feature/Rbac/SuperAdminBypassExclusionTest.php:52 — the 18th
->not->toContain($ability, "precondition: super_admin must not HOLD {$ability}")
```

**Those two have the same shape and only one is a bug**, which a reader comparing
them needs told. `SchemaConventions` passes two trigger names — both are things the
subject might genuinely contain, and the assertion "contains neither" is exactly
what the author meant. `:52` passes an ability and an *English sentence*. The
sentence is not a thing the permission collection could ever contain, so the
second needle can never make the arm fail. One is two needles; the other is a
message wearing a needle's clothes.

## What changed

11 test files fixed, 1 gate added, 1 ADR extended.

| File | What |
|---|---|
| `tests/Feature/Quality/PestNegatedExpectationMessagesTest.php` | **New.** The gate. Zero exemptions, no baseline. |
| `docs/adr/0052-a-migration-is-a-dated-act.md` | New section: *A REPORT is a dated act too*. |
| `tests/Arch/NotificationsArchTest.php` | 1 |
| `tests/Feature/Academics/CommentBandTest.php` | 1 |
| `tests/Feature/Finance/ApprovalsQueueRendersEveryTypeTest.php` | 1 |
| `tests/Feature/Finance/OpeningBalanceImportTest.php` | 3 |
| `tests/Feature/Finance/TriggerBodiesAreDumpSafeTest.php` | 1 |
| `tests/Feature/Rbac/DutySeparationBaselineTest.php` | 4 |
| `tests/Feature/Rbac/FinanceRoleRealignmentTest.php` | 1 |
| `tests/Feature/Rbac/ForcingMigrationsDoNotStripLaterGrantsTest.php` | 2 |
| `tests/Feature/Rbac/RouteAccessParityTest.php` | 1 |
| `tests/Feature/Rbac/SuperAdminBypassExclusionTest.php` | 1 |
| `tests/Feature/ScheduleTest.php` | 2 |

**Rewritten one at a time, not by codemod**, choosing the form that keeps the most
information rather than the one that applies most easily:

- `->not->toBeNull($m)` on a value about to be dereferenced →
  `toBeInstanceOf(Event::class, $m)` / `toBeInstanceOf(Role::class, $m)` /
  `toBeArray($m)`. Keeps the message **and** names what arrived instead — which
  `->not->toBeNull` never printed.
- `->not->toBeEmpty($m)` → `expect(count($x))->toBeGreaterThan(0, $m)`, which also
  puts the `0` in the output.
- `->not->toBe('', $column)` → `mb_strlen(...)` → `toBeGreaterThan(0, $column)`.

**Six unrelated files were reformatted by Pint and reverted.** Running
`./vendor/bin/pint tests/` touched `Auth/*`, `Dashboard/DataGapsTest`,
`ActivityLog/ActivitySchoolScopingTest` and `MultiSchool/MultiSchoolAccessTest`,
all pint-dirty on `staging` before this branch. They are not this commit's
business and were restored with `git checkout --`.

### The one rewrite that trades something away

`RouteAccessParityTest:75`. `->not->toEqual($other, $message)` printed both role
arrays and discarded the message; `expect($roles == $fixture[$key]['roles'])->toBeFalse($message)`
keeps the message and loses the arrays. The message names `$key`, which identifies
the offending row, and both arrays are one lookup away in the fixture. Stated in a
comment at the site as well as here.

`==` and not `===`, deliberately: `toEqual` is a **loose** comparison, and `!==` is
true more often than `!=`, which would have quietly weakened the assertion while
looking stricter.

## The watched red — 18 attempted, 17 fired

Each rewritten assertion was mutated at its subject (not at the assertion), the
file's tests run, the failure captured, the file restored. The full log is below;
what matters is that **every message printed whole**, as the failure description.

```
1  NotificationsArchTest:142       type [approval.requested] declares no channels
                                   Failed asserting that 0 is greater than 0.
2  CommentBandTest:135             score 0 resolved to no band
3  ApprovalsQueueRendersEveryType  /api/v1/finance/credit-notes/pending returned no pending row
   :314                            Failed asserting that null is of type array.
4  OpeningBalanceImportTest:472    ob_rows_school_batch_admission_fee_type_unique is not an index on the table
5  OpeningBalanceImportTest:989    admission_number
6  OpeningBalanceImportTest:990    admission_number
7  TriggerBodiesAreDumpSafe:34     no triggers found — this test would pass vacuously and prove nothing
8  DutySeparationBaseline:136      the plant produced no finding at all — this arm would be vacuous
9  DutySeparationBaseline:173      the plant produced no finding at all — this arm would be vacuous
10 DutySeparationBaseline:251      an empty baseline should be deleted along with the --baseline= argument,
                                   not committed empty
11 DutySeparationBaseline:281      no enforced pairs at all would make this arm vacuous
12 FinanceRoleRealignment:88       global role [executive_director] is missing from the seeded map
                                   Failed asserting that null is an instance of class App\Models\Role.
13 ForcingMigrations:60            RbacSeeder::grantsMap() returned nothing — this test would pass vacuously
14 ForcingMigrations:84            [2026_08_06_100000_move_head_of_school_finance_to_executive_director.php]'s
                                   TARGET is empty — nothing would be checked against it
15 ScheduleTest:43                 [authz:prune] is not registered in routes/console.php
                                   Failed asserting that null is an instance of class …\Scheduling\Event.
16 ScheduleTest:71                 [authz:prune] is not registered
17 RouteAccessParityTest:75        !! NO FAILURE RECORDED
18 SuperAdminBypassExclusion:52    precondition: super_admin must not HOLD result.approve
```

### #17 — the one that would not fail, and why that is a finding

`RouteAccessParityTest`'s second arm reads:

```php
foreach (ACCESS_DEVIATIONS as $key => $roles) {
    expect($fixture)->toHaveKey($key);
    expect($roles == $fixture[$key]['roles'])->toBeFalse("'{$key}' is listed as a deviation but matches the fixture — stale entry, remove it.");
}
```

`ACCESS_DEVIATIONS` is **empty** (`RouteAccessParityTest:27-32`, deliberately — the
last deviation was removed when the fixture was regenerated). The loop body never
executes, so the arm asserts nothing at all. **This is not something my rewrite
caused**: the original `->not->toEqual(...)` was equally unreachable, and the test
*"keeps the deviation list honest — each entry differs from the fixture"* has been
green-and-empty for as long as the list has been empty.

**The rewrite itself is proven correct**, by planting one deviation whose roles
match the fixture:

```
it keeps the deviation list honest — each entry differs from the fixture
'DELETE /api/activity-logs/saved-filters/{savedActivityFilter}' is listed as a deviation
but matches the fixture — stale entry, remove it.
Failed asserting that true is false.
at tests/Feature/Rbac/RouteAccessParityTest.php:86
```

So: the assertion fires when it has input, and has no input. **I did not "fix"
this**, because an arm that checks each declared deviation is legitimately silent
when nothing is declared — the list is *supposed* to reach empty. Raised as a
ticket below rather than papered over with a non-empty guard, which would assert
the opposite of what the file wants.

## `SuperAdminBypassExclusionTest:52` — before and after, raw

Not a mutation of the assertion. The precondition it guards — *the seeded
super_admin holds none of the checker permissions* — was violated **honestly**, by
granting the super_admin one real checker ability, and the arm then run in each
form.

**BEFORE** — original `->not->toContain($ability, "precondition: …")`:

```
it excludes every terminally-approve/reject enum case from the bypass, and excludes nothing else
finance.opening-balance.approve is a checker action (ADR 0040) and must NOT be bypassed —
if this is a new permission, that is the point: the convention covered it automatically.
Failed asserting that true is false.
at tests/Feature/Rbac/SuperAdminBypassExclusionTest.php:64
```

**Read the message.** That is not the precondition arm's sentence — it is the
**next** assertion's, the `can()` check two lines down. **The precondition
assertion did not fail at all**, with the precondition genuinely violated.

The mechanism, now confirmed rather than inferred: `->not->toContain(a, b)` runs
the positive `toContain(a, b)`, which requires the subject to contain **both**. The
English sentence is never present, so the positive assertion always fails, so the
negation always passes — **regardless of whether `$granted` contains `$ability`**.
The arm was unconditionally green on the thing it claimed to check.

**AFTER** — rewritten `expect($granted->contains($ability))->toBeFalse("precondition: …")`:

```
it excludes every terminally-approve/reject enum case from the bypass, and excludes nothing else
precondition: super_admin must not HOLD finance.opening-balance.approve
Failed asserting that true is false.
at tests/Feature/Rbac/SuperAdminBypassExclusionTest.php:64
```

The precondition arm now fires, first, and names the ability.

## The gate

`tests/Feature/Quality/PestNegatedExpectationMessagesTest.php`. A test, not a
15th `bin/quality` step: step 14 runs the suite, so it runs on every push either
way, and a second home for one rule is the thing this repo keeps paying for.

**Zero exemptions**, because the discriminator is the vendor's own signature —
reflect `Pest\Mixins\Expectation`, flag an argument landing in a non-variadic
parameter named `message`. It is not a heuristic over argument shape, so
`->not->toContain('a', 'b')` needs no allowlist to stay green.

Its docblock carries four things the brief asked for: the rule in prose; **the
rule is the definition** (4/139/17 were three rules over one unchanged tree, and a
future widening will find more); **the named hole**; and **the rejected
heuristic**, with the reason — flagging multi-word or `{$interpolated}` needles
would be tuned against the ~300 instances in today's tree, which is fitting the
discriminator to the sample. An honest hole beats a rule that cannot be wrong.

### The gate's own proofs

**GREEN 1 — the unmodified tree.** `PASSED — no failure recorded`.

**GREEN 2 — `->not->toContain('a', 'b')` added temporarily** (two legitimate
needles, the false positive an allowlist would have been written for):

```
expect(['a', 'b', 'c'])->not->toContain('x', 'y');   // planted
PASSED — no failure recorded
```

**RED — one of the 18 re-introduced** (`ScheduleTest:71` reverted to
`->not->toBeNull("…")`):

```
A custom failure message is passed to a NEGATED expectation. Pest discards it: `->not->` runs
the positive assertion and, when that succeeds, throws its own sentence with every argument
shortened-exported into it — so the message is never the failure description and is truncated
in the middle. …

tests/Feature/ScheduleTest.php:74  ->not->toBeNull (message is argument #1, 1 supplied)
```

**GREEN 3 — the blind spot, demonstrated rather than described.** `:52`'s
**original** line re-introduced verbatim:

```
expect($granted)->not->toContain($ability, "precondition: super_admin must not HOLD {$ability}");
PASSED — no failure recorded
```

The gate does not catch it, will not catch it, and says so in its own docblock.

## ADR — where the "a report is a dated act" rule went, and why

**ADR 0052**, as a new section rather than a new ADR. The argument:

- **0052's title already states the general principle** — *"A migration is a dated
  act, not a live query"*. Migrations are the instance; the principle is wider.
- **0052 already draws this exact boundary one level down.** Its section *"What
  the corollary governs is the EXECUTING half — a comment is not in scope"* rules
  that within one artifact some parts are frozen and some must be corrected. The
  report rule is the same question asked of a different artifact: does this text
  make a claim about a **moment** or about the **system**?
- **A second ADR would be a second place to keep one statement in step**, which is
  the failure mode 0052 exists to object to.

The section states the boundary as a table (live documentation is corrected;
dated records are not), gives the case that produced it (four sentences left
untouched in 5a's report, one corrected in the MVP cut brief), and ends by
admitting there is **no gate behind it** — `bin/quality` cannot tell a report from
a brief — so it is recorded as a convention, labelled as one, rather than dressed
as a control.

## Proof

```
DB_DATABASE=portal_testing ./vendor/bin/pest <the 11 touched files>
{"tool":"pest","result":"passed","tests":124,"passed":124,"assertions":759}
```

Census after the fixes, by the gate's own rule:

```
CAUGHT                0
A_zero_args           122
B_short_of_message    46
C_variadic            114     ← 115 minus :52, now rewritten
D_no_message_param    0
E_unknown_matcher     7
```

The one remaining `C_variadic` with 2+ arguments is `SchemaConventionsTest:196`,
which is correct code and stays exactly as it is.

### bin/quality — raw, unedited (ANSI colour codes stripped; nothing else removed)

```
quality gate — base 0974f06

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

- **`RouteAccessParityTest`'s deviation arm is still vacuous** — see #17. Not
  fixed, because the fix is a decision about what that arm should do when the list
  is empty, not a rewrite.
- **No sweep of `app/`, `database/` or any non-`tests/` directory.** `expect()` is
  a test-only API here, but that was assumed rather than measured.
- **The gate does not run on staged-but-uncommitted files differently from
  committed ones** — it walks `tests/` on disk, which is what step 14 sees.
- **Pest version dependence is not pinned.** Everything here is true of the
  `OppositeExpectation` shipped in this `composer.lock`. If a future Pest makes
  `->not->` forward the message, the gate becomes wrong rather than merely
  unnecessary, and nothing in this branch would notice.

## Findings raised, not fixed

- `tests/Feature/Rbac/RouteAccessParityTest.php:67-88` — the *"keeps the deviation
  list honest"* arm asserts nothing while `ACCESS_DEVIATIONS` is empty, which it is
  and is meant to become. A test that is silent by design is not a defect, but it
  is also not coverage, and its name promises coverage. **ticket.**
- `tests/Feature/Rbac/SuperAdminBypassExclusionTest.php` — the vacuity found here
  was in the arm guarding **ADR 0040**. Nothing says how many other arms in this
  repo are green for a reason other than the one they claim; this branch found one
  by hand, and the class has no detector. **ticket**, and the more interesting of
  the two.
- Pest's `->not->` accepting a `$message` argument it silently discards is a
  vendor-side footgun. Worth an upstream issue; not this repo's to fix. **ticket.**
