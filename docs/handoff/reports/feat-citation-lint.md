# `feat/citation-lint` — a new `path:LINE` citation must name a symbol, and the symbol must be there

Branch off `origin/staging` at `06cd9a3`. Closes the tier question left open in
`docs/handoff/tickets/stale-path-line-citations.md`, which has recorded the same defect six times
across four branches.

**What shipped:** `bin/ci-citation-lint.php`, `citation-lint-baseline.txt` (170 keys, 187
citations), `tests/Arch/CitationLintCoverageTest.php` (22 arms), `bin/citation-window-measure.php`
(the committed extractor behind §2, not wired into any gate), `bin/quality` step 13 of 16, and the
ticket amended to say a mechanism now exists.

**A cold review returned nine findings; all nine are fixed in the second commit on this branch.**
§12 carries them, the review's own stated limits, and two process items. Sections 1-10 have been
brought into line with what the code does now rather than left as the pre-review text.

---

## 1. The rule

A citation is a `path:LINE` token matching the ticket's own regex, reused verbatim:

```
#(?<![\w/.-])([A-Za-z0-9_][A-Za-z0-9_./-]*\.(?:php|ts|tsx|js|jsx|md|sh|sql|json|xml)):(\d+)#
```

It is COMPLIANT when it names the symbol it points at, in either of the two spellings this
repository already uses, and that symbol is really there:

```
app/Support/ActiveSchool.php:66 (getOrFail)      symbol-last
getOrFail (app/Support/ActiveSchool.php:66)      symbol-first
app/Support/ActiveSchool.php with a bare line and no symbol   not compliant
```

"Really there" means either of two things, and the second is what lets a citation point INSIDE a
method:

1. the symbol occurs, as a whole word, within ±3 lines of the cited line; or
2. the symbol IS the nearest preceding declaration to the cited line.

A range is checked at its START line. Widening the window to the whole range, or anchoring at the
end, would make a long range self-approving — both are pinned by arms d2/d3.

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

**The extractor is committed**: `bin/citation-window-measure.php`, three modes
(`discrimination`, `drift <sha>`, `nearest`). Nothing in `bin/quality` runs it; it is an instrument.
It is committed because the first version of this report DESCRIBED the extraction in prose, and a
cold review re-implementing it from that prose reproduced every percentage, every percentile and the
crossing exactly while getting different DENOMINATORS. The gap was an unstated choice about whether
files that did not change between the two shas contribute delta-0 rows. Both are now printed.

**Corpus**: TRACKED files in the scanned dirs with extension `php|md|ts|tsx`. **Declarations**:
`function|class|interface|trait|enum NAME`, plus SCREAMING_CASE `const NAME`. Both stated in the
script; the same regex is used by the lint, and coverage arm p asserts the two copies are identical.

**(a) Discrimination** — `php bin/citation-window-measure.php discrimination`. 3,742 adjacent-
declaration pairs across 844 files: if a citation names the WRONG neighbouring symbol, does the
window still accept it?

```
N=0   2.5%     N=1   4.1%     N=2  20.1%     N=3  23.3%
N=5  36.1%     N=8  53.7%     N=10 61.3%     N=15 73.0%
N=20 81.5%     N=30 90.1%     N=50 95.4%
gap percentiles: p10=2  p25=4  p50=8  p75=17  p90=30
```

**(b) Drift** — `php bin/citation-window-measure.php drift <sha>`, both denominators printed. The
moved population is identical between them; only the delta-0 rows differ.

```
30-day (fab302b)   ALL files present at both shas : 2,222 symbols over 598 files, 754 moved (33.9%)
                   CHANGED files only             :   942 symbols over 170 files, 754 moved (80.0%)
   N=0 0.0%  N=1 18.2%  N=2 25.2%  N=3 31.2%  N=5 42.6%  N=8 48.0%  N=10 52.1%  N=20 68.8%  N=50 86.1%
   |delta| among MOVED: p50=9  p75=29  p90=129  p95=256  p99=368

60-day (f64e771)   ALL files                      : 1,514 symbols over 374 files, 785 moved (51.8%)
                   CHANGED files only             :   982 symbols over 183 files, 785 moved (79.9%)
   N=0 0.0%  N=1 15.0%  N=2 24.8%  N=3 29.7%  N=5 37.3%  N=8 49.3%  N=10 57.1%  N=20 68.9%  N=50 82.2%
   |delta| among MOVED: p50=9  p75=32  p90=90  p95=325  p99=454
```

