# feat/rollover-placement-preview — implementation report

**Branch:** `feat/rollover-placement-preview`
**Base:** `staging` @ `4b0383e5` (the merge of PR #296; verified with `bin/landed`, which confirmed
containment *and* that the merge took the reviewed head `2e431fea`)
**Shape:** 4 commits · 4 new PHP classes · 1 new test file (12 tests) · 1 job refactor · 1 screen ·
runbook + `CLAUDE.md`

---

## Deviations from the approved plan — read these first

**1. Two classes, not one.** The plan named a single `NextYearPlacement` shaped on `NextTermSlot`'s
static factory. Built as two: `NextYearPlacement` (the per-pupil result, keeping `NextTermSlot`'s
shape) and `NextYearPlacementResolver` (the per-curriculum context). The context — source curriculum,
school, target session — is constant across every pupil while the result is per pupil, and folding
both into statics produced seven-parameter calls at both call sites. The resolver also caches each
level's arm list, which is the per-level-not-per-pupil work the plan's own cost note asked for.
**The property that mattered survives**: the five `firstOrCreate` keys are still built once and fed
to both modes.

**2. `RolloverPlan` gained a `RolloverPlacement` object, not a bare `Collection`.** The plan said one
field holding groups, "plus per-plan buckets for repeaters and unplaceable". Three fields would have
meant three constructor changes; one value object holds all three and gives `unconfiguredKeys()` /
`unconfiguredCount()` a natural home. Still one new field on `RolloverPlan`.

**3. The TOCTOU acknowledgment is optional at the validation layer, not `required`.**
`RolloverEndOfYearRequest` serves the preview endpoint too, where there is nothing to acknowledge, so
`required` would have broken preview. It is `sometimes|array`, and `commitEndOfYear` treats **absent
as the empty set** — which passes while nothing is unconfigured and refuses the moment something is.
That is the direction a missing acknowledgment must fail in, and it is why the pre-existing surface
tests still pass unchanged: their worlds are terminal, so nothing is unconfigured.

**4. One behaviour in `MoveToNextYearJob` deliberately not preserved verbatim.**
`resolveTargetLevel` now tests the **column** for terminality rather than the resolver's null.
Measured: `class_levels` does not soft-delete and `class_levels_next_level_school_foreign` is
`ON DELETE RESTRICT` (`2026_08_20_110000:67-70`), so the non-terminal null is unreachable in normal
operation — the branch guards constraint-bypass paths (restored dump, `FOREIGN_KEY_CHECKS` off) only.
Kept because it costs one comparison and fails safe; the alternative logs a broken pointer as
"terminal, nobody advances", a specific false statement in the one log a person reads when a cohort
did not move. **The job's behaviour is unchanged — only the log line differs.**

---

## What changed

| File | Change |
| --- | --- |
| `app/Services/Rollover/NextYearPlacement.php` | new — per-pupil result, reason constants, `destinationKey()` |
| `app/Services/Rollover/NextYearPlacementResolver.php` | new — the extracted rules, read-only + write modes |
| `app/Services/Rollover/PlacementGroup.php` | new — one (source → destination) pair with its pupils |
| `app/Services/Rollover/RolloverPlacement.php` | new — advancers / repeaters / unplaceable + the acknowledgment set |
| `app/Jobs/MoveToNextYearJob.php` | delegates; keeps its own logging via `logRefusal` (−217/+66) |
| `app/Services/Rollover/RolloverPlan.php` | `+ readonly RolloverPlacement $placement` |
| `app/Services/Rollover/RolloverPlanner.php` | builds placement in `planEndOfYear`; empty for end-of-term |
| `app/Http/Controllers/RolloverController.php` | serialises placement; the subset pre-check before dispatch |
| `app/Http/Requests/RolloverEndOfYearRequest.php` | `+ acknowledged_unconfigured` |
| `resources/js/pages/admin/academics/rollover.tsx` | placement table, subject note (EOY only), confirm line, opaque echo |
| `docs/handoff/drive-runbooks/m4-rollover-surface.md` | four new steps (§§8–10 + hand-back items) |
| `CLAUDE.md` | two lessons |

---

## The claims, and how each is verified

**The extraction is behaviour-preserving.** `MoveToNextYearJobTest` passes **17/17 with the test file
untouched** (`git status tests/` clean across the Part 1 commit). That is the proof, exactly as
`MoveFromTermJobTest`'s 17/17 was for `NextTermSlot`.

**Preview and commit cannot disagree about placement.** Not asserted from the design — there are two
parity tests, and the second exists *because a mutation showed the first was not enough*:
- through the **create path** (destination absent at preview), and
- where **distribution decides the arm** (two target arms, non-matching source label).

**The preview writes nothing.** Curriculum and episode counts snapshotted around **two** preview
calls. Two, because a preview that wrote would report the destination as configured on the second
look, silently removing the flag the screen exists to raise.

