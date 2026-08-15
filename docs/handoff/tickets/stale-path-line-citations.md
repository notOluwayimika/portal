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

A census over the tree at `a4524be` — every `path.ext:LINE` token in `.php`, `.ts`, `.tsx`, `.js`,
`.md` and `.sh` files, excluding `vendor/`, `node_modules/`, `public/build/`, and the
wayfinder-generated `resources/js/actions/` and `resources/js/routes/`:

```
citations found (path:LINE)      : 1074
  path not resolvable            : 59
  resolvable                     : 1015
    line within file length      : 1012
    line PAST end of file        : 3

  ✗ tests/Feature/Rbac/RbacDiffGrantsTest.php:173  cites Models/Role.php:186  (file is 36 lines)
  ✗ docs/handoff/reports/feat-opening-balance-import-staging.md:630  cites ImportOpeningBalances.php:506  (file is 447 lines)
  ✗ docs/handoff/reports/rbac-diff-grants.md:429  cites Models/Role.php:186  (file is 36 lines)
```

All three confirmed by hand: `app/Models/Role.php` is 36 lines, `app/Finance/Console/ImportOpeningBalances.php`
is 447.

**Read that as the argument against tier 1 being the whole answer.** 1012 of 1015 resolvable
citations already pass the cheap check. Every one of the seven real defects listed above would have
passed it too, because each pointed at a line that exists. A gate whose baseline is 3 and whose
recall on the actual failure mode is 0 is a green that means nothing.

## What a lint could check — three tiers, weakest first

### Tier 1 — the cited file exists, and the line is within its length

State-based, repo-wide, no `$BASE` needed. Shape is `bin/ci-money-lint.php`: regex over source, a
committed shrink-only baseline for the exceptions.

- **Catches:** a file renamed or deleted out from under a citation, and a citation into a file that
  has since shrunk past the cited point.
- **Cannot catch:** the failure this ticket is about. A shift inside the file is invisible.
- **Cost:** 59 unresolvable paths to triage first. Most are not defects — a bare `Role.php:186`
  cannot be resolved without a path, and reports quote raw `grep -n` output that looks identical to
  a citation (`docs/handoff/reports/fix-u8-reduction-guard-field-errors.md:711-713` is literally
  pasted grep output). A resolver that guesses by basename will produce false positives; one that
  requires a repo-relative path will silently ignore most citations in prose.
- **Verdict:** worth having, not worth having alone.

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
