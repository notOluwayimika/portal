# A renamed CCM marking component drops its carried scores, and only a human would notice

> **CLOSED 2026-08-26** on `feat/ccm-fold-surface`. Slotted in exactly as this ticket asked — the
> next PR touching `MoveFromCcmJob`. The guard refuses the fold at the miss site when an unmatched
> component carries marks, naming each component and its score count; an unmatched component with no
> marks still folds normally, because two schemes that merely differ are ordinary and a guard that
> refused on shape would block every legitimate fold.
>
> **It stopped being a nice-to-have when CCM arrival was automated.** Measured before building:
> across all 17 folded CCM curricula in production — 310 subjects, 11,828 scored component-rows —
> ZERO were dropped. Not because the matcher is safe: school#1 has no marking schemes at all, so
> every one of those folds ran the legacy subject-local path where `cloneCurriculumSubjects` had
> copied the components and the names matched BY CONSTRUCTION. The matcher was handed pre-matched
> inputs 11,828 times and never actually exercised. Configuring CCM arrival makes the scheme path
> the normal path, and the CCM and non-CCM schemes are two independently-editable objects —
> school#2 already carries the asymmetry (CCM has one component where non-CCM has three), currently
> in the safe direction.
>
> The deciding argument was not the defect's own severity: a fold SURFACE built over an
> unsignalling job renders "N folded ✓" over dropped marks. The surface would have been the most
> honest-looking possible presentation of a silent loss, which made the guard its prerequisite.

**Raised 2026-08-26** by cold review of the CCM model on `feat/reassignment-ui`, while assessing
whether the CCM/non-CCM twin curriculum could be collapsed. The collapse is **on hold** (weeks of
work, no user-visible change, and — verified — no billing exposure). This guard is carved out of that
hold because it is the opposite of a refactor on every axis: ~20 lines, no schema change, no
behaviour change on any healthy run.

**NICE-TO-HAVE. Not urgent, no dedicated PR.** Slot it into whatever PR is next already touching
`MoveFromCcmJob` or the marking-component area. If it never gets slotted in, that is a defensible
outcome, not an accepted risk — read *Why this has not surfaced* and *What the guard actually buys*
below before treating it as anything more.

An earlier revision of this ticket was titled *"loses a class-term's marks silently"*. That claim was
wider than the artifact supports, and the correction is in *Why this has not surfaced*.

## The defect

`MoveFromCcmJob::mapOverlappingMarkingComponents()`
(`app/Jobs/MoveFromCcmJob.php:209-222`) matches each CCM marking component to its non-CCM counterpart
by normalised name — `Str::lower(trim($component->name))` — and returns `[]` for any component it
cannot match:

```php
return $newComponent
    ? [$oldComponent->id => ['old' => $oldComponent, 'new' => $newComponent]]
    : [];
```

`migrateScores()` (`:233-269`) then iterates only the mapped pairs. **Scores behind an unmatched
component are never queried, never carried, and never mentioned.** The job completes, the source
curriculum is set to `closed` (`:77`), and the queue records a success.

So a CCM component renamed to `"Half-Term Exam"` (hyphen) against a term component named
`"Half Term Exam"` drops every score entered against it, and **nothing in the system says so.** The
half-term marks remain readable on the closed CCM curriculum's broadsheet, but the term curriculum is
computed short a component from that point on.

This is the same silent-green shape as the vacuous-assertion family already catalogued here: a
control that reports success over an omission it never looked for. Note the scope of the word
*silent* — it is silent **to the system**, not to everyone. See the next section, which is the
correction to an earlier, wider version of this claim.

## Why this has not surfaced — two reasons, and neither is luck

As of 2026-08-26 no incident has been observed on production. That is consistent with the mechanism,
for two independent reasons. It is **not** evidence the defect is unreal, and it is not evidence a
drop has never happened — see the pre-flight.

