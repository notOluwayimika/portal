# A baselined `path:LINE` citation can go stale and the citation lint cannot fail on it

**Status:** open, not implemented. Raised on `test/js-runner-vitest-money-format`, which caused the
drift AND measured it. The weakness is in `bin/ci-citation-lint.php` and predates the branch; the
branch is the witness, not the cause of the rule.

## The fact

`bin/ci-citation-lint.php` exists to catch exactly one kind of drift: a `path:LINE` citation whose
target has moved, so the line it names no longer holds what it claims. Its baseline is shrink-only —
a run fails on keys not already in it, and removing one is reported as progress.

**A baselined citation is exempt from the rule it is baselined against, permanently.** A baselined
citation that goes stale produces no finding, because it produces no NEW key. That is not an
implementation gap; it is what a shrink-only baseline means. The lint is state-based precisely so a
citation broken by a *different* branch fails on that branch (its own header says so) — it already
reasons about drift caused elsewhere. It cannot reason about drift in a line it was told to ignore.

## Measured, not hypothesised

Three citations to `bin/quality`'s own lines lived in files the lint DOES scan, all three baselined
as `citation-missing-symbol` (written before the symbol form existed):

```text
.claude/skills/finance-drive/SKILL.md      bin/quality:195
tests/Arch/BoundaryLintCoverageTest.php    bin/quality:251
tests/Arch/SqlClockLintCoverageTest.php    bin/quality:251
```

Commit `66cc22b` added seven lines to `bin/quality`'s header comment and appended step 17. Every
line below the header moved by seven, and each cited line came to hold something else:

```text
                    what the sentence claims        what the line held after 66cc22b
bin/quality:195     `pnpm` throughout               #    dropped a `/>` and `)}`; all eleven previous
bin/quality:251     pest --group=arch               #     NOT ADDED AT ZERO, unlike the sql-clock lint
bin/quality:279     plain pest                      QUALITY_ARTEFACTS="${TMPDIR:-/tmp}/quality-runs"
```

The three targets had moved to `:202`, `:258` and `:286`. Two of the three citations now pointed at
comment prose about an unrelated step; the third at a shell assignment.

**`bin/quality` ran to completion twice on that commit — by hand, and again through the pre-push
hook — and the citation lint was green both times.** Nothing in seventeen steps said a word.

The commit after it moved them another seven lines (`:209`, `:265`, `:293`) — the drift compounds,
and each round is as silent as the last.

They were re-derived by hand in the following commit and given symbols, which is the only reason
they are correct now: `bin/quality:209 (build)`, `bin/quality:265 (arch)`. Giving them a symbol also
took them OUT of the baseline (three entries removed, 165 keys from 168), so those three are now
live: the symbol has to sit within three lines of the cited line, which a shift of seven breaks, so
the next drift fails the gate instead of passing it. **That is a fix for three citations, not for the rule** —
the remaining baselined entries are exactly as exempt as they were.

## A second, quieter half: a bare `:LINE` is not a citation at all

Both test files pair their `bin/quality:251` with a companion written as `` `:279` `` — the path
elided because the sentence already named it one clause earlier. The lint's pattern requires
`path:LINE`, so a bare `` `:279` `` is invisible to it: not baselined, not checked, not fixable by
the burn-down. Both of those went stale in the same commit and remain uncheckable. Any count of
"how many citations does this repository guard" is an overcount by however many of these exist.

## Why the obvious fix is not obviously right

Re-validating every baselined citation on every run would make the baseline mean "known-stale, still
tolerated" instead of "checked once, never again" — which is what a reader currently assumes, and is
the stronger claim. But it converts 165 keys from silent into a wall of findings on the first run,
most of them someone else's to fix, and a wall of findings on an unrelated branch is how a gate gets
bypassed rather than satisfied.

Middle options, none costed here:

- **Re-validate baselined citations as a WARNING** — visible, non-blocking, and therefore also
  ignorable.
- **Re-validate only citations whose TARGET file is in the diff.** Cheap, and catches this exact
  case: the branch editing `bin/quality` is the branch that should hear about `bin/quality:LINE`
  citations. Misses drift from a branch touching neither file, which is what the state-based design
  exists to catch — a partial retreat from the current shape.
- **Ratchet the count** rather than the set, so staleness surfaces as a number going up.
- **Teach the pattern the bare `:LINE` form**, resolving the path from the nearest preceding
  citation in the same comment. Independent of the baseline question and probably cheaper.

## What it already cost

The vitest step was appended as `bin/quality` step 17 — after the ~6-minute Pest suite — rather than
placed beside the other `resources/js` steps at 4-5 where its ~1s runtime belongs, because appending
moves fewer of these. With the staleness visible the choice would have been "insert, then fix the
citations the lint names"; invisible, the choice was "insert, and hope someone re-derives five line
numbers by hand" — and the measurement above is what happens when nobody does. See
`docs/handoff/tickets/the-vitest-step-is-in-the-wrong-place-in-bin-quality.md`.

## Not proposed here

Which option, whether the baseline is re-derived, and what happens to the 165 keys on the first run
are all open. The one claim this ticket makes is that "the citation lint is green" means "no NEW
citation is stale", and that several places in this repository read it as the stronger statement.
