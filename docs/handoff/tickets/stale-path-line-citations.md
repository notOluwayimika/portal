# TICKET — `path:LINE` citations in comments and docs go stale silently; a gate proposal

**Status:** open, not implemented. Raised by `feat/u8-invoice-modal-discount-policy` (U8 commit 5),
which corrected four of them and is the **third consecutive branch** to correct at least one. The
lint sketched below is deliberately NOT built in that commit: it needs its own branch, its own
measurement and its own coverage test, on the model of `bin/ci-sql-clock-lint.php`.

This is a gate proposal, not a complaint about tidiness. The house rule is that a convention with no
mechanism behind it is wallpaper, and "cite accurately" has been exactly that for three branches
running.

## The recurring failure, three instances

**1. `chore/finance-drive-skill`.** Three citations pointing into a file the same commit shifted.
That report's own sweep section is the sharpest statement of why the obvious check does not work:

> **46 `path:LINE` tokens, 0 missing, 0 out of range.** The earlier sweep checked only that a cited
> line exists inside a file of that length; it could not have caught any of group A, because a
> citation shifted by five lines still lands inside the file.
> (`docs/handoff/reports/chore-finance-drive-skill.md:419-423`)

**2. `fix/u8-reduction-guard-field-errors`.** Two claims about `proof 12`, in a docblock, asserting
that `ReductionEnforcementTest`'s `proof 12 (DB)` reached the trigger. It did not — it passed a
policy id where the arm needed null (`docs/handoff/reports/fix-u8-reduction-guard-field-errors.md:30-31`,
`:372-373`). Not a line number that drifted; a *claim attached to* a cited symbol that was false when
written.

**3. `feat/u8-invoice-modal-discount-policy`.** Four, found by a cold review and a sweep:

Located by symbol rather than by line, deliberately: a table of stale line numbers that carries
line numbers into files this same commit edits is the defect writing itself down again.

| Where | Cited | Actual | Was it this branch's doing? |
| --- | --- | --- | --- |
| `ReductionEnforcementTest`, the `proof 12b (DB)` arm's opening comment | `new-invoice-modal.tsx:135-138` "sends … no discount_policy_id whatsoever" | `wireLine()` sends it on every reduction | **Yes** — this branch falsified it and left it standing |
| `docs/handoff/tickets/no-javascript-test-runner.md`, "The example this ticket is named after" | `new-invoice-modal.tsx:55-98` | `errorLinesFrom` | **Yes** — born stale in the same commit that wrote both files |
| `ReductionPreCheckTest`, the `rpcPostForStudent()` docblock | `new-invoice-modal.tsx:133` | the `axios.post` is far below that | **No** — already wrong at the base commit |
| `ReductionPreCheckTest`, the same docblock | `InvoiceController.php:83` | neither of the two `assertDiscountPoliciesUsable()` call sites is there | **No** — already wrong at the base commit |

Two of the four were written wrong by the commit that introduced them, before any drift could occur.
That distinction matters for what a gate can catch, below.

## The measurement

**Corrected, and the correction is itself an instance of the class.** The figures first published
here — 1074 / 59 / 1015 / 1012 / 3 — were labelled "a census over `a4524be`" and were not: they were
taken on a working copy that already carried this round's uncommitted files, so the number sat
between the two commits it could have belonged to. A cold review re-ran it, got different figures
under two resolvers, and diagnosed exactly that. Re-taken below on clean `git worktree` checkouts of
named shas.

**The rule, stated so the number is re-derivable** (it was not, before):

- **Regex:** `#(?<![\w/.-])([A-Za-z0-9_][A-Za-z0-9_./-]*\.(?:php|ts|tsx|js|jsx|md|sh|sql|json|xml)):(\d+)#`
- **Scanned:** files tracked by `git ls-files` with extension `php|ts|tsx|js|jsx|md|sh`.
- **Excluded:** `vendor/`, `node_modules/`, `public/`, `storage/`, `resources/js/actions/`,
  `resources/js/routes/`.
- **Resolution:** (1) the cited string taken as repo-relative is a tracked file → resolved;
  (2) else exactly one tracked path ends with `/`+basename → resolved; (3) else unresolvable.
- **Vendor guard:** a citation on a line whose text contains `vendor` is counted separately and
  **never** basename-resolved. See the triage below for why that clause exists.
- **Past-EOF:** resolved, and the cited line number exceeds `wc -l` of the resolved file.

