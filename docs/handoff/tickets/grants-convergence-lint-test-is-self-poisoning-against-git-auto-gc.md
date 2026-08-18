# TICKET — `GrantsConvergenceLintTest` manufactures loose git objects and is not robust to git's own auto-gc, so it goes intermittently red for reasons unrelated to the code under test

**Status:** open, not implemented. Raised from `feat/guardian-merge-command`, where the ratchet went
**red twice in a row on a branch that touches nothing in RBAC**, with a **different subset of arms
failing each time**. Recorded rather than retried away: CLAUDE.md is explicit that a red cannot be told
from a flake by looking at it, and retrying until green is indistinguishable from fixing. This ticket
is the mechanism, so the next person does not have to re-derive it at 2am.

**Nothing here is a claim that the arms are wrong.** The lint they exercise is sound and the arms are
good arms. What is defective is their *interaction with the repository they run inside*.

## What was observed

Two full-suite runs on `feat/guardian-merge-command`, both ratcheting red, both naming only
`tests/Feature/Rbac/GrantsConvergenceLintTest.php`:

| Run | New failures | Suite wall time |
| --- | --- | --- |
| 1 | **12** arms | ~1745 s |
| 2 | **9** arms, a *different* subset | **~3101 s** |

Four earlier full-suite runs on the same branch ratcheted clean with exactly the seven baselined
failures. The branch's diff is `app/Services/GuardianService.php`, two console commands, one test file
and docs — no seeder, no enum, no migration, no grant map.

## The two failure shapes, and they have one cause

```
Illuminate\Process\Exceptions\ProcessTimedOutException: The process
"'php' 'bin/ci-grants-convergence-lint.php' '3129c7c…' 'e4df044…'" exceeded the timeout of 60 seconds
```

```
Failed asserting that 'grants-convergence-lint: NOT LINTED — database/seeders/RbacSeeder.php is
unreadable at head 4af879e. Either the file moved or the revision is unreachable; this gate cannot
look…'
```

The arms build **throwaway commits** with `git read-tree` / `update-index` / `write-tree` /
`commit-tree` against a scratch `GIT_INDEX_FILE`, then hand those SHAs to the lint. Those commits are
**unreferenced**: no ref, no branch, no tag points at them. They exist only as loose objects.

Now the repository state, re-derived:

```
$ git count-objects -v
warning: garbage found: .git/objects/pack/tmp_pack_rA7yBY
count: 7286
size: 35164
in-pack: 9450
packs: 14
size-pack: 19527
prune-packable: 243
garbage: 1
size-garbage: 159

$ git config --get gc.auto   →  unset, so the default 6700
```

**7286 loose objects against a 6700 threshold.** Git runs `gc --auto` opportunistically after ordinary
commands, so with the repo over the threshold, a gc can start *during the suite* — triggered by the
tests' own git calls, or by any commit made while the suite runs. That single fact produces both
shapes:

1. **Timeouts.** A gc packing ~7k objects is slow, and it blocks the git commands the arms are waiting
   on. `Process`'s 60 s default is generous for `commit-tree` (~0.03 s measured in isolation) and not
   generous at all for `commit-tree` queued behind a repack.
2. **"revision is unreachable".** A gc prunes **unreferenced** loose objects — which is exactly what
   the fixture commits are. The arm creates a commit, gc prunes it, the lint is then asked to read a
   SHA that no longer exists, and reports `NOT LINTED`. The arm asserting a *finding* sees `NOT LINTED`
   and fails; the arm asserting `NOT LINTED` passes for the wrong reason.

The `tmp_pack_*` left in `.git/objects/pack/` is the residue of one of those gc runs being killed
part-way — the signature of an interrupted repack, not of anything the application did.

**And the test file is a contributor to the condition that breaks it.** Every run writes fresh blobs,
trees and commits that nothing references. Run it enough times and the loose-object count climbs past
`gc.auto` on its own, after which the file becomes intermittently red. It poisons the well it drinks
from.

## Why it matters beyond one branch

The failure lands on **whoever is holding the branch when the threshold is crossed**, and it names a
file they did not touch. The natural readings are all wrong: "my change broke RBAC" (it did not), "it
is a flake, re-run it" (it will pass eventually, which teaches exactly the habit the ratchet exists to
prevent), or "baseline it" (which permanently blinds the ratchet to a real gate).

It also makes `bin/quality`'s last step — the full suite — nondeterministic in a way that ADR 0053
already flags as the residual nobody has explained. This is at least one concrete instance of it with a
mechanism attached.

## The shape a fix takes

Any of these; the first two are cheap and independent.

1. **Anchor the fixture commits for the duration of the test.** Write a temporary ref
   (`refs/gcl-fixtures/<random>`) pointing at each fixture commit and delete it in a `finally`. A
   referenced object is not pruned, and the arms stop depending on gc's timing.
2. **Disable auto-gc for the duration.** Add `-c gc.auto=0` to every git invocation in `gclCommit` (and
   `GIT_CONFIG_PARAMETERS` / `-c gc.auto=0` on the lint's own subprocess), so no arm can trigger a
   repack mid-run.
3. **Raise the subprocess timeout** from the 60 s default and assert on the timeout explicitly, so a
   slow machine produces a message that says "git was slow" rather than an assertion about grants.
4. **Clean up.** A `git gc --prune=now` after the file, or a scheduled maintenance step, so the loose
   count does not ratchet upward run after run.

`bin/quality` should probably also fail loudly when `git count-objects -v` shows the repo above
`gc.auto`, since that is the precondition for this whole class.

## Not this ticket

The seven entries already in `tests/ratchet-baseline.txt`. And **do not add these arms to it** — they
pass on a repository below the gc threshold, and baselining them would retire a real gate to work
around a git housekeeping problem.
