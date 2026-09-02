# Nothing requires a branch to contain `staging` before it merges

**Status:** open. Found 2026-09-02 by asking whether `staging`'s own head had ever been gated.
**Severity:** **ticket** — the hazard is real and has **not** bitten; what is missing is the
mechanism, not a fix to broken code.
**Bites in:** any merge from a branch that does not contain `origin/staging`.

## The gap

`.githooks/pre-push` runs `bin/quality` against the **branch's** tree. When the branch does not
contain `origin/staging`, the merge produces a tree **no gate has ever run on** — each side was
measured against a base lacking the other, and the combination first exists at the moment nobody is
looking.

The sharpest shape is a rename: a caller introduced on one branch, the symbol it calls renamed on
the other. With no alias left behind — which is the deliberate choice in `#375`'s collapse of the
release predicate — the result is a `BadMethodCallException` at **runtime**, not a wrong answer at
rest. The suite that would have caught it is exactly the one that never ran.

**GitHub Actions is permanently disabled (ADR 0053), so no other check will ever run there.** On a
repository with CI this is what a required status check on the merge commit closes. Here there is no
second layer, which makes the residual load-bearing rather than theoretical.

## Measured, and it has NOT bitten

All five recent merges checked with `git merge-base --is-ancestor <merge>^1 <merge>^2`:

| merge | branch contained `staging`? |
|---|---|
| `4138af7a` #378 | yes |
| `a7b0b539` #377 | yes |
| `0e8510c4` #376 | yes |
| `a54b46c7` #375 | yes |
| `5bcc62b8` #374 | yes |

Every one TRUE, so each merge result **was** the tree its own gate ran on. `#374` even carries an
explicit `Merge remote-tracking branch 'origin/staging' into …` doing it by hand.

And the specific case that prompted the check turned out not to exist: `#375`'s rename was authored
**on top of** `#369`'s head (`git merge-base --is-ancestor 9e5eda1a e6b57b1e` → true) and updated
`InitiateGatewayPayment`'s call site as part of the collapse.

**`bin/quality` was then run on `staging` @ `4138af7a` anyway: PASS, 20/20 steps.** A containment
argument is a claim about what was *measured*, never proof the tree is *green*, and stopping at the
argument would have been the most defensible-looking wrong stop available.

## Why this is a ticket and not a shrug

**Five clean merges are evidence that whoever pushed them remembered.** That is a practice, not a
mechanism. Nothing refuses a push from a branch behind `staging`, so uptake will look like noise
rather than a rule — the adoption-gradient case this repository has now recorded three times, and it
holds here for the usual reason: omitting the step produces **no red** until the day it produces a
runtime crash.

## Two mechanisms, guarding DIFFERENT windows

Neither is redundant with the other. Stated explicitly so a later reader does not remove one as
duplication.

**1 · A pre-push containment check.** Refuse the push unless the branch contains `origin/staging`.
Makes the gated tree and the merge result the same tree **at push time**, and fails early, locally,
with a message naming what to do about it.

**2 · Linear history required on `staging` (branch protection).** The pre-push check alone is **not
sufficient**, and the hole is timing: `staging` can move between the push and the merge, at which
point the merge is no longer a fast-forward, git produces a real merge commit, and the gap reopens
exactly where it was. Requiring linear history means a branch that has fallen behind physically
**cannot** merge — it must rebase and re-gate first. That is containment enforced at the only moment
that cannot be outrun by timing; it costs nothing to run and cannot be forgotten.

The first fails early and legibly. The second fails late and cryptically but is the one that cannot
be evaded. Belt and braces.

## What is NOT proposed

**A post-merge gate run.** It is the obvious fix and the weaker one: it detects the broken tree
*after* it is on `staging`, where every branch cut next inherits it. It also has nothing to refuse —
by the time it runs, the merge has happened.

## Whoever takes it

Mechanism 2 is a GitHub branch-protection setting on `staging` and is the project lead's to enable.
Mechanism 1 is a change to `.githooks/pre-push` and can be taken by anyone; it needs both arms
bite-proven — **behind → refuses** and, more importantly, **up to date → exits 0** — because a
containment check that refuses everything is the broken-closed shape `bin/db-exclusive` shipped
with, and a refuses-only proof passes it.
