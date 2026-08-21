# `feat/citation-lint` — a new `path:LINE` citation must name a symbol, and the symbol must be there

Branch off `origin/staging` at `06cd9a3`. Closes the tier question left open in
`docs/handoff/tickets/stale-path-line-citations.md`, which has recorded the same defect six times
across four branches.

**What shipped:** `bin/ci-citation-lint.php`, `citation-lint-baseline.txt` (163 keys, 180
citations), `tests/Arch/CitationLintCoverageTest.php` (13 arms), `bin/quality` step 13 of 16, and
the ticket amended to say a mechanism now exists.

---

## 1. The rule

A citation is a `path:LINE` token matching the ticket's own regex, reused verbatim:

```
#(?<![\w/.-])([A-Za-z0-9_][A-Za-z0-9_./-]*\.(?:php|ts|tsx|js|jsx|md|sh|sql|json|xml)):(\d+)#
```

It is COMPLIANT when it carries a symbol and that symbol occurs in the cited file within ±3 lines
of the cited line:

```
app/Support/ActiveSchool.php:99 (getOrFail)      compliant if `getOrFail` is within ±3 of line 99
app/Support/ActiveSchool.php:99                  not compliant — nothing to check against
```

A range is checked at its START line. Widening the window to the whole range would make a long
range self-approving.

Five rules, each with its own message:

| rule | fires when |
| --- | --- |
| `citation-missing-symbol` | a bare `path:LINE`, no `(symbol)` |
| `citation-symbol-not-found` | the named symbol is not within ±3 of the cited line |
| `citation-past-eof` | the cited line is beyond the end of the cited file |
| `citation-not-repo-relative` | a bare basename, which is never resolved against the repo |
| `citation-unresolvable` | a path this repo does not contain and vendor/ does not either |

---

## 2. The window: N = 3, and the measurement says N is not a drift knob

Two curves were measured over this tree. Both scripts are reproducible from the description below;
neither is committed.

**(a) Discrimination.** 3,708 adjacent-declaration pairs across 842 files in the scanned dirs. For
each declaration, the distance to the nearest occurrence of the NEXT declaration's name — i.e. if a
citation names the wrong neighbouring symbol, does it still pass?

```
N=0   2.5%     N=1   4.2%     N=2  20.0%     N=3  23.2%
N=5  36.0%     N=8  53.7%     N=10 61.4%     N=15 73.0%
N=20 81.6%     N=30 90.2%     N=50 95.4%
gap percentiles: p10=2  p25=4  p50=8  p75=16  p90=30
```

**(b) Drift.** Symbols matched by name between a sha 30 days back (`fab302b`) and HEAD, and between
60 days back (`f64e771`) and HEAD. 30-day: 2,222 symbols matched across 170 changed files, 754
moved. Of the moved ones, how many are still inside the window:

```
30-day   N=0  0.0%   N=1 18.2%   N=2 25.2%   N=3 31.2%   N=5 42.6%
         N=8 48.0%   N=10 52.1%  N=20 68.8%  N=30 75.9%  N=50 86.1%
         moved-symbol |delta|: p50=9  p75=29  p90=129  p95=256  p99=368

60-day   1,514 symbols across 183 changed files, 785 moved (51.8%)
         N=3 29.7% of moved still inside; moved |delta|: p50=9  p75=32  p90=90
```

**The two curves cross.** At every N the window admits about as many WRONG symbols as it retains
DRIFTED-but-right ones — 53.7% against 48.0% at N=8; 95.4% against 86.1% at N=50. Drift is
heavy-tailed (median move among moved symbols is 9 lines, p90 is 129), so no window absorbs it.
That reframes what N is: a tolerance for jitter above a symbol — an added `use`, an attribute, a
blank line — and not a tolerance for movement. When a symbol drifts out of the window the lint
FAILS, on the branch that moved the target, which is the case a diff-aware rule cannot see because
the citing file is untouched.