**1. The trigger is genuinely rare.** It requires a marking component named differently between a
school's CCM scheme and its end-of-term scheme. Schools configure those once, and
`getOverlapping()` surfaces the relationship in the score-entry UI, so a divergence is visible at
configuration time to anyone looking for it.

**2. The drop is visible-if-noticed, downstream.** A score that failed to migrate leaves
`cell.value === ''`, and the cell lock is
`disabled={overlappingMC.includes(mc.name) && cell.value !== ''}`
(`resources/js/components/score-entry-page.tsx:586-589`) — so an unmigrated cell stays **editable**
rather than locked. The drop renders as an empty CA1 / Half-Term column on the term curriculum's
score-entry screen, in front of the teacher entering the remaining marks, and a missing component
drags every affected total visibly low on the term sheet.

### That detection path is HUMAN AND AMBIGUOUS, not self-healing

Do not read reason 2 as "the workflow repairs it". That overshoots as far in one direction as the old
title did in the other. An empty CA1 cell is **indistinguishable from "half-term not entered yet"** —
the blank carries no marker saying *this used to be full*. Detection therefore requires someone to
know the half-term happened, to read the blank as a drop rather than a to-do, and to re-enter the
same value that would have been carried. The depressed-total signal likewise requires a human to
eyeball totals and question them at approval.

Those are genuine backstops. They are soft ones, resting on vigilance, and they are the reason the
realistic outcome is *a teacher re-enters marks* rather than *a class-term is lost* — which is why
this ticket is a nice-to-have. They are not a repair mechanism, and nothing here should be cited as
one.

## Why the obvious guard — "every CCM component must resolve" — is WRONG

That was the first shape proposed, and it asserts against a configuration the system **deliberately
supports**.

`MarkingComponentController::getOverlapping()` (`app/Http/Controllers/MarkingComponentController.php:144-170`)
is not a diagnostic. It is load-bearing UI: `resources/js/components/score-entry-page.tsx:586-589`
disables a score cell when

```tsx
overlappingMC.includes(mc.name) && cell.value !== ''
```

— i.e. the carried-over CA1 / Half-Term cells on the term curriculum are **locked against
overwrite**. The overlap set is the normal expected carried set, which means the system tolerates
non-overlapping components on both sides by design.

An unconditional "every CCM component must resolve" abort would therefore convert a silent partial
into a **failed end-of-term run** for any school holding a legitimately CCM-only component. That
turns a cheap insurance line item into an outage.

## The guard to build

> A CCM marking component that resolves to **no** counterpart **and has at least one `scores` row on
> the source `curriculum_subject`** aborts the job. Unmatched **and** score-free passes silently, as
> today.

That is exactly the transition that loses data and nothing else. It also has real inputs on every
run — unlike the abort version, which would only ever fire on a configuration nobody has, and would
be vacuous by construction.

### What the guard actually buys — a detection channel, not a catastrophe averted

Given the section above, state the justification at its true size: **the guard does not hold back a
flood.** It swaps a soft, ambiguous, human-dependent signal — a blank cell that looks exactly like
work not yet done — for a hard, unambiguous system one: a failed job carrying the school and
curriculum in its payload, raised at the moment of the drop rather than whenever someone next looks
closely at a score sheet.

That is the whole value, and it is enough to justify ~20 lines riding along in a PR that is in the
area anyway. It is not enough to justify its own PR, a schedule slot, or any urgency.

### Mechanics

- **Site.** The miss is already visible at `mapOverlappingMarkingComponents()`
  (`app/Jobs/MoveFromCcmJob.php:209-222`) — the `: []` branch at `:220`. The check is a cheap `exists()` on the
  unmatched component: is there a `Score` row with `curriculum_subject_id = $oldSubject->id` and
  `marking_component_id = $oldComponent->id`. Unmatched components are the rare branch, so this is an
  existence probe on a small set, not a scan. It is the precise data `migrateScores()` would
  otherwise never query.
