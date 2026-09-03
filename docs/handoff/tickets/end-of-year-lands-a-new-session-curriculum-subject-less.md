# End-of-year lands a new session's curriculum subject-less; it should inherit the level's prior-session subjects

**Found:** review of the rollover jobs on `feat/ccm-fold-surface` (PR #306, now on `staging`),
2026-09-03. **Pre-existing** — not a CCM-fold regression; the fold work only added the CCM branch to
`MoveFromCcmJob`. This is a gap between what end-of-year does and what the product owner expects:
*"2026/27 Year 10 should carry the subjects of 2025/26 Year 10 — the same level, the previous
session."* It does not.

## What happens today

`MoveToNextYearJob` crosses a class-level boundary and creates the destination curriculum as a **bare
row**. In `NextYearPlacementResolver::resolveDestination`:

```php
Curriculum::firstOrCreate($keys, [
    'min_subjects'      => $this->source->min_subjects,
    'status'            => 'active',
    'grading_scheme_id' => $level->grading_scheme_id,          // resolved from the TARGET level
    'marking_scheme_id' => $this->resolveMarkingSchemeId(...), // resolved, never copied
]);
```

No `curriculum_subjects` are written. Then `createEpisode` calls
`StudentSubjectService::autoAttachCompulsorySubjects`, which reads the **destination's own**
`curriculumSubjects()->active()->where('is_compulsory', true)`. If the destination was just created
empty, that set is empty, and the pupil lands **subject-less**. `MoveToNextYearJob`'s own docblock
states the intent plainly: *"subjects are not cloned across a level boundary — the target level
defines its own."*

Confirmed there is no inheritance anywhere else, either:

- The curriculum row is keyed on `term_id`, and a `Term` belongs to a session, so **2025/26 Year 10
  and 2026/27 Year 10 are different rows**. Nothing looks up the prior one.
- Session/term creation does **not** clone curricula or subjects forward (`SessionController` has no
  such path).
- `Curriculum` has no prior-session scope or helper.
- Subjects reach a curriculum only via: manual setup (`CurriculumController::assignSubject`), the
  **end-of-term** clone (`MoveFromTermJob::cloneCurriculumSubjects`, which carries subjects *within* a
  session and level), or backfill. None of these is "inherit the level's subjects from last year."

So at every new academic year, the **first term of each level lands subject-less** unless someone has
already rebuilt that session's curricula by hand. The rollover warnings
(`destinationHasCompulsorySubjects`, the "runs no subjects / no default" flag) exist precisely because
this is the failure mode — but a warning is not the behaviour the owner wants.

## What it should do

When end-of-year creates a destination curriculum that has **no subjects yet**, seed its
`curriculum_subjects` from the **same curriculum in the closing (source) session** — same
`class_level_arm_id`, same `exam_type_id`, same `is_ccm` — then let it be edited afterward. This is the
end-of-term clone applied across the year boundary on the *same level*, which is the one case the
"target defines its own" rule got wrong: a level's subject list is stable year to year, so the prior
year's instance of that level is the right default.

`class_level_arm_id` is session-independent (only `term_id` carries the session), so the lookup is
exact: last year's Year 10 arm A has the same `class_level_arm_id` as this year's Year 10 arm A.

## Why this default, and the tension it has to respect

Schools teach roughly the same subjects in Year 10 every year; making an operator re-enter them each
session is both burdensome and the source of the silent subject-less landing above. **But subjects can
legitimately change year to year** (syllabus reform, new offerings) — which is the real reason the
authors chose "target defines its own." The resolution is the EOT pattern, not a blind overwrite:

- **Seed only when the destination has no subjects** (a fresh row, or one nobody configured). Never
  clobber a destination an operator has already edited — same discipline as
  `MoveFromTermJob::canAdoptSourceSchemes` ("repair only while unused").
- After seeding, the new session's curriculum is fully editable, so a school that *is* changing Year
  10's subjects still can.

Default = inherit; override = still available. That satisfies the owner without reintroducing the
rigidity the original design was avoiding.

## What closes it

In `MoveToNextYearJob` (not the resolver — see the preview/commit note below), after the destination
curriculum is resolved and before/at `createEpisode`, when the destination has no
`curriculum_subjects`:

1. Find the prior instance: `Curriculum` matching `(school_id, class_level_arm_id, exam_type_id,
   is_ccm)` of the destination, with `term_id` in the **source session's** terms
   (`sourceSessionId()` is already available), that **has** `curriculum_subjects`. Subjects are
   consistent across a session's terms for a level, so pick deterministically (e.g. the latest term of
   the closing session).