N=3 is the smallest window that still tolerates jitter: 29.7% of all 60-day moves are ≤3 lines.
N=1 discriminates better (4.2% against 23.2%) and was rejected because a one-line insert above a
symbol is not a defect and should not fail a gate. N=0 fails on that insert by construction.

---

## 3. The matcher, narrowed, with the numbers both ways

The symbol is read from a parenthesised group immediately following the token. The first version
accepted ANY parenthesised text and asked whether any word in it occurred near the cited line.

**Accepting prose: 2 of the 194 citations in scope passed BY COINCIDENCE.**

```
app/Finance/Http/Controllers/PaymentReceiptController.php:37  routes/web.php:237 (the statement page)
database/seeders/RbacSeeder.php:478   routes/endpoints/finance.php:24 (invoice-addressed)
```

Neither names a symbol. `(invoice-addressed)` passes because the word "invoice" is in the route
path on the next line down. A coincidental pass is worse than a baseline entry because it reads
like verification.

**Requiring the group to be symbol-shaped — one identifier, optionally qualified
(`Class::method`, `$table->timestamp`, `handle()`, `App\Models\User`), no spaces: 0 of 194.** Both
citations moved to the baseline where they belong. Shape assertions verified directly:

```
ensureBankAccount        true      the statement page       false
SubledgerPoster::post    true      invoice-addressed        false
$table->timestamp        true      see below                false
handle()                 true
App\Models\User          true
```

The narrowing was visible in the run that followed it — the second of the two was in a file that
already had one baselined citation of the same token, so the count key reported it as growth rather
than as a new key:

```
citation-lint: 2 NEW or GROWN citation violation(s) …
  ✗ database/seeders/RbacSeeder.php  routes/endpoints/finance.php:24  [citation-missing-symbol]
  ✗ app/Finance/Http/Controllers/PaymentReceiptController.php  routes/web.php:237  [citation-missing-symbol]  baselined 1, now 2
```

Within `symbolIsNear()`, a qualified symbol passes when ANY of its identifiers is near, not every
one — `SubledgerPoster::post` names two and the class name is routinely not on the method's line.
Identifiers shorter than three characters are dropped; `$a` and `x` match everywhere.

---

## 4. Scope

**Scanned:** `app/ tests/ bin/ database/ config/ routes/ bootstrap/ .claude/skills/`
**Not scanned:** `docs/`

Every file in those directories is read, including comments and fenced blocks. Comment lines are
NOT skipped, unlike the sibling lints — a citation's natural home is a docblock, and skipping
comments would skip the defect.

**Why docs/ is out, and what that costs.** Reports paste raw command output by rule, and `grep -n`
output is byte-identical to a citation. The ticket measured this at `2b3cdbb`: seven of the nine
past-EOF hits in the whole tree were that ticket's own self-quotations of census output. A lint over
docs/ opens with a baseline dominated by its own documentation.

The cost is not hypothetical and is not covered by anything else. Citations inside tickets and
reports stay unguarded, and two of the six recorded instances were exactly that —
`a-malformed-200-renders-the-empty-state-not-the-error-state.md:25`, and the seven numbers in
report §9/§11.2. **This lint does not cover them.** The exclusion is written into the script's
docblock, into the ticket, and into coverage arm b.

**Why `.claude/skills/` is in.** Skills are what agents read as instructions. `6b14a43` and
`ec2b56a` were verified directly on this branch rather than carried from the ticket:

```
$ git show 6b14a43 -- .claude/skills/finance-drive/SKILL.md | grep '^-' | grep -oE "$RX" | sort -u | wc -l
7          # of which 3 changed value (comm against the added side)
$ git show ec2b56a -- .claude/skills/finance-drive/SKILL.md | grep '^-' | grep -oE "$RX" | sort -u | wc -l
3          # of which 2 changed value: SeedDriveFixture.php:155→:162, DriveFinanceStates.php:65→:66
```

Both figures match the ticket.

---

## 5. Vendor: resolved, not guessed

A cited path this repository does not contain is EXEMPT when it resolves under `vendor/` — by
prefix (`vendor/…`) or by unique path SUFFIX (`Models/Role.php`). **Vendor is decided BEFORE
anything is resolved in-tree, and vendor wins ties.**

