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

---

## FIRST MEASURED CASUALTY — 30 August 2026

Until today this ticket described a mechanism that does not run. It now describes one that has
already cost a red `staging` four days before cutover.

**What happened.** #330 (the gateway transaction table) merged into a `staging` that already carried
S11's `finance_invoice_lines_destination_guard`. Its fixture — `GatewayTransactionSchemaTest`'s
`gtxSchool()` at `:66` — builds a charge line with no `bank_account_id`, which that guard refuses.
The branch was cut before S11 landed, so its last local gate run was green and correct **for the
tree it ran against**. The PR merge ran no hook, read no stamp, and refused nothing. Twenty-two arms
went red on merge.

Merge order on staging: `695b05e` (#330), then `80afc4b` (#338). S11 landed in #324, before both.

**And a second, independent collision in the same merge window.** The manual-invoice migration added
a CHECK constraint; #330 shipped a closed list of every CHECK on a `finance_` table. Each branch
green alone, red together.

**So the failure is not only about migration order.** Developer 2's `--step` warning was right and
was not the whole of it. Two more modes, neither visible to either branch's own gate:

1. **An exhaustive-set assertion on one branch meeting a new member of that set on another.**
2. **A fixture written before a guard that landed while the branch was open.**

Both are invisible by construction to a gate that runs on one branch at a time. Only something that
runs on the MERGED tree can see them — which is precisely what this ticket says does not exist.

**This strengthens option 1** (a required status check on the PR into main, server-side) relative to
the other two. Options 2 and 3 both keep the gate on a developer's machine, and a machine-side gate
cannot run on a merge result that does not exist until the merge happens. The tension with the
standing "GitHub Actions is intentionally off" decision is real and is still the subject — but the
cost side of that trade now has a measured number on it rather than a hypothetical.
