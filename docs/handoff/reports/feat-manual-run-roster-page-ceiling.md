# `feat/manual-run-roster-page-ceiling` — 100 → 150, on measured cohorts

**Branched from** `staging` at `e089ca89`, clean tree.
**Scope:** raise `ManualInvoiceRunStudentController::MAX_PER_PAGE` to 150, give the roster screen its own page-size options rather than raising the shared control's, and pin the three places that carry the number against each other. No schema change, no request-contract change, no change to how selection or billing works.

---

## 1 · Where 150 comes from

Class-level cohorts on the production copy (school#1, 611 live students) — `COUNT(DISTINCT students.id)` grouped by class level over students with an ACTIVE episode:

```
116   107   102   101   99   86
```

**Four of six exceeded the old ceiling of 100, and by between 1 and 16 rows.** Selection is page-scoped, so a bursar billing a whole class met the page-scoped tick warning for the sake of a handful of rows — the warning firing on the ordinary case, which is how a warning stops being read. 150 clears the largest measured cohort by 34.

**The distribution is in the docblock, not just the number.** `MAX_PER_PAGE`'s comment carries the six figures, the query that produced them, and the instruction to re-measure rather than re-guess when a school grows or a second school with larger classes arrives. A number with a story attached invites the next reader to argue about the story; a number with its evidence attached invites them to re-run it.

---

## 2 · The shared control was not touched, and that was the point

`resources/js/components/pagination.tsx`'s `LIMITS` is rendered by **fifteen** screens whose servers do not agree about a legal `per_page` — two of them clamp nothing at all. Adding 150 there would offer it on every one of them, including the ones that would page against it.

So the roster brings its own:

```ts
const ROSTER_PAGE_LIMITS = [5, 10, 25, 50, 100, MAX_PER_PAGE];
```

passed as `<Pagination … limits={ROSTER_PAGE_LIMITS} />`. It is the shared list **plus** this feature's ceiling — nothing removed, so a bursar reaching for a familiar option still finds it — and the last entry is the mirrored constant rather than a second literal, so **the control cannot offer an option the server refuses**.

`Pagination` gained one optional prop, `limits`, defaulting to `LIMITS`. Every existing caller is unchanged, in source and in behaviour. `LIMITS` itself is byte-identical and now carries a comment saying why it must not be raised for one screen's sake.

### The controller's comment was wrong and is corrected

It said the ceiling **was** the shared control's top option — "rather than a number invented here, so the control cannot offer an option the server refuses". That was true when both were 100 and became false the moment this number moved. The property it was reaching for still holds; what changed is which side is the authority. It is now the controller, and the comment says so.

---

## 3 · Three places, one number, and nothing but a test between them

| #   | Where                                             | What it is                                                                                                                                          |
| --- | ------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | `ManualInvoiceRunStudentController::MAX_PER_PAGE` | the **clamp** — the authority; what a client actually gets                                                                                          |
| 2   | `MAX_PER_PAGE` in `index.tsx`                     | the **banner's copy** — what the operator is told ("the largest page available is N"), and what decides whether "Show all N on one page" is offered |
| 3   | `ROSTER_PAGE_LIMITS` in `index.tsx`               | the **control** — what the operator can pick                                                                                                        |

A TypeScript file cannot import a PHP constant, and this screen fetches its roster over HTTP rather than receiving it as props, so there is no build step and no shared module that could make these one value. They are three copies, and copies drift — quietly, in every direction: a banner naming a ceiling the server does not have, or a dropdown option the server silently clamps, where the operator picks 150, is served 100, and the label agrees with them.

**Arm `2h` pins all three against literals, from three independent reads** — a reflection read of the PHP constant, and two verbatim source assertions on the TSX. An arm that derived any of them from any other would prove only that a value equals itself. It additionally asserts the shared `LIMITS` is still `[5, 10, 25, 50, 100]`, so "simplifying" this by raising the shared array instead is refused.

**Arm `2e` pins the clamp by BEHAVIOUR**, because a constant that matches a literal is not evidence that anything reads it: accepted at 150, clamped at 151. It was **reworded, not replaced** — its subject never changed — and it gained a third literal: `per_page=101` is now served whole, so it reds in both directions rather than only when the clamp vanishes. Without that, every assertion in the arm passes against a server still clamping at 100 except the two literals, which are exactly the lines a hurried revert would edit.

### A note on failure legibility

The source assertions started as `expect($source)->toContain(...)`, whose failure prints the **entire file** — 87 KB of `index.tsx` into the terminal for one missing line, which is how a legible red becomes an unread one. They go through `mirpDeclares()` instead, which reduces the subject to a boolean and puts the needle and the reason in the message.

---

## 4 · The banner stays honest

Raising the ceiling makes the page-scoped warning fire on **fewer** screens — every class level in the production copy now fits on one page. That is the intent, and it is also exactly how such a warning gets quietly disconnected: it stops appearing, nobody misses it, and the next filter that spans pages has no warning left.

Nothing about the banner changed. What arm `2i` pins is **what it keys on**:

```ts
const spansPages = pagination.last_page > 1;
```

`last_page` is a property of the response and carries no reference to the ceiling at all, which is what makes the warning survive any future move of that number. Had it been written `total > MAX_PER_PAGE` it would read identically and be coupled to the thing this commit changes — so the arm asserts the form, plus the unconditional wording ("Ticks apply to this page only.") and the escalation that is only reachable once there are ticks to lose.

**It is a source assertion, not a render one, and the arm says so in its own docblock.** There is no render harness for this component — the repo's five vitest files cover pure modules — so what is proved is that the wiring still reads the way it must. That the banner actually paints and escalates is a drive's job.

The two stale prose references to "100" in the screen's own class docblock ("the banner names its ceiling — 100 —", "a cohort above 100") were corrected in the same commit. A comment naming the old ceiling is the same defect as a constant holding it, one layer softer.

---

## 5 · Cost, measured rather than assumed

Stated because "150 is fine" should not be an assumption, even an obviously safe one.

**The run**, at 150 ticked students (`portal_testing`, planted cohort, three reps, rolled back):

```
queries=13   txn_open_ms=37.4 / 30.5 / 22.8   targets=150 placed=147 unplaceable=3
```

**13 queries — the same 13 as at 611.** Both perf commits on this path left it flat: the port resolves the whole selection in one read, and the targets are written in one batched insert. A 611-student run went 6030 → 13 queries and ~2.6 s → ~0.1 s of open transaction across those two commits (`perf-manual-run-batch-enrollment-resolve.md`, `perf-manual-run-batch-target-inserts.md`), so 150 is not a consideration on the write side at all.

**The roster read**, through the real controller on the production copy (school#1, 611 students):

| `per_page` | served  | rows    | queries | wall ms  |
| ---------- | ------- | ------- | ------- | -------- |
| 25         | 25      | 25      | 10      | 15.6     |
| 100        | 100     | 100     | 10      | 28.9     |
| **150**    | **150** | **150** | **10**  | **27.4** |
| 151        | **150** | 150     | 10      | 32.1     |

Ten queries at every page size — the feed is eager-loaded, so page size moves rows and not round trips — and 150 costs no more than 100 within run-to-run noise. The last row is the clamp confirmed against the real controller on real data, not only in the suite.

---

## 6 · Mutations — four, each verified applied before running

| #   | Mutation                                                   | Result                                                                                                                                              |
| --- | ---------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | controller `MAX_PER_PAGE` back to 100                      | **2e and 2h red** (`Failed asserting that 100 is identical to 150`), 13/15 pass                                                                     |
| 2   | screen's mirrored `MAX_PER_PAGE` back to 100               | **only 2h red**, 14/15 pass — 2e stays green because the server is untouched. This is precisely the drift no behavioural test can see.              |
| 3   | `limits={ROSTER_PAGE_LIMITS}` removed from the call site   | **only 2h red** — the control silently falls back to the shared list, topping out at 100 while the banner still promises 150                        |
| 4   | `spansPages` re-keyed to `pagination.total > MAX_PER_PAGE` | **only 2i red** — the warning still renders and still reads correctly, but is now coupled to the ceiling and would go silent the next time it moves |

Each mutation was reverted and the file read back before the next.

---

## 7 · Gates run locally

`bin/quality` was **not** run — Segun runs it in his own terminal.

| gate                                                                                                                          | result                                                        |
| ----------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------- |
| `pest tests/Feature/Finance`                                                                                                  | 1001 passed, 5427 assertions                                  |
| `pest --group=arch`                                                                                                           | 115 passed                                                    |
| `pest ManualInvoiceRunPageTest`                                                                                               | 15 passed                                                     |
| `npm run test:js` (vitest)                                                                                                    | 75 passed, 5 files                                            |
| `pint --test` (changed files, array form)                                                                                     | passed                                                        |
| `prettier --check` (changed `.tsx`)                                                                                           | passed                                                        |
| `eslint` (changed `.tsx`)                                                                                                     | clean                                                         |
| `tsc` + `bin/ci-tsc-ratchet.php`                                                                                              | 42 == baseline 42; neither changed file appears in the errors |
| `composer analyse` (Larastan)                                                                                                 | 0 errors                                                      |
| authz · boundary · citation · money · sql-clock · dev-namespace · identifier-generation · runtime-zero · dependency-integrity | all OK                                                        |

**One instrument note.** `php bin/ci-tsc-ratchet.php` with no argument reported _"type errors DECREASED — 0 < baseline 42 (good!)"_ and invited a `generate` that would have written the floor down to 0. It had no output file to read. Run with the real `tsc` output it says `OK (42 == baseline 42)`. That is the known false-green in this tool, and the failure mode is the dangerous direction: acting on its advice would have destroyed the baseline while reporting an improvement.

---

## 8 · What this does NOT do

- **It does not raise the shared `LIMITS`.** Fourteen other screens are untouched, in source and in behaviour; the only change to `pagination.tsx` is one optional prop defaulting to today's value, plus a comment saying why the array must not be raised.
- **It does not add a server-side "everyone matching this filter" scope.** That is still the real answer for a cohort above 150 and is still not built; brief §1 governs its shape.
- **It does not change what the ceiling protects.** Selection stays page-scoped, ticks still clear on filter and page change, and the warning still fires whenever a filter spans more than one page.
- **It does not make the three copies impossible to drift** — nothing can, across a PHP/TypeScript boundary with no build step. It makes them _loud_ when they do.
