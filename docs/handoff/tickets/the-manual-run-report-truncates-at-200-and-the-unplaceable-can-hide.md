# The manual run report truncates its buckets at 200, and that is where a name can hide

**Status:** open. Found while reviewing the selection-and-report commit, 30 August. Not a defect in
that commit — a documented limit whose consequence is larger than it looks because of what else was
decided the same day.

## The limit

The run report's buckets truncate at **200 rows** with a flag. Above that the flag says names are
missing; it does not say which.

## Why it matters more here than in an ordinary list

**Brookstone ruled on 30 August that this feature issues DIRECTLY — no maker-checker.** There is no
second signature and no second human. The run report is therefore the ONLY place a bursar can
discover that they ticked 96 students and 90 were billed.

The `unplaceable` bucket is the one they must act on: those are children who were selected and not
charged. From row 201 a name is invisible, and the flag tells the operator that something is missing
without telling them what — which is the same failure shape as the guardians index telling an
operator it acted on 240 while acting on 25.

## Today's scale does not close it

School#1 held 611 students on 28 August and the BSS cohort is 91. A 200-row cap is survivable at
those numbers. **"Survivable at today's numbers" is not the same as safe**, and the number that
matters is not the cohort — it is how many of a selection fail to resolve, which nobody has measured
because the feature has never run.

## What closes it

Any of these; the choice is a decision rather than a preference.

- Paginate the buckets, so every name is reachable.
- Cap the RUN rather than the report — refuse a selection larger than the report can faithfully
  show, which keeps the guarantee that what you see is everything.
- Export the unplaceable list, which is the thing an operator actually needs to act on.

**Do not close it by raising 200 to a larger number.** That moves the cliff without removing it, and
the next person to meet it will have less reason to expect it.