- **Atomicity.** `handle()` wraps the fold in `DB::transaction` (`:73`), so a throw rolls back
  cleanly. No half-folded curriculum, no partially migrated cohort. Do not move the check outside
  that transaction.
- **Retries.** `$tries = 3` (`:32`). The failure is deterministic, so the two retries are pure noise
  — three identical log lines for one problem. Either fail fast on this specific exception, or leave
  `$tries` alone and make the exception message unmistakable so the three lines do not read as three
  different problems. Either is acceptable; silently letting them look like three incidents is not.

### Explicit non-goal — this catches NO match, not a WRONG match

If two components normalise to the same name and the fold maps scores onto the unintended term
component, the guard does not see it: the component resolved, it had scores, it passed. That is a
mis-fold, not a drop — a different and rarer bug, out of scope here.

State this in the commit message too. The guard means **"the fold no longer silently drops"**. It
does **not** mean "the fold is now safe", and nobody should read it that way.

## Pre-flight — run it once, whether or not the guard is ever built

Run this before the guard ships, or on its own if the guard never gets slotted in. It is cheap and it
retires the question.

**The expectation is zero rows** — that is what *Why this has not surfaced* predicts, and a zero
result confirms the reasoning rather than merely failing to disturb it.

**The response to a surprise is unchanged by that expectation.** The query does not know the priors
were lowered. A non-zero row count is a finding regardless of what was expected: it means the
rare-and-visible case was **not visible enough somewhere** — the drop happened and the soft human
backstops in *Why this has not surfaced* did not catch it. Those class-terms' results were computed
short a component, and parents may have been shown understated marks.

A non-zero count therefore **escalates out of this ticket entirely** into *investigate and possibly
correct historical results*, and it jumps the queue — it is larger and more urgent than the
nice-to-have guard that found it. Do not fold that work into the guard's PR.

### Deriving the component set — there are TWO sources, not one

`CurriculumSubject::effectiveMarkingComponents()` (`app/Models/CurriculumSubject.php:83`) resolves
components from the **curriculum's marking scheme when one is set**, and falls back to per-subject
`marking_components` rows only when it is not:

```php
if ($this->curriculum?->markingScheme) {
    return $this->curriculum->markingScheme->components;
}
return $this->markingComponents()->get();
```

A query that reads only `marking_components.curriculum_subject_id` will therefore miss every
scheme-backed curriculum and under-report. Both branches must be unioned:

```sql
-- effective components of a curriculum_subject, both resolution branches
SELECT cs.id AS curriculum_subject_id,
       mc.id AS marking_component_id,
       LOWER(TRIM(mc.name)) AS norm
FROM curriculum_subjects cs
JOIN curricula c ON c.id = cs.curriculum_id
JOIN marking_components mc
  ON (c.marking_scheme_id IS NOT NULL AND mc.marking_scheme_id = c.marking_scheme_id)
  OR (c.marking_scheme_id IS NULL     AND mc.curriculum_subject_id = cs.id)
```

### Query A — CCM curricula whose non-CCM twin already exists