This is the only thing that stops the lint manufacturing findings, and it was proved by building the
resolver the ticket warns about and watching it manufacture one. With the vendor exemption removed
and the ticket's basename fallback reinstated:

```
  ✗ app/Finance/CitationLintFixtureI2.php     Models/Role.php:186  [citation-past-eof]
  ✗ tests/Feature/Rbac/RbacDiffGrantsTest.php Models/Role.php:186  [citation-past-eof]

app/Models/Role.php: 36 lines
vendor/spatie/laravel-permission/src/Models/Role.php: 221 lines
```

Both are false. `:186` is the `findByParam` team-scoping block in the 221-line vendor file. The
shipped lint reports neither, including on the real tree file, which is one of the two the ticket
measured. A line-scoped `vendor` word guard would not have been enough: in the second shape the word
"Vendor" opens the PREVIOUS line, and coverage arm i plants both spellings.

**A bare basename is never resolved against the repo.** `SeedDriveFixture.php:155` is reported as
`citation-not-repo-relative`, not resolved to `app/Console/Commands/SeedDriveFixture.php`. That is
the ticket's own cheapest step — require repo-relative paths in new citations — and it is what makes
the vendor resolution meaningful.

**What that costs, measured on this tree.** 12 citations are exempted by vendor SUFFIX and 2 by
vendor prefix. One of the 12 is a tie the exemption resolves toward vendor:
`app/Http/Requests/SyncUserRolesRequest.php:101` cites `User.php:412`, which matches both
`vendor/laravel/framework/src/Illuminate/Foundation/Auth/User.php` and `app/Models/User.php`. It is
unchecked. Precision over recall, deliberately.

**Vendor/ must be installed for the exemption to fire.** `bin/quality` step 1 is dependency
integrity, so it is present wherever this gate runs. On a checkout without it, a bare vendor
citation is reported as `citation-not-repo-relative` or `citation-unresolvable` — a wrong message on
a citation that is non-compliant either way, never a manufactured in-tree finding, because the
resolver has no repo fallback to fall into.

---

## 6. The baseline: 163 keys, 180 citations, and the key

```
rule \t citingPath \t citedToken \t COUNT
```

Shrink-only: a new key fails, a rising count on an existing key fails, and a count that has fallen
fails with "fixed (good!) — regenerate", matching `ci-boundary-lint.php` and `ci-money-lint.php`
after their 2026-07-20 audit.

**The count is the fix `docs/handoff/tickets/boundary-lint-baseline-keys-on-line-text.md`
prescribes.** That ticket records `ci-boundary-lint.php` keying on `rule \t path \t trim($line)`
with no count, so a seventh byte-identical violation produces a key that is already present and is
admitted silently. Here a second byte-identical citing line takes the key from 1 to 2 and fails —
arm k, bite-proved below on a real tracked file.

**What this key cannot distinguish, stated rather than discovered later:**

- **Which occurrence is forgiven.** Delete a baselined bare citation and add a different new bare
  citation of the same target in the same file: the count is still 1, and it is green. The baseline
  forgives N occurrences of a target in a file, not N specific sentences.
- **Where in the file the citation sits.** Keying on the citing line NUMBER was rejected for the
  reason that ticket gives: it fails on every unrelated edit above a baselined line and trains
  people to regenerate reflexively.
- **The citing line's text.** Rewording the sentence around a baselined citation is invisible.

**Composition.** 194 citations matched in scope; 180 baselined, 14 vendor-exempt, 0 compliant.

```
citation-missing-symbol      98
citation-not-repo-relative   78
citation-unresolvable         2

by citing directory:  .claude 61 · app 40 · tests 40 · database 26 · bin 8 · routes 2 · config 1
16 keys carry count 2; the rest carry 1.
```

The two `citation-unresolvable` entries are `dist/index.js:2271` and `endpoints/finance.php:24`.

---

## 7. Coverage test — 13 arms, each bite-proved

