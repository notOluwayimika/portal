# TICKET — the non-exclusivity refusal counts the merge's own school, so it can name the wrong hazard and prescribe a remedy that cannot work

**Status:** open, not implemented. Raised in review of `feat/guardian-merge-command` and ruled a
ticket: the refusal is **conservative** — it refuses where it should refuse and then some, so nothing
is written wrongly. What is defective is the message.

**It is recorded rather than shrugged at because it falsifies a property that revision 4 of this
branch's report makes load-bearing:** that the refusal "states a remedy only when it is real". In this
one shape it states a remedy that cannot clear the check, which is the exact failure the round before
it was fixing.

## The mechanism

`GuardianService::remainingGuardianSchoolIdsFor` filters only on `whereNotIn('id', $goingAwayGuardianIds)`.
It does not exclude the school the merge is running in. The donor branch of
`assertLoginDecisionAllowed` then renders that list as:

> …still backs live guardian records in {schools}. `users.disabled_at` is a property of the account and
> not of a school, so disabling it would revoke that parent's access **there too**…

and offers, when the keeper account is school-exclusive:

> Re-run with `--keep` and `--absorb` reversed…

## What it costs

A **certain-duplicate group of size three** in one school, all on one donor account, where the operator
lists only one `--absorb`:

- the refusal fires naming `school#A` — the school the operator is standing in — and says their access
  "there too" would be revoked, which reads as a cross-school hazard that does not exist;
- reversing `--keep`/`--absorb` does not clear it, because the third row is still there whichever way
  round the pair goes;
- the action that *would* clear it — **also absorb the third row in the same run** — is never stated.

The operator is told a true fact (the account still backs a live record) in a frame that is wrong (as
if another school were involved) with a remedy that does not work.

## Why this was not fixed inline

It is not a one-line exclusion. Excluding the current school from `remaining_school_ids` would make the
*message* right and the *gate* wrong: the gate is `orphanedUserIdsAfterMerge`, which correctly counts
same-school rows, and it must keep doing so — a same-school sibling row is still a live record that
would lose its account. So the fix is a split, not a filter:

1. a new plan value carrying the **sibling guardian ids in this school** (ids, not just a school id —
   the remedy needs to name the rows to absorb);
2. `remaining_school_ids` narrowed to genuinely other schools;
3. a third remedy branch: *"also absorb guardian#X, guardian#Y in the same run"*;
4. the `only this school` column in the decision table distinguishing the two cases;
5. an arm per branch.

The same conflation exists on the keeper side and was resolved there rather than ticketed, because the
keeper gate needed the narrow sense to avoid firing where no write was at stake: `keeper_school_exclusive`
(full sense, used to word the reversal remedy) and `keeper_other_school_ids` (other schools only, used
to gate the re-enable) are deliberately two values. **That pair is the worked precedent for this
ticket** — do the same on the donor side.

## Reachability

Today's copy has **0** certain `(user_id, school_id)` duplicate groups, so no group of this shape
exists and the message cannot currently be produced. Re-derive before assuming that still holds: it is
one `guardians:find-duplicates` run.

## Not this ticket

The refusal itself. It is correct and must stay conservative; only its wording and the remedy it offers
are in scope here.
