# TICKET — `guardians:merge`'s dry run and its apply are two separate plans, and nothing binds them

**Status:** open, not implemented. Raised in review of `feat/guardian-merge-command` (slice 1 of the
duplicate-guardian work) and ruled a ticket rather than a fix by the project lead: the window is short,
operator-controlled, and the merge is not destructive to a moved row. Recorded because the dry run is
the **only** review step this command has, and a claim about it is currently stronger than the
mechanism behind it.

## The claim, and where it stops being true

`GuardianService::merge()` opens a transaction, refuses, builds the plan, and applies it only when
`$apply` — so **within a single call** the array an operator would read is the array the write is
driven from. That much holds, and it is worth keeping.

The workflow the command mandates is not a single call. `MergeGuardians` prints
`DRY RUN — nothing was written. Re-run with --apply to execute.`, so the inspection and the write are
**two invocations** with an arbitrary gap between them. The second one rebuilds the plan from scratch
against whatever the database says at that moment:

- `buildMergePlan` reads `guardian_student` with no `lockForUpdate` and no snapshot,
- nothing hashes the plan on the dry run, and
- nothing on the apply re-checks that what it computed is what was reviewed.

So the design note "the array an operator inspects is the array the write is driven from" is true of
the method and false of the procedure. Those are different statements and only the first one is
mechanised.

## What it costs

An operator dry-runs a merge group. Between that and `--apply`, an admin attaches a student to the
absorbed guardian through the normal UI. The apply moves a link nobody reviewed.

The blast radius is bounded — a moved pivot is re-pointed, not deleted, and the cross-account login
pre-flight re-runs on the apply, so the worst case is not a stranded login. But "an operator reviewed
this" is the entire control on a command that soft-deletes records and hard-deletes pivot rows, and it
is currently a control over a plan that no longer exists.

## The test that would have caught the drift does not

`tests/Feature/Guardian/GuardianMergeTest.php`, `writes nothing on a dry run and returns the same plan
the apply executes`, runs both calls **in one process against a frozen database** and compares only:

```php
expect(array_keys($dry))->toBe(array_keys($applied))
    ->and($dry['pivot_moves'])->toBe($applied['pivot_moves'])
    ->and($dry['backfilled'])->toBe($applied['backfilled']);
```

`pivot_collisions`, `primary_demotions`, `pivot_final_state`, `login_state` and `orphaned_user_ids` are
not compared. The arm proves the two calls agree about the two easiest keys under conditions where
nothing could have made them disagree.

## The shape a fix takes

Either of these, not both:

1. **Bind the plans.** Print a fingerprint of the plan on the dry run (a hash over the ordered
   `pivot_moves` / `pivot_collisions` / `login_state` / `backfilled` entries), require `--apply` to
   carry it, and refuse when the recomputed plan hashes differently. That makes the review a real
   precondition and turns the drift into a refusal with a clear message.
2. **Drop the claim.** State in the class docblock that the two invocations are two plans, and that a
   concurrent edit between them is not detected. Cheaper, honest, and leaves the control where it
   actually is — with the operator's timing.

Whichever is chosen, widen the dry-run arm to compare **every** plan key, and add one that mutates the
pivot table between the dry run and the apply so the arm can tell the two options apart.

## Not this ticket

Locking `guardian_student` for the duration of an operator's inspection. The gap is human-length; a
row lock held across two console invocations is not a fix, it is an outage.