`tests/Arch/CitationLintCoverageTest.php`, group `arch`. Arms plant real files, run the real
script, and assert what it reports; no matcher is re-implemented. Two arms mutate TRACKED files
(k and l), each saving the exact bytes first and restoring per path in a `finally`.

All 13 green: `tests=13 passed=13 assertions=63`.

### 7.1 The raw output each arm asserts on

Produced by planting each arm's fixture and running the real script.

**a — a new bare `path:LINE` in `app/`** → exit 1

```
citation-lint: 1 NEW or GROWN citation violation(s) — a citation must name a symbol, and the symbol must be there:
  ✗ app/Finance/CitationLintFixtureA.php  app/Finance/CitationLintFixtureTARGET.php:7  [citation-missing-symbol]
      carries no symbol, so nothing about it can be checked — write `path:LINE (symbolName)`
```

**b — the same token in `docs/`** → exit 0

```
citation-lint: OK — no new citation violations (163 baselined key(s), 180 citation(s)).
```

**c — `path:LINE (symbol)` with the symbol at the line, two below, and three above** → exit 0

```
citation-lint: OK — no new citation violations (163 baselined key(s), 180 citation(s)).
```

**d — a symbol that is absent, and one present but outside the window** → exit 1

```
citation-lint: 2 NEW or GROWN citation violation(s) …
  ✗ app/Finance/CitationLintFixtureD.php  app/Finance/CitationLintFixtureTARGET.php:7  [citation-symbol-not-found]
  ✗ app/Finance/CitationLintFixtureD.php  app/Finance/CitationLintFixtureTARGET.php:8  [citation-symbol-not-found]
```

The two are asserted separately because they fail for different reasons — `:7` names a symbol that
is nowhere in the file, `:8` names `farAwaySymbol`, which IS in the file, 20-odd lines away. An arm
that asserted only "exit 1" stays green through the mutation that guts the window.

**e — a cited file that does not exist, and a bare basename** → exit 1

```
  ✗ app/Finance/CitationLintFixtureE.php  SeedDriveFixture.php:155  [citation-not-repo-relative]
  ✗ app/Finance/CitationLintFixtureE.php  app/Finance/NoSuchFileZZZ.php:12  [citation-unresolvable]
```

**f — a cited line past end of file** → exit 1. The citation carries a REAL symbol, so a green
could not be explained away as "it never reached the line check".

```
  ✗ app/Finance/CitationLintFixtureF.php  app/Finance/CitationLintFixtureTARGET.php:529  [citation-past-eof]
```

**g — a baselined bare citation** → exit 0, plus a non-vacuity check: the arm reads the baseline,
asserts it holds more than 100 entries, and asserts the citing file of each of the first five still
contains its token, so a green cannot mean "the baselined citations were quietly deleted".

**h — the baseline growing by one** → exit 1, and the arm asserts the count is exactly one

```
citation-lint: 1 NEW or GROWN citation violation(s) …
  ✗ app/Finance/CitationLintFixtureH.php  app/Support/ActiveSchool.php:99  [citation-missing-symbol]
```

**i — the vendor shape, both of the ticket's spellings** → exit 0, neither fixture named. Armed
first: `app/Models/Role.php` is asserted to be shorter than 186 lines and the vendor file longer,
so the trap is real. §5 above shows what the ticket's resolver does with the same fixture.

**j — a citation inside a fenced quoted `grep -n` block, in `.claude/skills/`** → exit 1

```
  ✗ .claude/skills/CitationLintFixtureJ.md  app/Support/ActiveSchool.php:99  [citation-missing-symbol]
```

**The decision this arm records: inside a SCANNED file, a `path:LINE` token IS a citation wherever
it sits.** The lint does not try to tell quoted output from prose because it structurally cannot —
`grep -n` output is byte-identical to a citation, which is the whole reason docs/ is out of scope.
Inside the scanned dirs the consequence is accepted: a scanned file that pastes tool output gets a
finding, and the answer is the baseline, argued once.

**k — a SECOND byte-identical citing line in a baselined file** → exit 1. Run against a real
tracked file, restored afterwards:

