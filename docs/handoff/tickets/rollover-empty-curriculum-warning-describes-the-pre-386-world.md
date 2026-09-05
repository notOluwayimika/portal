# The rollover's empty-curriculum warning describes the world before #386

**Found:** follow-up review after #386 landed on `staging`, 2026-09-03. #386 made end-of-year seed a
destination curriculum's subjects from the same level's PRIOR (closing) session when the destination
has none. The rollover PREVIEW was not updated, so it still tells operators the opposite.

The block gated on `placement.unconfigured_count > 0` in
`resources/js/pages/admin/academics/rollover.tsx` reads:

> End-of-year does not carry subjects across — the new class level defines its own. The rollover will
> create these curricula empty, pupils will land with no subjects, and nothing attaches them
> afterwards. Set them up first if you can.

After #386 the first sentence is false for any destination with a prior-session instance to inherit
from, and the last is actively harmful: it tells operators to hand-build what the system now
populates, which re-exposes the duplicate/orphan hazard the warning's own bullets describe — a
prepared curriculum on the wrong slot, exam type or arm is one the job does not FIND, so it creates a
second and leaves the prepared one orphaned, which looks identical to having done nothing.

## THE PREMISE THIS TICKET WAS RAISED ON IS WRONG, AND THE CORRECTION CHANGES THE FIX

The brief describes `destination_is_unconfigured` as the **"no curriculum yet"** badge, and proposes
splitting "no curriculum yet" into inheritable and truly-empty. Read against the tree, that is not
what the flag means, and building to it would have shipped a screen that lies.

`NextYearPlacement::destinationIsUnconfigured()` is:

```php
return $this->resolved() && ! $this->destinationHasCompulsorySubjects;
```

It tests **SUBJECTS, NOT EXISTENCE** — deliberately, and its own docblock records why the existence
check was removed: an existence check guarded the run where nothing was at risk (destinations absent)
and passed the run where everything was (a re-run, where run 1 had already created them EMPTY).

That matters because **#386 does not seed on the same condition the flag fires on.** The job seeds
when the destination has NO `curriculum_subjects` **rows at all**:

```php
if (CurriculumSubject::where('curriculum_id', $destinationId)->exists()) { return; }
```

whereas the flag fires when it has no ACTIVE COMPULSORY ones. Three states, not two:

| Destination state | Flag fires? | #386 seeds? | Truth |
| --- | --- | --- | --- |
| no curriculum row | yes | yes, if a prior exists | **inheritable** |
| row exists, zero subject rows | yes | yes, if a prior exists | **inheritable** |
| row exists, has subjects but none active+compulsory | yes | **NO** — the "only when empty" guard stops it | **lands empty** |

So `will_inherit` is **not** "a prior instance exists". It is
`destination has no subject rows at all` **AND** `a prior candidate exists`. Implementing the brief's
version would label state 3 "will inherit — no action needed" for a destination that lands with no
compulsory subjects: the precise failure this ticket exists to remove, reintroduced in the other
direction.

## What closes it

**Parity by construction.** #386 left its lookup private in `MoveToNextYearJob`. It is EXTRACTED to a
read-only helper that both the job's seeding and the preview's flag call, so the screen cannot flag on
a different rule than the commit seeds on. The helper keys on the five-key destination identity the
preview already carries (`curriculumKeys`), not on a `Curriculum` model — the preview's destination
row does not exist, which is the whole point.

**The acknowledgment set narrows, and that is the safe direction.** `unconfiguredKeys()` feeds the
commit's staleness gate, which refuses unless the freshly-planned set is a SUBSET of what was
acknowledged. Keying it on "will land empty" removes destinations that #386 will populate — an
operator should not be made to acknowledge a hazard that no longer exists. It stays self-correcting:
delete the prior curriculum between preview and commit and the destination becomes truly-empty again,
reappears in the fresh set, and the commit is refused.

**Copy.** Truly-empty keeps the red warning, reworded to drop the false universal claim, and keeps
the three placement bullets and the duplicate/orphan caveat — they still apply to exactly this case.
Inheritable gets an informational, non-red note. Per-row badges distinguish the two.

## Arms it needs

- Inheritable: a prior-session same-level curriculum WITH subjects -> preview marks it inheriting and
  it is NOT in the red count or the acknowledgment set.
- Truly empty: no prior instance -> still flagged, red warning, still acknowledged.
- **State 3, the one the brief would have got wrong:** destination exists with a non-compulsory
  subject only, and a prior exists -> the job will NOT seed it, so the preview must call it
  **empty**, not inheriting.
- **THE ANTI-LIE ARM:** run a real preview, then the real rollover, and compare — every destination
  the preview called inheriting lands non-empty, every one it called empty lands empty.
- No prior session at all (first year) -> everything unconfigured is empty; today's warning unchanged.
- `is_ccm` is part of the lookup key, so a CCM destination does not inherit from its non-CCM sibling.
- Preview stays READ-ONLY: computing inheritability creates no curriculum and no subject.

## Related

- #386 `fix/eoy-subject-inheritance` — the change this is the copy/trigger follow-up to.
- `NextYearPlacementResolver` — the shared preview/commit construction the new helper sits beside.
- Borders general curriculum setup (the co-dev's area), as #386 did.
