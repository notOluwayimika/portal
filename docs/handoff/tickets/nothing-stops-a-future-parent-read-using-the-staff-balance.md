# Nothing stops a future parent-facing read from using the staff balance method

**Status:** open. Raised by the implementing agent while building the withhold predicate
(`d4536ae1`, 31 August) and worth more than the ticket-level it was offered at.

## The gap

The parent portal's displayed balance must exclude bills pending Internal Audit review, so the
guardian read goes through a dedicated method while `accountPositionForStudent` — shared with the
staff statement — stays untouched and keeps counting the withheld bill, as Brookstone require.

The current call site is enforced: a mutation dropping the adjustment reds five arms. **A NEW
parent-facing endpoint calling the shared method directly would not be.** The guarantee is a
convention, and this repository's own rule is that a convention with no mechanism behind it is
wallpaper.

## Why it is worth a gate rather than a note

The whole "one predicate, no staff blast radius" argument rests on exactly one call site being
correct. That argument was the reason the interim was affordable before 6 September. It stops being
true the first time someone adds a parent-facing read — and the failure is silent: the screen
renders, the number is wrong in the direction of showing a parent a charge they were not meant to
see yet.

## What closes it

An arch or boundary arm: no parent-facing controller may call `accountPositionForStudent`. The
existing arch suite already expresses rules of this shape, and `App\Finance\Services` is already
module-private, so the machinery exists.

**Do not close it by renaming the shared method to look internal.** A name is a hint; the arch arm
is
the mechanism, and the point of this ticket is the difference between the two.