```
citation-lint: 1 NEW or GROWN citation violation(s) …
  ✗ app/Finance/Actions/GenerateInvoice.php  app/Models/StudentCurriculum.php:76  [citation-missing-symbol]  baselined 1, now 2

# after restore:
citation-lint: OK — no new citation violations (163 baselined key(s), 180 citation(s)).
```

The arm asserts the restored bytes are identical to the saved copy and re-runs the lint green, so
it cannot leave residue for the arms after it.

**l — a baselined citation fixed but left in the baseline (shrink-lock)** → exit 1

```
citation-lint: 1 baselined citation(s) fixed (good!) — lock it in by regenerating the baseline:
  - app/Finance/NoSuchCiter.php  app/Nowhere.php:1  [citation-missing-symbol]  baselined 1, now 0
  regenerate: php bin/ci-citation-lint.php generate
```

This is the sibling defect the repo paid for twice: `ci-authz-lint` and `ci-boundary-lint` both
WARNED on a stale entry and still exited 0.

**m — the tree as it stands** → exit 0.

### 7.2 The mutations each arm caught

Each mutation was applied to `bin/ci-citation-lint.php` alone, the named arm run, and the script
restored from a saved copy — verified byte-identical afterwards.

| # | mutation | arm that went RED |
| --- | --- | --- |
| M1 | `citation-missing-symbol` branch sets `$rule = null` | a |
| M2 | `docs` added to `SCANNED_DIRS` | b |
| M3 | `WINDOW` 3 → 0 | c |
| M4 | `symbolIsNear()` searches the whole file instead of the window | d |
| M5 | `citation-unresolvable` / `citation-not-repo-relative` dropped | e |
| M6 | past-EOF check disabled | f |
| M7 | vendor exemption removed | i |
| M8 | vendor exemption removed AND the ticket's basename fallback reinstated | manufactures the finding in §5 |
| M9 | `$count > $baseline[$key]` → `false` | k |
| M10 | shrink-lock `exit(1)` removed (warn only) | l |
| M11 | `.claude/skills` removed from `SCANNED_DIRS` | j |

M1 also reds every green-asserting arm, because removing a rule makes 90 baselined entries look
"fixed" and the shrink-lock fires. That is the ratchet working, not extra coverage; the arm named
above is the one the mutation is aimed at, and each was run under `--filter` so the attribution is
one arm at a time.

---

## 8. Wiring

`bin/quality` step **13 of 16**, between the sql-clock lint and the architecture tests:

```
step "citation lint (a new path:LINE citation names a symbol, and the symbol is there)"
check "citation-lint" php bin/ci-citation-lint.php
```

**State-based, not diff-aware, and the baseline is why.** The history is IN the baseline, so the
whole tree is re-asserted on every run. A citation that goes stale because a DIFFERENT branch moved
its target fails on that branch — the case a diff-aware rule structurally cannot see, since the
citing file is untouched and nothing about it is in the diff. This is the tier-2 catch reached by
tier-3 means. A diff-aware version would additionally have to re-derive what "new" means on every
rebase.

`tests/Feature/Quality/QualityStepCountTest.php` was updated: its assertion is relational and needed
no change to the logic, but the prose example inside it named "step 15" for the suite and now names
step 16. It passes.

**Step numbers and `bin/quality` line numbers moved, and the in-scope citations were re-derived by
hand.** Adding a step shifted everything at or after it by +13 lines. Corrected:

| file | was | now |
| --- | --- | --- |
| `tests/Arch/BoundaryLintCoverageTest.php` | `bin/quality:238` (step 13), `:266` (step 15) | `:251` (step 14), `:279` (step 16) |
| `tests/Arch/SqlClockLintCoverageTest.php` | `bin/quality:238`, `:266` | `:251`, `:279` |
| `tests/Feature/Quality/PestNegatedExpectationMessagesTest.php` | "step 15 runs the suite" | step 16 |
| `tests/Feature/Finance/PaymentProvenanceTest.php` | "the suite is bin/quality step 15" | step 16 |
| `tests/Feature/Quality/QualityStepCountTest.php` | "the suite is step 15" | step 16 |