**The acknowledgment is binding.** `RolloverEndOfYearRequest` previously accepted two session ids and
nothing else, so the server could not distinguish an acknowledged plan from an unacknowledged one, and
every divergence signal came from `queued()` — after `dispatchEndOfYear`. Now a **subset** check runs
before dispatch, over destination **identities** derived from the five resolved keys (not curriculum
ids — the destinations that matter are precisely the ones with no id yet).

---

## Watched reds — six, each planted, observed, restored

Restoration verified with `diff -q` against a private backup after every one; the working tree is
byte-identical to the commit.

| # | Mutation | Result |
| --- | --- | --- |
| 1 | subset check removed | **RED** — addition, swap, and missing-acknowledgment arms |
| 2 | comparison made symmetric | **RED** — the *removal* arm, proving the asymmetry is deliberate |
| 3 | **count** comparison instead of subset | **RED — the swap arm ONLY** |
| 4 | read-only mode made to write | **RED** — 7 tests, including writes-nothing |
| 5 | `orderBy('id')` → `orderByDesc('id')` | initially **GREEN** — see below |
| 6 | preview path drifts from write path | initially **GREEN** on parity — see below |

**Mutation 3 is the one that vindicates the swap fixture.** A count-based implementation passes the
addition, removal, equal *and* missing-acknowledgment arms intact. Only the swap catches it. Without
that fixture the fix would have shipped carrying the hole it exists to close.

**Mutations 5 and 6 found gaps in the tests, not the code**, and both were the same mechanism:

- **5** — the distribution test asserted that two pupils an `armCount` apart share an arm, which is
  true under *any* ordering; and worse, `rc_level` labels every arm `B`, so the source **label-matched**
  the target and the modulo was never evaluated at all. It called itself a distribution test, tested
  the label rule, and agreed by luck because both ids had the same parity. Fixed: a non-matching
  source label to force distribution, both residues asserted (and asserted to have been covered), and
  the expected arm derived from an **explicitly ascending query** rather than restating the resolver's
  own rule.
- **6** — parity stayed green under a simulated second implementation, because its target level has
  **one arm**: any placement rule lands everyone in the same place. Added the distribution-parity
  test. Both red now.

The generalisation is in `CLAUDE.md`: **a test proves the property it names only if the fixture makes
that property the sole explanation for the pass.**

---

## Two incidental bugs, both found by running rather than reading

- **`range(1, 0)` returns `[1, 0]` in PHP** — descending, two elements. The fixture helper was
  silently planting two pupils into every world that asked for none, so tests building their own
  roster measured mine as well as theirs.
- **A ternary cannot be passed to a by-reference parameter.** Fatal, and the pre-existing rollover
  suite never hit it because **none of its worlds has an advancer** — meaning the advancer path was
  entirely untested before this branch, and these are the first tests to enter it.

---

## A fact the reviewer should examine deliberately

**A subject-less enrollment is still billable.** `BillableEnrollmentAdapter` keys on `status = active`
and never on subjects (audited this session, pinned by
`tests/Feature/BillableEnrollmentAfterRolloverTest.php`). So a pupil who lands in a rollover-created
curriculum with no subjects is `active`, and therefore billed for a curriculum they cannot study.

That is **not a billing defect** — billing is correct per status — but it is a silent
academically-empty-but-billable state that surfaces at reconciliation time rather than at rollover
time. Two things are asserted rather than assumed:

1. the extraction is behaviour-preserving (job test unmodified ⇒ episode creation, and therefore
   billability, unchanged);
2. the subject-less state bills normally, unchanged by this branch.

This is why the warning is surfaced twice — panel and confirm — rather than once.

---

## What is NOT done

**The screen has not been driven.** The suite is structurally blind to rendering: the payload test
proves `unconfigured_count` is **present in the data**, not that the confirm dialogue **renders** it.
That split is deliberate — backend half asserted, dialogue half drive-verified — and **neither half
should be mistaken for the whole**. Runbook §§8–10 cover it.

**`DESTINATION_NOT_CONFIGURED` as a hold is not on this branch.** The correct-by-construction fix is
per-destination hold: the job stops `firstOrCreate`-ing an empty destination and leaves those pupils
unplaceable, so the source stays open, the registrar configures the destination *with* subjects,
re-runs, and the idempotent job places them ready. It is strictly better than a warning. It is not
here because it changes the job's create semantics and so breaks the "`MoveToNextYearJobTest` passes
unmodified" invariant that is this branch's proof of a behaviour-preserving extraction. **Ticket it.**

**Performance is not measured on a real cohort.** Placement is N resolutions over the year group where
the plan previously ran one count query. Episodes and students are eager-loaded and arms are cached
per level, but the largest fixture here is 4 pupils. If it is slow on Brookstone's data that is a
finding to report, not something to paper over with a cache.

**One pre-existing oddity left alone:** `RolloverPlanner::describe()` carries a duplicated docblock
(`@param` block immediately followed by a second `/** */`), which the IDE reports as unreachable code.
Pre-existing, cosmetic, out of scope.

---

## Gate

`bin/quality` — see the run appended at commit time. `MoveToNextYearJobTest` 17/17 unmodified;
73 rollover-surface tests green; `tsc` 42 errors, exactly the ratchet baseline, none in the changed
file.
