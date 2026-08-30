# TICKET — the release gate is enforced by a hook that a pull-request merge never runs

**Status:** open. The gate currently reports itself as enforced and is not. Found 27 August while
checking whether the staging→main promotion had been verified; it had not.

## The mechanism, as designed

`bin/quality-promote` is the release gate. It is deliberately heavier than the per-push hook: lint
scope is the **whole release** (everything staging adds over main) rather than one branch's diff,
plus `bin/quality-clean-db` — a throwaway database, the incremental migration path against
**populated** data, and `down()`/re-up() **reversibility**. Its docblock states the enforcement
model plainly:

> On success it stamps `.quality-promote-ok` with the exact verified SHA. The pre-push hook refuses
> a push to `main` unless that stamp matches the commit being pushed — so the gate is enforced by a
> check, not by remembering to run it.

`.githooks/pre-push:41-59` is that check.

## Why it does not hold

**A `git push` is the only thing that runs a pre-push hook.** A pull request merged in the GitHub
web UI is a server-side operation; no local hook is consulted, no stamp is read, nothing is refused.

Measured on 27 August: `main` was at `d79bf3b` (PR #315, merging staging), and `.quality-promote-ok`
held `ba5dba2e` — PR **#305**, a different and earlier release. Both main-ward merges in the
history, #307 and #315, are PR merges. The gate has never run on a release that reached main.

## And the intended path is now impossible

The hook's own advice is `git checkout main && git merge --ff-only staging && git push`, and its
comments at `:55-59` note that a merge commit "can never match" a stamp.

`git rev-list --count origin/staging..origin/main` is **2**. Main is no longer an ancestor of
staging, so `--ff-only` fails today and will fail on every future promotion — the two PR merges put
commits on main that staging will never contain. The only remaining route to main is the one the
gate cannot see.

So this is not "people took a shortcut." The documented path is unavailable, and the shortcut is now
the only path.

## What was actually lost, and what is recoverable

- **Release-scope lint** — unrecoverable per release once main has caught up. After the merge,
  `origin/main..origin/staging` is empty, so re-running it lints nothing and returns a green that
  means nothing. **Do not run it after the fact and take comfort from it.**
- **`bin/quality-clean-db`** — recoverable, because it is not diff-based. It can be run at any time
  before the migrations reach production, and it is the half with teeth: `migrate:fresh` runs every
  `up()` against an empty schema, so a backfill touching zero rows there is invisible, and a `down()`
  that leaves an index behind produces a state `up()` cannot reapply — which, in the script's own
  words, "bites hardest during an incident."

## What closes it

Three candidates, and the choice is a decision rather than a preference:

1. **Move the gate server-side** — a required status check on the PR into main. This is the only
   option that cannot be routed around, and it is at odds with the standing decision that GitHub
   Actions is intentionally off (`bin/quality-clean-db`'s own docblock says so). That tension is the
   real subject here.
2. **Make main mergeable ff-only again** and keep the local gate — reset main to staging's HEAD once,
   then promote only by ff-push. Restores the designed mechanism at the cost of one history rewrite
   on a shared branch.
3. **Accept the UI merge and move the verification earlier** — make `bin/quality-clean-db` a step of
   the ordinary push gate rather than the promotion gate, so a release cannot accumulate unverified
   migrations in the first place. Cheapest, and it loses release-scope lint permanently.

Whichever is chosen, the docblock and the hook comments must stop describing a mechanism that does
not run. A gate that reports itself as enforced is worse than a gate everybody knows is manual,
because it is spent trust rather than absent trust.