`.claude/skills/finance-drive/SKILL.md:79` cites `bin/quality:195`, which is before the insert and
is still `check "build" pnpm run build`; re-derived, unchanged.

**None of the five corrections above was made by this lint.** `bin/quality` is extensionless, so it
matches nothing under the ticket's regex — see §9.

---

## 9. What this does not cover

1. **A green does not prove the sentence is true.** A citation whose symbol appears near the cited
   line can still be wrong about what it claims. This lint checks that the pointer lands somewhere
   sane; it cannot check that the sentence is true. The ticket's instances 3 and 5 were false when
   written with the citation itself well-formed.
2. **`docs/` is unscanned.** Citations in tickets and reports are unguarded, and two of the six
   recorded instances were exactly that. §4.
3. **Extensionless paths match nothing.** `bin/quality:251`, `bin/landed`, `bin/lint-changed.sh` is
   seen (it has an extension) but `bin/quality` is not. The ticket noted this; this branch hit it
   immediately, because adding a step made five in-scope citations of `bin/quality` stale and the
   lint saw none of them. They were corrected by hand (§8).
4. **This branch left stale citations in `docs/`.** The +13 line shift makes `bin/quality:238`,
   `:266`, `:259`, `:271`, `:272`, `:274`, `:213` stale wherever docs/ names them —
   `docs/handoff/tickets/quality-gate-is-not-safe-to-run-from-two-trees.md`,
   `docs/handoff/tickets/the-suite-runs-serial-and-nothing-makes-it-parallel.md`,
   `docs/handoff/tickets/fresh-clone-review-needs-a-built-manifest.md`, and the citations ticket
   itself. They were NOT rewritten: several sit inside pasted command output that records a state at
   a named commit. Whoever next edits those tickets can re-derive with a +13 offset for any line at
   or after the new step.
5. **The baseline forgives a target, not a sentence.** §6.
6. **A vendor tie is unchecked.** `User.php:412` is the live instance. §5.
7. **Two named files are exempt from the lint entirely** — `bin/ci-citation-lint.php` and
   `tests/Arch/CitationLintCoverageTest.php` — because both contain citations as DATA. A genuine
   stale citation written in either is not seen. Same shape as `SELF` in `ci-sql-clock-lint.php`.
8. **The symbol check is a word match, not a parse.** A symbol name that appears inside a comment,
   a string, or an unrelated identifier within ±3 lines satisfies it.
9. **Nothing here proves the baselined 180 are correct.** They are recorded, not verified. Burning
   one down means giving it a symbol and re-deriving the line.

---

## 10. What I could not verify

- **The two curves in §2 are properties of this tree at this commit**, measured with a declaration
  regex (`function|class|interface|trait|enum|const NAME`) that does not see PHP properties,
  arrow-function assignments, or TS/TSX declaration forms other than those keywords. A different
  extraction would move the percentages; whether it would move the CROSSING — which is what the
  decision rests on — was not tested.
- **The drift figures match symbols by NAME and by ordinal within a file**, and skip any symbol
  whose occurrence count changed between the two shas. A symbol that was renamed, deleted, or
  duplicated is invisible to that measurement, so the moved-fraction is a floor rather than a count.
- **I did not run the lint on a checkout without `vendor/`.** The degradation described in §5 is
  read off the code path, not observed.
- **`bin/quality` end-to-end**: recorded in §11, which is written after the pre-push hook runs it.
  Before the push, what had been run locally was `pest --group=arch` (94 passed, 484 assertions),
  `pest tests/Arch/CitationLintCoverageTest.php` (13 passed), `pest
  tests/Feature/Quality/QualityStepCountTest.php` (1 passed), and Pint in check mode over the seven
  changed PHP files.
- **No claim is made that the 163 baseline keys are the complete set of citations in the repo.**
  They are the complete set the ticket's regex finds in the scanned dirs, which excludes `docs/`,
  `resources/`, and every extensionless path.
