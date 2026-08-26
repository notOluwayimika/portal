# The fold refusal names internal ids where the gate names the class

**Found:** browser drive of `feat/ccm-fold-surface` (PR #306), 2026-08-26, by the human driver — the
first person to see the message rendered.

## What an operator sees

Six lines apart, on the same screen, about the same thing:

```
GATE    1 CCM class(es) sit in a final slot and must be moved first.   Year 9 A
REASON  Refusing to fold curriculum#4: 1 scored marking component(s) on subject#2 have no
        counterpart on the non-CCM side and their marks would be lost — "Half Term Project"
        (2 score(s)). Add matching component(s) to the non-CCM marking scheme, then fold again.
```

The gate says **Year 9 A**. The refusal says **curriculum#4** and **subject#2**.

## Why this is arguably a defect and not polish

The whole argument for surfacing the reason — made in `3dbdc4d7` and again in the drive report — is
that *"Job failed" would unblock the operator from "there is no fold button" only to re-block them
with "it failed and I cannot say why"*. The message exists so the operator has **an action they can
take**.

`curriculum#4` is not an action they can take. There is no screen in the product where an operator
looks up a curriculum by integer id; they navigate by class and term. So the remedy sentence — *"Add
matching component(s) to the non-CCM marking scheme"* — is correct and unactionable in the same
breath, because the thing it is about is named in a vocabulary the operator does not have.

It is the same shape as the server path the A-fix removed: developer-facing detail on an
operator-facing surface. The path was worse (it leaked infrastructure); this is quieter and survives
because it *looks* precise.

**`subject#2` is the sharper half.** A reader could guess "curriculum#4" is the CCM class they just
clicked Fold on. Nothing on the screen tells them which SUBJECT that is — and the remedy is
per-subject.

## What closes it

`MoveFromCcmJob::mapOverlappingMarkingComponents` builds the message and has the models in hand:

- the curriculum → its class level arm → **"Year 9 A"**, plus the term;
- `$oldSubject->subject->name` → the subject's name.

Both are one relation away from what is already loaded. The component name is ALREADY human
(`"Half Term Project"`), which is what makes the ids beside it look like an oversight rather than a
convention.

Keep the ids as a parenthetical if a developer reading `failed_jobs` wants them — the constraint is
that the operator's sentence must lead with the vocabulary the operator has.

## Arms it needs

- the message contains the class label and the subject NAME;
- and a negative: it must not lose the component name or the score count, which are what make the
  refusal diagnosable;
- `CcmFoldSurfaceTest`'s existing `toBe($message)` arm pins the exact string end to end, so it will
  red on any change and must be updated deliberately rather than loosened.

## Related

- `docs/handoff/reports/feat-ccm-fold-surface-drive.md` § "Operator-facing findings from the driver"
- The two lesser reads from the same drive, deliberately NOT ticketed: the ~150-character single line
  wants a break at its natural period and a ~70–80ch max-width; and *"it will not resume on its own"*
  is actionable by omission, which the driver judged correct and would not change.
