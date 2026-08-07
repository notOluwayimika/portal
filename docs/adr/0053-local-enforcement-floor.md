# 0053 — The enforcement floor is local, permanently

**Status:** Accepted — 2026-08. **Deciders:** owner + advisor. Records a decision already in force
and already relied on; changes no behaviour. What it changes is that the floor can no longer be
mistaken for a stopgap.

## Context

GitHub Actions is disabled on this repository. The account is billing-locked, and the owner has ruled
that billing will not be pursued: *"we will continue to adopt `bin/quality` plus the pre-push hook as
the gate and write that down. if we decided to move on with Github billing, I will let you know
myself."*

Several documents were still written as though CI were the gate — `CLAUDE.md` described the workflow
as "PR → staging (CI + manual validation)", and `docs/roadmap.md`, under a heading that promises
*current state, not intent*, stated that the `linter` and `tests` workflows run on pull requests.
They do not. They have never executed a job. A document that describes a gate nobody is standing at
is worse than one that says there is no gate, because someone plans around it.

## Decision

**`bin/quality` plus `.githooks/pre-push` are the enforcement floor. Not a stopgap for CI — the
floor.** Every branch is verified on the machine that produced it, before the push leaves it.
Reopening remote CI is an explicit owner decision and nobody else's to take.

`core.hooksPath` is `.githooks`. The hook reads the ref list from stdin once, skips deletions, and
for `refs/heads/main` additionally requires a `.quality-promote-ok` stamp equal to the exact local
SHA — so promotion to `main` is gated on a run somebody performed against that commit, not against
something near it.

## What the floor guarantees

Fourteen steps, and a run means: the working tree matches what `composer.json` and `composer.lock`
declare; generated route/action files are current; changed files pass Pint, Prettier and ESLint;
TypeScript, PHPStan and the test suite hold against their ratchet baselines, which may only shrink
(ADR 0041); the frontend builds; and every architectural and authorization lint passes.

Step 1 is different in kind from the rest, and the difference is deliberate. Steps 2–14 each measure
a property of the code. Step 1 measures whether the code being measured is the code that was
declared — so it **aborts** the run (exit 2) rather than collecting its failure like the others.
Thirteen green ticks printed beside one red dependency line tell the reader the opposite of the
truth: they are not additional findings, they are readings from an instrument that has just reported
itself untrustworthy.

## What the floor does NOT guarantee, and each of these has bitten or can

**It guards against forgetting, not against intent.** `git push --no-verify` bypasses it, and that is
by design — the hook's own header says so. A gate that cannot be bypassed deliberately becomes a gate
people route around permanently.

**It is only as honest as its caches.** A stale PHPStan result cache once reported eight hard
failures against a `vendor/` that was merely incomplete, and survived the `composer install` that
fixed the tree. A gate that can report red from a cache can report green from one. The cache is now
repo-local and fingerprinted against `composer.lock`, and — the load-bearing half — a failing run
records no fingerprint at all, so the next run cannot inherit a cache built over a tree that was
known bad.

**It does not verify `node_modules`.** `composer.lock` integrity is checked; the pnpm lockfile is not.
The gate can still run against a JavaScript dependency tree nobody declared. This is a known,
unclosed hole, recorded here rather than left to be rediscovered.

**It runs on one machine.** There is no independent reproduction. A tree that is wrong in a way the
developer's environment hides is wrong in the same way when the gate runs, because it is the same
environment.

## Consequences

A document that describes CI as the gate is now wrong by ruling, not merely out of date. `CLAUDE.md`
and `docs/roadmap.md` are corrected in this change. `.github/workflows/*` stay on disk, disabled and
drifted — they are already behind `bin/quality` by several lints. They are not deleted, because
restoring CI is a decision the owner has reserved, and a deleted workflow is harder to restore than a
stale one. Nobody should read them as describing what runs.
