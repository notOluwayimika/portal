# `ci-citation-lint` is absent from the local-gate list, and its failures point at somebody else's test

**Status:** open · **Opened:** 2026-09-06 · **Found by:** the void-producer pin
(`feat/pin-the-single-writer-of-invoice-void`), whose first draft tripped this lint · **Severity:**
fix — (b) is a small, precise change; (a) is a documentation decision

Two halves. They compound: a gate you were not told to run, failing in a way that does not name your
file, costs the reader the exact time the gate exists to save.

## (a) The list at `CLAUDE.md:82-84` names two of the fourteen

Verbatim, re-derived on `064de707`:

```
82  - Gates to run locally before pushing: `./vendor/bin/pint --test`,
83    `php bin/ci-authz-lint.php`, `php bin/ci-boundary-lint.php`,
84    `./vendor/bin/pest --group=arch`, `composer analyse`.
```

Measured, with the denominator:

| population | count |
| --- | --- |
| `bin/ci-*.php` scripts on disk | **14** |
| of those, invoked by `bin/quality` — i.e. by every push | **14** |
| of those, named in the local-gate list at `:82-84` | **2** (`ci-authz-lint`, `ci-boundary-lint`) |
| of those, named ANYWHERE in `CLAUDE.md` | **4** (the two above, plus `ci-test-ratchet` and `ci-tsc-ratchet`, each named in a different bullet) |
| of those, named NOWHERE in `CLAUDE.md` | **10** |

The ten unnamed: `ci-activity-catalogue-lint`, `ci-citation-lint`, `ci-dependency-integrity-lint`,
`ci-dev-namespace-lint`, `ci-grants-convergence-lint`, `ci-identifier-generation-lint`,
`ci-message-text-lint`, `ci-money-lint`, `ci-runtime-zero-lint`, `ci-sql-clock-lint`.

**And a second, separable staleness in the same file.** `CLAUDE.md:722` describes what the pre-push
hook runs:

```
721  **Day-to-day (every push):** `.githooks/pre-push` runs `bin/quality` — wayfinder
722  generate, changed-files Pint/Prettier/ESLint, tsc ratchet, four lints, arch,
723  Larastan, suite + failure ratchet.
```

**"four lints".** `bin/quality` invokes fourteen `bin/ci-*.php` scripts; the same sentence counts the
tsc ratchet separately, so the number it is reaching for is twelve lints plus the test ratchet. It is
stale by eight, and it is the sentence a reader consults to learn what the floor does.

### Why this is a defect and not a documentation nit

The list is not decoration: it is the pre-flight a person runs to avoid a red push, and a person who
runs exactly what it says is still eight to twelve gates short. The failure mode is not "the lint
does not run" — `bin/quality` runs all fourteen — it is that **the first time you meet ten of these
gates is when one of them refuses your push**, at the point of highest cost and lowest context. That
happened here: `ci-citation-lint` caught a real defect in the first draft of the void pin (a
`path:LINE` naming no symbol), and it was met for the first time as a wall of arch failures.

## (b) One root cause, ten or eleven reds, and two or three of them name your file

**Reproduced twice on `064de707`, and the two runs disagree — which is itself part of the finding.**
A bare citation naming no symbol (`app/Support/ActiveSchool.php:99`) was planted in a file under a
scanned directory, and `tests/Arch/CitationLintCoverageTest.php` was run alone (22 arms).

| condition | reds | messages naming the offending file | ratio |
| --- | --- | --- | --- |
| the offending file is **UNTRACKED** | 10 | `it_h`, `it_l` | **2 of 10** |
| the offending file is **TRACKED** (committed) | 11 | `it_h`, `it_l`, `it_n` | **3 of 11** |

The eleventh is `it_n — generate reads TRACKED files only, while check still reds`, which by design
only fires once the file is committed. So the number of reds a reader sees depends on whether they
have committed yet, and neither number is the count of things wrong.

**The eight that do not name it all carry the same message:**

```
Failed asserting that 1 is identical to 0.
```

`it_b`, `it_c`, `it_c2`, `it_d2`, `it_g`, `it_i`, `it_k`, `it_q` — every one of them.

### The mechanism, read rather than guessed

These arms plant their own fixture, run the lint **over the whole tree plus that fixture**, and then
assert the exit code:

```php
[$exit, $output] = citationLintWith([...]);

expect($exit)->toBe(0)
    ->and($output)->not->toContain(basename($citer));
```

`$output` **does** carry the offending path — it is the lint's own report, and it is exactly what
`it_h` and `it_l` print when they fail, which is why those two name the file. The exit code is
asserted **first**, so it fails first, and the chain short-circuits before `$output` is ever
mentioned. The information was in the room and the assertion threw it away.

That is the diagnosability defect, stated precisely: **the arms do not lack the offending path; they
assert on the one value that has been stripped of it.**

### What would close it — proposed, not built

**Assert the OUTPUT before the exit code in every arm that expects a clean run.** In `it_b` and
`it_q` this is a pure reordering — both already chain
`->and($output)->toContain('no new citation violations')` and merely have it second. In the arms that
only assert `->not->toContain(basename($citer))`, add the positive
`->toContain('no new citation violations')` ahead of the exit assertion.

**What it costs:**

- **Eight arms edited**, one line each; no new helper, no change to `bin/ci-citation-lint.php`.
- **The arms gain a coupling** to the lint's success sentence (`no new citation violations`). Two of
  the eight already have it, so the coupling exists either way; this spreads it. If that sentence is
  ever reworded, eight arms red instead of two — loudly, and in a way that names the sentence.
- **It does not stop the fan-out.** Eleven arms still red for one cause, because they genuinely all
  run the lint against the real tree. This makes each red *legible*; it does not make it *singular*.
  A reader would still see eleven failures — but the first one they open would name their file.
- **It does not help a reader who never ran the lint directly.** That is half (a)'s job, and the two
  halves close different parts of the same cost.

An alternative considered and rejected as too large for the finding: give the coverage arms a
fixture-scoped lint invocation so a dirty tree cannot red them at all. That is a change to
`bin/ci-citation-lint.php`'s interface, it would weaken `it_q` (whose whole purpose is to be clean on
the tree as it stands), and it trades a diagnosability problem for a coverage one.

## Related

- `docs/handoff/tickets/larastan-examines-no-test-file.md` — the sibling found on the same branch.
  Both are the same shape: a gate whose output does not tell the reader what it examined, or about
  what.
- `docs/handoff/reports/feat-pin-the-single-writer-of-invoice-void.md` — the branch, and the first
  draft's defect that this lint caught.
- `docs/handoff/tickets/stale-path-line-citations.md` — why the lint exists at all. Not in dispute
  here; the lint's rule is right and it did its job.