**The two curves cross, and that crossing is now confirmed by two independent extractions** — this
script and the cold review's own re-implementation, which agreed on every percentage and percentile
and disagreed only on the delta-0 denominator. At every N the window admits about as many WRONG
symbols as it retains DRIFTED-but-right ones: 53.7% against 48.0% at N=8; 95.4% against 86.1% at
N=50. Drift is heavy-tailed (p50 of a move is 9 lines, p90 is 129), so no window absorbs it. N is a
tolerance for jitter above a symbol, never for movement; a symbol that drifts out FAILS, on the
branch that moved the target. N=1 discriminates better (4.1% against 23.3%) and was rejected because
a one-line insert above a symbol is not a defect.

### 2.1 The second half of the rule: nearest preceding declaration

`php bin/citation-window-measure.php nearest`, run before the rule was adopted rather than after:

```
NEAREST-PRECEDING (window = 3)
  wrong-symbol adversary (body line naming the NEXT declaration), 70,778 pairs
    window only        passes 20.64%
    window OR nearest  passes 20.64%
  citations the rule is FOR (body line naming its enclosing declaration), 70,778 pairs
    window only        accepts 25.93%
    window OR nearest  accepts 100.00%
    region newly accepted: 74.07% of body lines
  declaration body length: p50=8  p75=19  p90=39  p95=64  p99=161
```

Discrimination against the same adversary the window measurement used is **unchanged to the second
decimal**, because the NEXT declaration is never the nearest PRECEDING one inside this body. The
rule was adopted on that number.

**What it gives up**: inside one declaration's body every line is equivalent, so a citation may
drift anywhere within its enclosing method and stay compliant. For half of the bodies in this tree
that flat region is 8 lines — barely wider than the window — and for one in a hundred it is 161.

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

Within `symbolIsAt()`, a qualified symbol passes when ANY of its identifiers is near, not every
one — `SubledgerPoster::post` names two and the class name is routinely not on the method's line.
Identifiers shorter than three characters are dropped for the WINDOW half; `$a` and `x` match
everywhere. The nearest-declaration half applies no length filter, because equality with a
declaration name is already unambiguous and the filter would otherwise refuse the rule's own worked
example, `ActiveSchool::id`.

### 3.1 The symbol-FIRST spelling, and why "a symbol-shaped token before the paren" is not enough

`symbolName (path:LINE)` is this repository's own house style and the first version of this lint
refused it, telling the author a citation "carries no symbol" when it carried one. Accepting a
symbol-shaped token immediately preceding the `(` is the fix — but it is NOT unambiguous, and this
tree says so. Admitting every such token admits six ordinary prose sentences:

```
throughout (`bin/quality:195`)                             .claude/skills/finance-drive/SKILL.md
RBAC change (`docs/handoff/reports/feat-rbac-…md:442-447`) .claude/skills/finance-drive/SKILL.md
no client (routes/endpoints/finance.php:222-225)           app/Finance/Http/Controllers/InvoiceController.php
no school (app/Http/Middleware/SetSchoolContext.php:51)    config/rbac.php
only on Pint (`composer.json:67,70`)                       tests/Arch/BoundaryLintCoverageTest.php
IN TESTS (phpunit.xml:45)                                  tests/Feature/Finance/OpeningBalanceOperatorScreenTest.php
```

