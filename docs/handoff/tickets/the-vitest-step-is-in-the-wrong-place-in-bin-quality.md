# The vitest step is `bin/quality` 17, after the six-minute suite, when it costs about a second

**Status:** open, not implemented. Recorded by the branch that added the step
(`test/js-runner-vitest-money-format`), which chose the position and is naming its own cost rather
than leaving the next reader to wonder why the cheapest step in the gate runs last.

## The fact

`bin/quality` runs cheap and structural first, expensive last — dependency integrity at 1, the full
Pest suite at 16. Step 17, `javascript tests (vitest …)`, runs in about a second and sits after the
suite:

```text
[16/17] tests (failure ratchet vs tests/ratchet-baseline.txt)      ~6 min
[17/17] javascript tests (vitest — the only step that executes application JS)   ~1 s
```

By runtime and by subject it belongs at 4-5, beside the other steps that read `resources/js`:
`lint changed files` (3), `types (tsc ratchet)` (4), `frontend build` (5). A JavaScript unit test
that reds is currently discovered **after** a six-minute wait that had nothing to do with it, on
every push that touches the frontend.

## Why it was appended instead

Inserting a step at position 6 renumbers everything after it. That is not a `sed` — the numbers live
in prose that no gate reads:

**In `bin/quality` itself:** the `[%d/17]` literal (mechanised — `QualityStepCountTest` ties it to
the `step()` call count), the `# 6.`-style block headers, and every sentence naming a step by number
("`step 15`'s phpstan result cache", "steps 2..15 below", "a broken migration still fails step 16").
`QualityStepCountTest`'s own docblock says this half stays unmechanised and must be moved by hand.

**Outside it:** citations of the form `bin/quality:LINE` in `tests/` and `.claude/skills/`, plus
step-number prose in four test files and a dozen documents. Three of those citations were baselined
in the citation lint at the time, which means **stale is indistinguishable from correct** — see
`docs/handoff/tickets/a-baselined-citation-can-go-stale-and-no-lint-notices.md`, where exactly that
happened, twice, measured, through two green gate runs.

Appending moved none of it. The trade was: one avoidable six-minute wait per frontend red, against a
renumbering whose failure mode is silent.

## What has changed since, and what it buys the move

The three `bin/quality:LINE` citations have since been re-derived and given symbols
(`bin/quality:209 (build)`, `bin/quality:265 (arch)`), which removed them from the citation
baseline. They are now **live**: the named symbol must sit within three lines of the cited line, so
the shift an inserted step causes fails the citation lint instead of passing it silently. Whoever
does the move gets told about those three by the gate.

They will not be told about:

- the bare `` `:293` ``-style companions in the same two docblocks — no path, so not citations as
  far as the lint's pattern is concerned;
- any `step N` prose anywhere, in `bin/quality` or outside it;
- documents under `docs/`, which the citation lint does not scan at all.

## What the change is

1. Move the `step "javascript tests …"` / `check "vitest" …` pair from after the test-ratchet check
   to immediately after step 5's `check "build" pnpm run build`.
2. Renumber the `# N.` block headers below it.
3. Re-derive every `step N` sentence in `bin/quality` — including the two corrected on this branch,
   which were themselves stale (one for longer than the vitest step has existed).
4. Re-derive the `bin/quality:LINE` citations; the citation lint names three of them and misses the
   bare-`:LINE` companions, so grep for `` `:` `` in the same docblocks by hand.
5. Re-derive step-number prose in `tests/Arch/BoundaryLintCoverageTest.php`,
   `tests/Arch/SqlClockLintCoverageTest.php`,
   `tests/Feature/Quality/PestNegatedExpectationMessagesTest.php`,
   `tests/Feature/Finance/PaymentProvenanceTest.php`, and the tickets and reports under `docs/` that
   name one.

`QualityStepCountTest` will catch a printf that does not match the `step()` count. Nothing will catch
any of the rest, which is the whole reason this is its own branch: a purely mechanical change, with
a diff a reviewer can read as mechanical, is checkable by eye in a way the same edits folded into a
feature commit are not.

## Not proposed here

Whether it moves to 6 or elsewhere among the `resources/js` steps, and whether the step-number prose
should be mechanised rather than moved again, are open. This ticket claims only that the current
position was chosen for the cost of moving it and not for anything about the step.