```
$ php census.php <clean checkout of a4524be>
citations matched                 : 1010
  line mentions 'vendor' (skipped): 28
  unresolvable                    : 44
  resolved                        : 938
    line within file              : 936
    line PAST end of file         : 2

  ✗ docs/handoff/reports/feat-opening-balance-import-staging.md:630  cites ImportOpeningBalances.php:506  → resolved app/Finance/Console/ImportOpeningBalances.php (447 lines)
  ✗ docs/handoff/reports/rbac-diff-grants.md:429  cites Models/Role.php:186  → resolved app/Models/Role.php (36 lines)
```

### Past-EOF triage — 1 real, 2 manufactured by the method

The three hits first published here were said to be "confirmed by hand". Two of them were not
findings at all, and the confirmation confirmed the length of a file the citation does not point at:

| Hit | Verdict |
| --- | --- |
| `feat-opening-balance-import-staging.md:630` → `ImportOpeningBalances.php:506` | **Real.** `app/Finance/Console/ImportOpeningBalances.php` is 447 lines. No vendor path involved. |
| `RbacDiffGrantsTest.php:173` → `Models/Role.php:186-188` | **Manufactured.** The citing line reads "findByParam (**vendor** Models/Role.php:186-188)". `vendor/spatie/laravel-permission/src/Models/Role.php` is **221** lines and `:186-188` is exactly the `findByParam` team-scoping block the sentence describes. The census excludes `vendor/`, so the basename fell through to `app/Models/Role.php` (36 lines) and invented the hit. |
| `rbac-diff-grants.md:429` → `Models/Role.php:186-188` | **Manufactured, same way** — and this one the review did not name. The word "Vendor" opens the *previous* line, so a line-scoped vendor guard still misses it. Same vendor file, same 221 lines, same real target. |

The corrected census above carries the vendor guard, which is why it reports 2 rather than 3 — and
why the second manufactured hit still survives it. **A line-scoped guard is not sufficient**; nothing
short of resolving against `vendor/` as well would have been.

### The self-reference cost, measured

Quoting census output into a ticket **creates `path:LINE` tokens**. At `2b3cdbb` the same census
reports:

```
$ php census.php <clean checkout of 2b3cdbb>
citations matched                 : 1117
  line mentions 'vendor' (skipped): 28
  unresolvable                    : 45
  resolved                        : 1044
    line within file              : 1035
    line PAST end of file         : 9
```

**9 past-EOF, of which 7 are this branch's own two files** — four in this ticket (`:56`, `:57`,
`:58`, `:79`) and three in `docs/handoff/reports/feat-u8-invoice-modal-discount-policy.md`
(`:920`, `:921`, `:922`), every one of them a quotation of the very output above. Two pre-existed,
and one of those two is itself manufactured.

So the branch proposing this check is, at the moment it proposes it, **the largest single source of
violations under it** — and 4 of its 7 are quotations of hits that are not defects. A lint written
without an answer for quoted output would open on a baseline dominated by its own documentation.

**Read that together with the headline.** 1035 of 1044 resolvable citations already pass the cheap
check, all seven real defects across three branches would have passed it too, and the only failures
it does surface are two artifacts of its own resolver plus seven self-quotations. That is the case
against tier 1 as a standalone gate, stated in its own numbers.

## What a lint could check — three tiers, weakest first

### Tier 1 — the cited file exists, and the line is within its length

State-based, repo-wide, no `$BASE` needed. Shape is `bin/ci-money-lint.php`: regex over source, a
committed shrink-only baseline for the exceptions.

- **Catches:** a file renamed or deleted out from under a citation, and a citation into a file that
  has since shrunk past the cited point.
- **Cannot catch:** the failure this ticket is about. A shift inside the file is invisible.
- **Cost, and both halves were paid in this very ticket:**
  - **44 unresolvable paths** to triage first. Reports quote raw `grep -n` output that is
    indistinguishable from a citation — `docs/handoff/reports/fix-u8-reduction-guard-field-errors.md`
    carries three pasted grep lines of exactly that shape.
  - **AN EXCLUDED DIRECTORY IS A FALSE-POSITIVE GENERATOR.** This is the mechanism, not a caveat: the
    scan excludes `vendor/`, so a citation into `vendor/spatie/.../Models/Role.php` cannot resolve
    where it points, the basename fallback retargets it at `app/Models/Role.php`, and the lint
    reports a defect that does not exist. Two of the three hits first published here were made this
    way. A resolver that guesses by basename manufactures findings; one that demands a repo-relative
    path ignores most citations in prose. There is no third option that is free.
  - **QUOTED OUTPUT IS INDISTINGUISHABLE FROM A CITATION**, so any document discussing citations —
    including this one — adds violations. 7 of the 9 past-EOF hits at `2b3cdbb` are self-quotations.
  - False positives are what kill a lint nobody keeps. Tier 1 opens with a baseline in which the
    majority of entries are its own artifacts and its own documentation.