Capitalisation does not separate them: `Pint` and `SchoolScope` are the same shape. So a leading
candidate is accepted only when it **carries a code marker** (`::`, `->`, `\`, or a trailing `()`)
**or is DECLARED in the file it points at**. `StudentCurriculum::booted()` and `ActiveSchool::id()`
qualify on the first test; `SchoolScope` on the second; none of the six on either. **With the
qualifier: 6 false positives → 0, and the two genuine stale symbol-first citations still surface.**

This is not circular. Test 2 asks whether the word names a declaration ANYWHERE in the cited file;
the compliance rule then asks whether it is at the cited LINE. A citation naming a symbol that is
USED but not DECLARED in its target is missed and reported as carrying no symbol — a wrong message
on a citation that is unverifiable either way.

---

## 4. Scope

**Scanned:** `app/ tests/ bin/ database/ config/ routes/ bootstrap/ .claude/skills/`
**Not scanned:** `docs/`

Every file in those directories is read, including comments and fenced blocks. Comment lines are
NOT skipped, unlike the sibling lints — a citation's natural home is a docblock, and skipping
comments would skip the defect.

**Why docs/ is out, and it is VOLUME, not false positives.** An earlier version of this report and
of the docblock gave the reason as pasted `grep -n` output being indistinguishable from a citation
and therefore MANUFACTURING findings. That prediction does not describe this tree — docs/
manufactures none, and the `2b3cdbb` measurement it rested on no longer describes the tree either.
The reason as written invited someone to add a fence-skipper and conclude docs/ was now safe, so it
has been replaced with the measurement. Re-derived at this commit by adding `docs` to
`SCANNED_DIRS` and running `generate`:

```
WITH docs/ IN SCOPE: 1,347 keys, 1,579 citations
  docs/ contributes: 1,177 keys, 1,392 citations
  non-docs remainder:  170 keys,   187 citations
```

docs/ is **seven and a half times** the code baseline, essentially all of it unverifiable prose and
pasted output. Skipping fenced blocks does not rescue it: of the 1,444 citation tokens in docs/,
only 372 sit inside a fence, so a prose-only baseline still opens at about 1,072. A baseline that
size is not a ratchet, it is a directory listing.

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

**Extensionless executables are now matched by name.** The path pattern needed an extension to know
a path when it sees one, so `bin/quality`, `bin/landed`, `bin/is-docs-only-push`,
`bin/quality-promote`, `bin/quality-clean-db` and `.githooks/pre-push` could never be cited or
checked — `bin/quality:99999 (thisSymbolIsNowhere)` passed. They are named in a fixed alternation
(longest first, so `bin/quality-promote` is not eaten by `bin/quality`); "any path under `bin/`"
would match prose. **Measured cost of the alternation: 11 citations found across 9 files, 0 false
positives** — every one of the 11 spot-checked as a real citation of a real executable, and all 11
baselined. That count and file spread re-derive the cold review's figure exactly.

---

## 5. Vendor: resolved, not guessed, and CONDITIONAL on the line existing

A cited path this repository does not contain is EXEMPT when it resolves under `vendor/` — by
prefix (`vendor/…`) or by path SUFFIX (`Models/Role.php`). Vendor is decided BEFORE anything is
resolved in-tree. This is the only thing that stops the lint manufacturing findings, proved by
building the resolver the ticket warns about and watching it manufacture one: with the vendor
exemption removed and the ticket's basename fallback reinstated, `Models/Role.php:186` is reported
past-EOF against the 36-line `app/Models/Role.php`, both on a planted fixture and on the real
`tests/Feature/Rbac/RbacDiffGrantsTest.php`. A line-scoped `vendor` word guard would not have been
enough: in the second shape the word "Vendor" opens the PREVIOUS line, and arm i plants both.

### 5.1 The tie-break was unconditional, and it hid a live stale citation

The first version exempted on ANY vendor suffix match. **There are three basenames in this tree
that match on both sides, not the one the first report named**, and only one of them should be
exempt:

| citation | vendor candidate | in-tree candidate | verdict |
| --- | --- | --- | --- |
| `User.php:412` | `…/Foundation/Auth/User.php`, **20 lines** — 412 cannot be in it | `app/Models/User.php`, 543 | **not exempt** |
| `Models/Role.php:186` | `…/laravel-permission/src/Models/Role.php`, 221 | `app/Models/Role.php`, 36 | **exempt** |
| `Permission.php:158` | `…/laravel-permission/src/Models/Permission.php`, 165 | `app/Enums/Permission.php`, 300 | **not exempt** |

The third was a **48-line-stale citation sitting unbaselined and unreported for the whole branch**.
`app/Finance/Http/Controllers/OpeningBalanceBatchController.php:100` cited `Permission.php:158-160`
for `FINANCE_OPENING_BALANCE_SUBMIT`. Re-derived rather than taken on report:

```
$ git show b1d5e50:app/Enums/Permission.php | grep -n FINANCE_OPENING_BALANCE_SUBMIT
158:    case FINANCE_OPENING_BALANCE_SUBMIT = 'finance.opening-balance.submit';
$ grep -n FINANCE_OPENING_BALANCE_SUBMIT app/Enums/Permission.php
206:    case FINANCE_OPENING_BALANCE_SUBMIT = 'finance.opening-balance.submit';
```

Correct at `b1d5e50` (the commit that wrote it), 48 lines off today. **The rule is now: exempt only
when SOME vendor candidate contains the cited line and NO in-tree candidate does.** When both sides
could contain it, the citation is reported `citation-not-repo-relative`, which is what the rule
already demands of every bare basename. `Models/Role.php:186` stays exempt — arm i asserts the two
file lengths that make it so, and arm i2 asserts the four that make the other two reportable, so
neither outcome rests on an assumption about somebody else's file.

**A bare basename is still never resolved against the repo.** `SeedDriveFixture.php:155` is reported
as `citation-not-repo-relative`, not resolved to `app/Console/Commands/SeedDriveFixture.php`. The
in-tree candidate list is consulted ONLY to decide whether the exemption applies; no symbol is ever
checked against a guessed target.

**Vendor/ must be installed for the exemption to fire.** `bin/quality` step 1 is dependency
integrity, so it is present wherever this gate runs. On a checkout without it a bare vendor citation
is reported as `citation-not-repo-relative` or `citation-unresolvable` — a wrong message on a
citation that is non-compliant either way, never a manufactured in-tree finding.

---

## 6. The baseline: 170 keys, 187 citations, and the key

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
arm k, bite-proved on a real tracked file.

**What this key cannot distinguish, stated rather than discovered later:**

- **Which occurrence is forgiven.** Delete a baselined bare citation and add a different new bare
  citation of the same target in the same file: the count is still 1, and it is green.
- **Where in the file the citation sits.** Keying on the citing line NUMBER was rejected for the
  reason that ticket gives: it fails on every unrelated edit above a baselined line.
- **The citing line's text.** Rewording the sentence around a baselined citation is invisible.

### 6.1 Composition, re-derived from the committed baseline

The composition block in the first version of this report was the PRE-NARROWING measurement — taken
before §3's symbol-shape narrowing moved two citations in, and then published as though it described
the committed file. Both figures, re-derived by reading the committed baselines rather than by
re-running anything:

| | at `5aeb5e6` (as committed) | at this commit |
| --- | --- | --- |
| keys / citations | 163 / 180 | 170 / 187 |
| `citation-missing-symbol` | 100 | 107 |
| `citation-not-repo-relative` | 78 | 78 |
| `citation-unresolvable` | 2 | 2 |
| keys with count > 1 | 17 | 17 |
| by citing dir | .claude 61 · app 41 · tests 40 · database 27 · bin 8 · routes 2 · config 1 | .claude 62 · app 39 · tests 43 · database 28 · bin 12 · routes 2 · config 1 |

The first report said 98 / 78 / 2 = 178, 16 keys at count 2, app 40 · database 26. Every one of
those six figures was wrong by exactly the narrowing's delta.

**The movement between the two columns is this commit's own doing**, and each part is accounted
for: +11 citations from the extensionless alternation (bin 8→12, and the rest spread), −4 from the
four citations fixed in §5.1 and §7.3, and the symbol-first and nearest-preceding rules moving
citations between `citation-missing-symbol` and compliance in both directions.

The two `citation-unresolvable` entries are `dist/index.js:2271` and `endpoints/finance.php:24`.

### 6.2 `generate` reads tracked files only

`scannedFiles()` walks the filesystem, so `generate` on a dirty tree would bake a path nobody else
has into a shrink-only baseline, and every other checkout would then fail with "fixed (good!)"
naming a file that does not exist there. `bootstrap/cache/*.php` — gitignored, generated, inside a
scanned directory — is the standing instance.

Both fixes are applied and each has its own reason: `generate` intersects with `git ls-files`, and
`bootstrap/cache/` is skipped in BOTH modes. `check` still reads the working tree, deliberately —
every coverage arm plants an untracked file and would test nothing otherwise. Arm n pins the
asymmetry in both directions, and additionally asserts that regenerating at this commit reproduces
the committed baseline byte for byte.

---

## 7. Coverage test — 22 arms, each bite-proved

`tests/Arch/CitationLintCoverageTest.php`, group `arch`. Arms plant real files, run the real
script, and assert what it reports; no matcher is re-implemented. Four arms mutate TRACKED files
(k, l, n, o), each saving the exact bytes first and restoring per path in a `finally`.

All 22 green: `tests=22 passed=22 assertions=143`.

**Nine arms are new in this commit**, and the reason is that three mutations of the lint survived
the first thirteen untouched: deleting the window's upper bound, dropping the range from the
symbol-last pattern, and anchoring a range at its END line. Ranges had zero coverage across all
thirteen.

### 7.1 The raw output the new arms assert on

Produced by planting each arm's fixture and running the real script. The target fixture is a planted
file with real method BODIES — `class` at 5, `ensureBankAccount()` at 7 (body to 19),
`laterMethod()` at 21 (body to 29), `farAwaySymbol()` at 31 — so the nearest-preceding half of the
rule can be exercised at all. `assertTargetShape()` asserts those four line numbers before every arm
that uses them, because a miscounted heredoc would make an arm test the wrong thing quietly.

**c3 — a symbol declared ABOVE the cited line that is not the NEAREST one** → exit 1. Line 27 is
inside `laterMethod()`; `ensureBankAccount` is declared at 7, above it, and is not nearest.

```
  ✗ app/Finance/CitationLintFixtureC3.php  app/Finance/CitationLintFixtureTARGET.php:27  [citation-symbol-not-found]
      names a symbol that is neither within 3 lines of the cited line nor the nearest declaration above it
```

**d3 — a range does not approve itself** → exit 1. `:7-31` spans the class; `farAwaySymbol` is at
31, inside the range, nowhere near its start.

```
  ✗ app/Finance/CitationLintFixtureD3.php  app/Finance/CitationLintFixtureTARGET.php:7  [citation-symbol-not-found]
```

**i2 — the two vendor ties that are NOT exempt** → exit 1.

```
  ✗ app/Finance/CitationLintFixtureI3.php  Permission.php:158  [citation-not-repo-relative]
  ✗ app/Finance/CitationLintFixtureI3.php  User.php:412        [citation-not-repo-relative]
```

**m — an extensionless executable, cited past its end** → exit 1. Both of these were invisible
before; `bin/quality:99999` passed.

```
  ✗ app/Finance/CitationLintFixtureM.php  .githooks/pre-push:99999  [citation-past-eof]
  ✗ app/Finance/CitationLintFixtureM.php  bin/quality:99999         [citation-past-eof]
```

**c / c2 / d2 — the three GREEN acceptances**: symbol-first `ensureBankAccount (TARGET:7)`,
nearest-preceding `TARGET:18 (ensureBankAccount)`, and the range `TARGET:7-31 (ensureBankAccount)`,
all planted in one file:

```
exit=0
citation-lint: OK — no new citation violations (170 baselined key(s), 187 citation(s)).
```

**n — `generate` reads tracked files only, `check` reads the working tree.** The arm plants an
untracked citer, asserts `check` reports it, runs `generate`, asserts the regenerated baseline does
NOT name it, and asserts the regeneration is byte-identical to the committed file — then restores.

**o — the lint reads its own file and its exemplar is compliant.** Asserts
`bin/ci-citation-lint.php` is no longer in `SELF`, that the file contains
`app/Support/ActiveSchool.php:66 (getOrFail)`, and that `getOrFail` really is on line 66 of
`ActiveSchool.php`. Then it moves the exemplar to `:99` — where `getOrFail` is not, and is not the
nearest declaration either — and asserts the lint reds ITS OWN FILE, before restoring the bytes.

**p — the measurement script and the lint share one declaration regex.** Both copies asserted
byte-identical, so the published measurement cannot silently stop describing the lint.

The nine arms carried over unchanged — a, b, d, e, f, g, h, i, j, k, l, q — keep their previous raw
output; the earlier version of this report has it, and only the rule-message wording changed.

### 7.2 Every mutation, and the arm that caught it

Each mutation was applied to `bin/ci-citation-lint.php` alone, the named arm run under `--filter`,
and the script restored from a saved copy — verified byte-identical afterwards.

| # | mutation | arm that went RED |
| --- | --- | --- |
| MX1 | `$from = max(1, $line - WINDOW)` → `$from = 1` | c3 |
| MX2 | `(?:-\d+)?` dropped from the symbol-last pattern | d2 |
| MX3 | a range anchored at its END line | d2 and d3 |
| F1a | vendor exemption made unconditional again | i2 |
| F1b | vendor exemption removed entirely | i |
| F1c | vendor removed AND the ticket's basename fallback reinstated | manufactures the finding in §5 |
| F2a | symbol-first spelling refused | c |
| F2b | every leading token accepted as a symbol | q (six prose false positives return) |
| F3 | nearest-preceding half removed | c2 |
| F5 | extensionless alternation removed | m |
| F8 | `generate` no longer restricted to tracked files | n |
| F9 | the lint exempts itself again | o |
| M1 | `citation-missing-symbol` branch sets `$rule = null` | a |
| M2 | `docs` added to `SCANNED_DIRS` | b |
| M3 | `WINDOW` 3 → 0 | c |
| M4 | `symbolIsAt()` searches the whole file | d |
| M5 | `citation-unresolvable` / `citation-not-repo-relative` dropped | e |
| M6 | past-EOF check disabled | f |
| M9 | `$count > $baseline[$key]` → `false` | k |
| M10 | shrink-lock `exit(1)` removed | l |
| M11 | `.claude/skills` removed from `SCANNED_DIRS` | j |

M1 also reds every green-asserting arm, because removing a rule makes ~90 baselined entries look
"fixed" and the shrink-lock fires. That is the ratchet working, not extra coverage; the arm named
is the one the mutation is aimed at, and each was run under `--filter` so attribution is one arm at
a time.

### 7.3 The four citations this commit fixed

Made visible by the fixes above, then re-derived by hand and corrected:

| file | was | now | why it was wrong |
| --- | --- | --- | --- |
| `app/Finance/Http/Controllers/OpeningBalanceBatchController.php` | `Permission.php:158-160` | `app/Enums/Permission.php:206-208 (FINANCE_OPENING_BALANCE_SUBMIT)` | 48 lines stale; hidden by the unconditional vendor tie-break (§5.1) |
| `app/Http/Requests/SyncUserRolesRequest.php` | `User.php:412` | `app/Models/User.php:412 (User::assignRole)` | bare basename with a 20-line vendor namesake; the line itself is correct and sits inside `assignRole()`, declared at 390 |
| `app/Finance/Actions/GenerateInvoice.php` | `StudentCurriculum::booted() (…:76-78)` | `…:80-82` | `booted()` is at 80 |
| `app/Services/GuardianService.php` | ``SchoolScope (`…SchoolScope.php:35-37`…)`` | ``SchoolScope::apply (`…:35-37`…)`` | `SchoolScope` is the class at 13; the cited lines are the `User` exemption inside `apply()`, declared at 24 |

**The fourth named stale symbol-first citation needed no edit.**
`tests/Feature/Finance/PaymentRecordGateTest.php:91` cites
`ActiveSchool::id() (app/Support/ActiveSchool.php:42)` where `id()` is declared at 28. Under the
window alone that is stale; under §2.1's nearest-preceding rule it is compliant, and correctly so —
line 42 is `if ($request->hasSession() && ($id = $request->session()->get('school_id')))`, the
session branch the citing sentence is about. Verified by reading both files, not inferred.

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

**None of the five corrections above was made by this lint at the time.** `bin/quality` was
extensionless and matched nothing under the ticket's regex. That hole is closed in this commit
(§4), and the five citations are now checked — they are baselined as `citation-missing-symbol`,
because the fix makes them visible, not correct.

`bin/citation-window-measure.php` is committed but **not wired into `bin/quality`**. It is an
instrument, not a gate: it decides a parameter and is re-run when someone wants to re-decide it. The
step count is therefore unchanged at 16.

---

## 9. What this does not cover

1. **A green does not prove the sentence is true.** A citation whose symbol appears near the cited
   line can still be wrong about what it claims. This lint checks that the pointer lands somewhere
   sane; it cannot check that the sentence is true. The ticket's instances 3 and 5 were false when
   written with the citation itself well-formed.
2. **`docs/` is unscanned.** Citations in tickets and reports are unguarded, and two of the six
   recorded instances were exactly that. §4 — and the reason is volume, so a fence-skipper does not
   change it.
3. **Only SIX extensionless paths are matched, by name.** `bin/quality`, `bin/landed`,
   `bin/is-docs-only-push`, `bin/quality-promote`, `bin/quality-clean-db`, `.githooks/pre-push`. A
   seventh added to the repo is invisible until it is added to `CITATION_RX`, and nothing fails when
   that happens.
4. **This branch left stale citations in `docs/`.** Adding a step to `bin/quality` shifted its line
   numbers by +13, which makes `bin/quality:238`, `:266`, `:259`, `:271`, `:272`, `:274` and `:213`
   stale wherever docs/ names them — `quality-gate-is-not-safe-to-run-from-two-trees.md`,
   `the-suite-runs-serial-and-nothing-makes-it-parallel.md`,
   `fresh-clone-review-needs-a-built-manifest.md`, and the citations ticket itself. They were NOT
   rewritten: several sit inside pasted command output that records a state at a named commit.
   Whoever next edits those tickets can re-derive with a +13 offset for any line at or after the new
   step.
5. **The baseline forgives a target in a file, not a sentence.** §6.
6. **The vendor tie-break can still be wrong in one direction.** When a vendor candidate contains
   the cited line and no in-tree candidate does, the citation is exempt — even if it was really
   about an in-tree file that has since shrunk past that line. That is the case the in-range test
   cannot separate from a genuine vendor citation, and it is the direction chosen deliberately,
   because the other direction manufactures findings (§5).
7. **One file is exempt from the lint entirely** — `tests/Arch/CitationLintCoverageTest.php` —
   because its fixtures are citation strings in heredocs. A genuine stale citation written there is
   not seen. `bin/ci-citation-lint.php` used to share that exemption and no longer does.
8. **The symbol check is a word match, not a parse.** A symbol name that appears inside a comment,
   a string, or an unrelated identifier within ±3 lines satisfies it. The nearest-declaration half
   is a regex over lines, not a parser: an interface method, an abstract signature and a real
   implementation are the same to it.
9. **A symbol-first citation naming a symbol that is USED but not DECLARED in its target is
   reported as carrying no symbol** (§3.1), which is a wrong message on an unverifiable citation.
10. **Nothing here proves the baselined 187 are correct.** They are recorded, not verified. Burning
    one down means giving it a symbol and re-deriving the line — and §5.1 is what one of them turned
    out to be worth.

---

## 10. What I could not verify

- **The two curves in §2 are properties of this tree at this commit**, measured with a declaration
  regex that does not see PHP properties, arrow-function assignments, or TS/TSX declaration forms
  other than the listed keywords. A different extraction would move the percentages; the CROSSING
  survived one independent re-extraction (the cold review's), which is evidence but not proof that
  it survives any.
- **The drift figures match symbols by NAME and by ordinal within a file**, and skip any symbol
  whose occurrence count changed between the two shas. A renamed, deleted or duplicated symbol is
  invisible to that measurement, so the moved fraction is a floor rather than a count. This is now
  stated in the committed extractor as well as here.
- **§2.1's nearest-preceding measurement uses the same declaration extractor**, so its 20.64%
  figures inherit exactly the same blind spots. The claim that the two numbers are equal is
  structural — the NEXT declaration is never the nearest PRECEDING one inside this body — and the
  measurement confirms rather than establishes it.
- **I did not run the lint on a checkout without `vendor/`.** The degradation described in §5 is
  read off the code path, not observed.
- **The 11 extensionless citations were spot-checked, not exhaustively re-derived.** Three of the
  eleven were opened and read; the other eight were confirmed only as `citation-missing-symbol`,
  which means the file resolved and the line exists — not that the line is the right one.
- **No claim is made that the 170 baseline keys are the complete set of citations in the repo.**
  They are the complete set the pattern finds in the scanned dirs, which excludes `docs/`,
  `resources/`, and every extensionless path not on the six-name list.

---

## 11. The gate, and the remote read back

`bin/quality` ran under `.githooks/pre-push` on `4c01086` and passed 16/16:

```
[13/16] citation lint (a new path:LINE citation names a symbol, and the symbol is there)
   ✓ citation-lint
...
✓ quality: PASS — per-push floor. Promoting to main? run bin/quality-promote.
```

Step 3 reported `Pint (check) on 7 changed PHP file(s)`, which is the seven this branch touches.

Read back from the remote rather than from the push output:

```
$ git fetch origin
$ git rev-parse HEAD                       4c01086c90f48a9f3e01b67fdbc074d3ab9ff358
$ git rev-parse origin/feat/citation-lint  4c01086c90f48a9f3e01b67fdbc074d3ab9ff358
$ git diff --stat HEAD origin/feat/citation-lint     # empty
```

A PASS here is the per-push floor and nothing more: one PHP version, one machine, one MySQL, and
`bin/quality` has produced both PASS 16/16 and a red on byte-identical code before (ADR 0053).

### 11.1 The cold-review fixes

`bin/quality` ran again under the hook on `59c4f99`, the commit carrying all nine findings, and
passed 16/16:

```
[13/16] citation lint (a new path:LINE citation names a symbol, and the symbol is there)
   ✓ citation-lint
[14/16] architecture tests (§17.1)                     ✓ arch      (103 tests, 564 assertions)
[15/16] static analysis (Larastan level 5 vs baseline) ✓ larastan  (0 errors)
[16/16] tests (failure ratchet vs tests/ratchet-baseline.txt)  ✓ test-ratchet
✓ quality: PASS
```

Step 3 reported `Pint (check) on 12 changed PHP file(s)`. Read back from the remote by sha rather
than from the push output:

```
$ git rev-parse HEAD                       59c4f990dafb1610ed108f48f2afcd2bf5655919
$ git rev-parse origin/feat/citation-lint  59c4f990dafb1610ed108f48f2afcd2bf5655919
$ git diff --stat HEAD origin/feat/citation-lint     # empty
```

The same caveat applies, and one more: the arch group grew from 94 to 103 tests with this branch's
arms, so a green there is a statement about 22 citation-lint arms among them, not about the arms
being the right ones. §7.2 is the part that argues they are.

---

## 12. The cold review

Nine findings, all FIX. Severities are as the review set them; they are carried, not re-ranked, and
nothing below re-orders or re-weighs them.

| # | finding | what changed |
| --- | --- | --- |
| F1 | the vendor tie-break is unconditional, there are three ties, and one hides a live stale citation | the exemption is now conditional on the cited line being IN RANGE in a vendor candidate and out of range in every in-tree one; the 48-line-stale citation is fixed; arms i and i2 |
| F2 | the symbol-first form is refused and the refusal message is false | both spellings read; a leading candidate must carry a code marker or be declared in the target; two stale symbol-first citations fixed; arm c |
| F3 | the rule forces every citation onto a declaration line | nearest-preceding declaration accepted as the second half of the compliance rule, adopted on a published measurement; arms c2 and c3 |
| F4 | three mutations no arm catches, one of them near-vacuous | arms c3, d2 and d3; all three mutations now red |
| F5 | extensionless cited paths are invisible | six named executables in `CITATION_RX`; 11 citations across 9 files now checked, 0 false positives; arm m |
| F6 | §6's composition block is the pre-narrowing measurement | §6.1 re-derived from both committed baselines; the docs/ exclusion reason replaced with the volume measurement, in the report and in the docblock |
| F7 | §2's denominators are unreproducible, and the method is described rather than committed | `bin/citation-window-measure.php` committed; both windows re-run with it; both denominators published; arm p pins its regex to the lint's |
| F8 | untracked files enter a shrink-only baseline | `generate` intersects with `git ls-files`; `bootstrap/cache/` skipped in both modes; `check` still reads the working tree; arm n |
| F9 | the canonical example of a compliant citation is not compliant | exemplar corrected to `:66`; the lint no longer exempts its own file, so the exemplar is enforced; arm o |

### 12.1 Every claim the review made that this branch re-derived

Nothing below was taken from the review's text.

- **F1's stale citation.** `git show b1d5e50:app/Enums/Permission.php` puts
  `FINANCE_OPENING_BALANCE_SUBMIT` at :158; today it is at :206. 48 lines, and `b1d5e50` is
  "feat(finance): the platform issues the opening-balance import template", the commit that wrote
  the citing file.
- **F1's three ties and their four line counts.** vendor `Auth/User.php` 20 · `app/Models/User.php`
  543 · vendor `Models/Role.php` 221 · `app/Models/Role.php` 36 · vendor `Models/Permission.php`
  165 · `app/Enums/Permission.php` 300. Arms i and i2 assert them.
- **F2's three stale symbol-first citations.** All three read at both ends; two needed an edit and
  the third is resolved by F3's rule rather than by an edit (§7.3).
- **F5's count.** 11 citations across 9 files, listed and spot-checked.
- **F6's baseline figures.** The committed `5aeb5e6` baseline really is 100 / 78 / 2 = 180, 17 keys
  at count 2, app 41 · database 27 — and the first report said 98 / 78 / 2 = 178, 16, app 40 ·
  database 26.
- **F7's denominators.** The committed extractor reproduces both of the review's numbers and both of
  the report's: 942 and 982 on changed files only, 2,222 and 1,514 over all files present at both
  shas, with 754 and 785 moved in every case.
- **F9's exemplar.** `getOrFail` is at `app/Support/ActiveSchool.php:66`; `:99` is `runFor`.

### 12.2 The review's limits, in its words

The review recorded that it **did not run `bin/quality` end to end (no MySQL available)**, **did not
run the non-arch suite**, **used its own reading of §2's described regex** rather than a committed
extractor — which is F7 and is the reason the extractor is now committed — and **disclosed two
harness injections it did not use**.

### 12.3 Two process items

**The `npm install` instruction.** The review's environment produced a stray `package-lock.json`,
from a review instruction that said `npm install`. This repository is pnpm: `pnpm-lock.yaml` is the
committed lockfile, `bin/quality:129` refuses to run without `node_modules/` and tells you to run
`pnpm install`, and `bin/quality:195` is `check "build" pnpm run build`. No `package-lock.json`
exists in this tree — confirmed at this commit — so nothing had to be reverted here; the instruction
is what needs correcting, wherever it lives.

**The branch's subject fired on the branch.** The lint shipped with a worked example that failed its
own rule by 33 lines, in the one file it exempted from itself, and shipped a vendor tie-break that
hid a citation 48 lines stale — while its own §9 listed nine things it did not cover and neither of
these was among them. Four stale citations were fixed in this commit and four more of the class were
created and corrected within the branch (§8's `+13` shift). That is the ticket's own defect class
firing on the mechanism built to catch it, and it belongs in
`docs/handoff/tickets/stale-path-line-citations.md` as part of the entry that says a mechanism now
exists — recorded there, not only here.