2. Clone its subjects onto the destination, **mirroring `MoveFromTermJob::cloneCurriculumSubjects`**:
   `firstOrCreate` each `CurriculumSubject` (`subject_id`, `is_compulsory`, `display_order`, `active`);
   copy marking components **only** on the legacy scheme-less, non-categorical path (scheme-backed
   curricula resolve components through the scheme); create the draft `resultStatus`; carry teacher
   assignments if that mirrors EOT. Do **not** copy `grading_scheme_id` / `marking_scheme_id` — those
   are already resolved from the target level and must stay that way.
3. Then `autoAttachCompulsorySubjects` runs as today and now finds subjects to attach.

### Two constraints that will bite if missed

- **Preview must stay read-only.** `NextYearPlacementResolver` is shared between the job (write) and
  `RolloverPlanner` (preview) — that shared construction is what makes preview/commit parity a
  property of the code. Seeding is a write, so it belongs in the **job**, never in the resolver's
  shared path. A preview that creates `curriculum_subjects` is a defect worse than the one being
  fixed.
- **Idempotency and concurrency.** `MoveToNextYearJob` runs one per source curriculum, and
  distribution can point several source arms at one destination arm, so two jobs can race to seed the
  same destination. `firstOrCreate` per subject (as EOT does) makes that safe; a bulk insert would
  not.

## Edge cases

- **No prior-session curriculum** (first year of operation, or a genuinely new level): fall back to
  today's behaviour — bare destination, `autoAttachCompulsorySubjects` attaches nothing, the existing
  warning fires. Do **not** error.
- **`is_ccm`** must match: a CCM destination seeds from the prior CCM curriculum, non-CCM from
  non-CCM. It is part of the lookup key, so this falls out for free — but assert it.
- **Destination already has subjects**: skip seeding entirely (the "only when empty" guard). This is
  what keeps a re-run, and an operator's edits, safe.

## Arms it needs

- **Positive:** a closing-session Year 10 with a known subject set → end-of-year into the new session →
  the destination Year 10 curriculum carries those exact `curriculum_subjects`, and each promoted
  pupil has the compulsory ones as `student_subjects`. Assert the destination's subject set equals the
  prior's, not merely "non-empty".
- **Negative, unchanged:** no prior-session curriculum → destination lands subject-less and the
  warning still fires. The fix must not turn a legitimately-empty first year into an error.
- **Preview/commit parity:** the preview path creates **zero** `curriculum_subjects` — assert the row
  count is unchanged by a preview, changed only by the commit.
- **Idempotency:** running the job twice (or two jobs onto one distributed destination) yields one set
  of `curriculum_subjects`, not duplicates.
- **No-clobber:** a destination that already has a hand-edited subject set is left untouched.
- **Scheme handling:** scheme-backed destination clones subject rows but not components; legacy
  scheme-less non-categorical clones components too — mirror EOT's split exactly, and pin it.

## Related

- `MoveFromTermJob::cloneCurriculumSubjects` — the pattern to mirror; the whole difference is the
  lookup (prior session, same level) instead of (same session, same level, source curriculum).
- `NextYearPlacementResolver` / `RolloverPlanner` — the shared preview/commit construction that the
  seeding must not run inside.
- The progression modal warnings and `destinationHasCompulsorySubjects` — the pre-flight that flags a
  subject-less landing; after this fix they should fire far less often, but they stay as the backstop
  for the no-prior-session case.
- Borders general curriculum setup, which is partly the other developer's area — confirm the "seed
  from prior session, editable after" model is the agreed one before shipping, since it changes what a
  freshly-rolled session looks like.