- **Verdict:** worth having, not worth having alone, and not worth having before the resolver
  question is settled.

### Tier 2 — a citation INTO a file this commit modified must have been re-derived in this commit

Diff-aware, takes `"$BASE"`. The in-repo precedent for a lint that *cannot* be asserted from state
and therefore reads the diff is `bin/ci-grants-convergence-lint.php` (`bin/quality:213`), whose own
comment says the diff is the only place the invariant is visible.

The rule: for every file `F` in `git diff --name-only "$BASE"...HEAD`, find every `F:LINE` citation
anywhere in the tree; each such citing line must itself appear in the diff. If you moved the target,
you must have touched the sentence that points at it.

- **Catches:** instance 1 (all three), and instance 3's first two rows — the ones this class is
  actually made of.
- **Cannot catch, and this is the honest limit:** it proves you *looked*, never that you were
  *right*. Instance 3's rows 3 and 4 were written wrong in the same commit that wrote them; the
  citing line was in the diff, so tier 2 passes them. It also cannot see a citation into a file that
  a *different, later* commit moves — the citing file is untouched, so nothing is in the diff.
- **False positives, real and unbounded:** touching a file for an unrelated reason flags every
  citation into it from anywhere. `new-invoice-modal.tsx` alone is cited from four places. This
  needs either a shrink-only baseline or a per-line escape comment, and an escape comment nobody
  audits is how a lint becomes decoration.
- **Verdict:** the tier that would have paid, and the tier that needs the most design.

### Tier 3 — citations name a symbol, and the symbol is checked against the line

Change the citation *form* so it is mechanically verifiable: `file.php:LINE (symbolName)`, and the
lint asserts `symbolName` occurs within ±N lines of `LINE` in that file.

- **Catches:** everything above, including a wrong-when-written number, because the check is against
  content rather than against a diff.
- **Cost:** a new convention applied to 1074 existing citations, or a cutover date and a
  grandfathered baseline. Large.
- **Verdict:** the only tier that verifies rather than nags. Probably too large as a first step.

### The cheapest alternative, named because it may be the right one

**Ban line numbers in new comments; cite symbols only.** `errorLinesFrom in new-invoice-modal.tsx`
never goes stale, needs no lint beyond a regex refusing new `path:LINE` tokens in `app/` and
`tests/`, and costs a reader one `grep`. It gives up precision inside long files, which is real —
several citations in this codebase point into the middle of a 400-line migration where the symbol
name alone would not locate the arm.

This is not obviously worse than tier 3 and is an order of magnitude cheaper. It should be priced
against tier 3 before either is built.

## What the sql-clock precedent says about how to build this

Read `bin/ci-sql-clock-lint.php`'s docblock before writing any of the above. Four things it did that
this lint will need:

1. **Measured before deciding scope.** Its `tests/` exclusion is backed by a count (27 findings, not
   one of them the defect) and a structural argument, not a preference. Tier 1's 59-unresolvable and
   tier 2's false-positive rate need the same treatment before either ships.
2. **Narrowed the matcher until the false positives were gone, and recorded the numbers both ways**
   — 25 findings without a token boundary, 0 with it. The `path:LINE` regex has the same problem: it
   matches pasted `grep -n` output, `file.php:12:` prefixes in quoted logs, and URLs.
3. **Wrote down what a green does NOT prove.** Any version of this lint must carry the sentence "a
   citation that is inside the file and inside the diff can still be wrong."
4. **Shipped with a coverage test that plants the violations** — `tests/Arch/SqlClockLintCoverageTest.php`.
   Without one, this lint joins the six that were reported green while blocking nothing.

## Not proposed here

Which tier, whether it becomes `bin/quality` step 16, whether it is baselined or ratcheted, and what
happens to the 59 unresolvable and 3 past-EOF citations already in the tree. The one claim this
ticket makes is that three consecutive branches have shipped a stale citation, each was found by a
human or a cold review rather than by anything automatic, and the cheap check that looks like it
would have helped demonstrably would not have.
