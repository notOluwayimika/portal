# TICKET — nothing in `guardians:merge` tells an operator which of two accounts should be the keeper

**Status:** open, not implemented. Raised in review of `feat/guardian-merge-command` and ruled a ticket
on re-derived data: **0 of the 28 accounts in the 14 duplicate groups has `email_verified_at` set.**
Neither address in any group is yet "the one the parent uses", so today the choice is close to
arbitrary and close to harmless. **That zero is the whole reason this is a ticket, and it is the thing
to re-derive before trusting this status** — the moment one group contains an activated account, the
choice stops being harmless and this becomes a fix.

## The gap

Which account survives a merge is decided entirely by which uuid the operator passes to `--keep`. The
decision table prints, per absorbed record: `guardian#`, `user#`, whether it can sign in today, whether
it is the keeper's account, whether it is school-exclusive, and what the merge will do to it. The
survivor line prints `user#<id>` plus deliverable yes/no and enabled/disabled.

Nothing in that distinguishes the two addresses in a way that bears on the choice. Both are deliverable;
both can sign in; the table says so about both and stops.

## What it costs once an account is activated

The operator picks a keeper effectively at random with respect to the parent's actual habits. The merge
disables the other account and mails credentials to the survivor. If the parent had been using the
donor address, their known sign-in address is now dead — and worse, a password reset sent to that
address resolves the still-existing but disabled `users` row, so the reset itself succeeds and the
subsequent login still fails. The parent gets a working reset link into an account they cannot enter,
which is a more confusing failure than a plain refusal.

## The shape a fix takes

1. **A discriminator column on the accounts table.** `activated` — `email_verified_at` non-null — which
   respects the ids-only rule (a boolean, not an address). Any group where exactly one side is
   activated has an obvious right keeper.
2. **A recommendation.** When a group contains exactly one activated account, mark it as the
   recommended `--keep` in the table, and refuse (or warn loudly) when `--keep` names the unactivated
   side of an activated pair. Refusing is the safer default and costs the operator one flag to
   override.
3. **Re-derive the zero when picking this up.** The query is one line against `users` joined to the
   guardian rows in a group; if any group has an activated account, treat this as a fix and do it
   before the next batch of merges, not after.

## Not this ticket

Choosing the keeper automatically. Which record survives carries the relationship, the back-filled
fields and the operator's judgement about which row is the good one; a heuristic that also decides that
is a bigger change than a column.