```sql
WITH eff AS (
  SELECT cs.id AS curriculum_subject_id, cs.curriculum_id, cs.subject_id,
         mc.id AS marking_component_id, LOWER(TRIM(mc.name)) AS norm
  FROM curriculum_subjects cs
  JOIN curricula c ON c.id = cs.curriculum_id
  JOIN marking_components mc
    ON (c.marking_scheme_id IS NOT NULL AND mc.marking_scheme_id = c.marking_scheme_id)
    OR (c.marking_scheme_id IS NULL     AND mc.curriculum_subject_id = cs.id)
)
SELECT ccm.school_id, COUNT(*) AS unmatched_scored_components
FROM curricula ccm
JOIN curricula twin
  ON  twin.school_id          = ccm.school_id
  AND twin.term_id            = ccm.term_id
  AND twin.class_level_arm_id = ccm.class_level_arm_id
  AND twin.exam_type_id       = ccm.exam_type_id
  AND twin.is_ccm = 0
JOIN eff old_e  ON old_e.curriculum_id = ccm.id
JOIN curriculum_subjects new_cs
  ON  new_cs.curriculum_id = twin.id
  AND new_cs.subject_id    = old_e.subject_id
WHERE ccm.is_ccm = 1
  AND EXISTS (
        SELECT 1 FROM scores s
        WHERE s.curriculum_subject_id = old_e.curriculum_subject_id
          AND s.marking_component_id  = old_e.marking_component_id)
  AND NOT EXISTS (
        SELECT 1 FROM eff new_e
        WHERE new_e.curriculum_subject_id = new_cs.id
          AND new_e.norm COLLATE utf8mb4_bin = old_e.norm COLLATE utf8mb4_bin)
GROUP BY ccm.school_id;
```

**`COLLATE utf8mb4_bin` is load-bearing.** The PHP match is
`Str::lower(trim($name))` compared with `===`. Under the table's default (case- and
accent-insensitive) collation the SQL would match **more** pairs than PHP does, and the probe would
under-report exactly the near-miss renames it exists to find.

### Query B — CCM curricula with no twin yet

`resolveTargetCurriculum()` (`:92`) creates the twin via `firstOrCreate`, and
`attachMarkingComponents()` (`:154`) then seeds it from the school's **active non-CCM marking
scheme**, falling back to **global non-CCM `marking_components` templates**. For a CCM curriculum
with no twin, the counterpart set is therefore that seed set, not a twin's. Mirror that fallback
order exactly, or the probe reports misses that the fold would not actually make.

### Before running either

Confirm the column names and key types against `information_schema` rather than the create
migrations. `database/migrations/..._create_marking_components_table.php` declares `uuid` primary and
foreign keys, but the project went through a hybrid uuid→integer conversion and later migrations
altered these tables — **the create filenames are not what ran.**

## Acceptance

- A test seeds a CCM curriculum whose component name does **not** match the term counterpart, with a
  score behind it, and asserts the job **throws** and the transaction rolled back (the target
  curriculum holds no partially migrated cohort). Plant it red first — confirm it fails against the
  current `: []` branch.
- A test seeds an unmatched component with **no** scores and asserts the job **completes**, folding
  everything else. This is the arm that proves the guard is scoped to the losing transition and not
  to the shape `getOverlapping`/the cell lock rely on.
- The existing `tests/Feature/MoveFromCcmJobTest.php` score-carry test still passes unchanged — the
  guard must be inert on the healthy path.
- The pre-flight has been run and its row count recorded in the PR description. Zero is a result, not
  an absence of one.

## Related

- `app/Jobs/MoveFromCcmJob.php:254` — the weight-ratio rescale. Lossy by construction against
  `scores.score` `decimal(4,1)` (`database/migrations/2026_04_26_121746_create_scores_table.php:17`):
  `45.5 → 22.75 → 22.8`. Known and asserted in the test at `:203-205`. **Not fixable in place** —
  only collapsing the twin curricula removes it, since direct entry at term weights never creates the
  intermediate. Documented, not ticketed.
- `app/Http/Controllers/MarkingComponentController.php:42` — `\Log::info($ccm);`, a bare boolean into
  the log on every marking-component fetch. Leftover debug. Delete it in the same PR.
- The normalised-name coupling has **three** sites, not one: the rescale above, `getOverlapping()`,
  and `BroadsheetService::COMPONENT_ORDER` / `COMPONENT_LABELS`
  (`app/Services/BroadsheetService.php:20-31`), which hardcode `'continuous assessment 1'`,
  `'half term exam'` and friends to drive column order and labels. A school naming a component
  anything else gets unordered columns and raw labels — same failure family, same silent shape. All
  three retire together if the twin curricula are ever collapsed behind an explicit phase marker.
