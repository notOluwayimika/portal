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

Fifteen steps, and a run means: the working tree matches what `composer.json` and `composer.lock`
declare; generated route/action files are current; changed files pass Pint, Prettier and ESLint;
TypeScript, PHPStan and the test suite hold against their ratchet baselines, which may only shrink
(ADR 0041); the frontend builds; and every architectural and authorization lint passes.

Step 1 is different in kind from the rest, and the difference is deliberate. Steps 2–15 each measure
a property of the code. Step 1 measures whether the code being measured is the code that was
declared — so it **aborts** the run (exit 2) rather than collecting its failure like the others.
Fourteen green ticks printed beside one red dependency line tell the reader the opposite of the
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

**It is not deterministic, and a red can therefore be retried away.** On 2026-08-09 three consecutive
runs on this branch produced:

| Run | Tree | Result |
|---|---|---|
| A | `aae9459` | **PASS** 14/14 |
| B | `bb75e3e` — one markdown file on top of A | **FAIL** — 23 new test failures |
| C | `bb75e3e`, unchanged from B | **PASS** 14/14 |

B and C ran against byte-identical code. The 23 were unrelated to the change and clustered in
permission- and seed-heavy files — `SuperAdminAuthorityTest` (6), `SharedPermissionsTest` (5),
`SeededPermissionCoverageTest` (5), and five others; all 69 arms of those eight files pass when run
together in isolation.

**The cause was investigated and NOT found.** Recorded so nobody repeats the search from zero:

- *Inherited database state* — **disproven.** Three runs after `db:wipe` and three without produced a
  byte-identical failure set (md5 `f167633e`, 1492 tests, exactly the seven `ratchet-baseline.txt`
  entries). The two conditions are not actually different: `RefreshDatabase` runs `migrate:fresh` once
  per *process*, so every invocation already starts from a wiped database.
- *Test ordering* — **disproven.** PHPUnit sorts test files (`php-file-iterator/src/Facade.php:48`),
  and `phpunit.xml` sets no random `executionOrder`.
- *A leaked session variable* — **disproven.** `AccountPaymentConcurrencyTest:128` sets
  `innodb_lock_wait_timeout = 2` on the default connection where its five siblings use a purged
  throwaway connection; a probe reads the default 50 both alone and immediately after that file,
  because Laravel rebuilds the application, and its connections, per test.
- *The double pest invocation* — **ruled out.** `--group=arch` selects three files that touch no
  database (23 tests, ~2s).
- *Spatie's permission cache* — **ruled out.** `CACHE_STORE=array` under test, so it is per-process.

Eleven further runs (six full-suite, four full-gate, one abandoned) did not reproduce it: **one
observed failure in twelve runs.**

The practical consequence is the one that matters for a floor whose whole job is to be believed:
**a red here cannot be distinguished from a flake by looking at it, and re-running until green is
indistinguishable from fixing.** Every "PASS 14/14, pasted raw" in every report rests on a gate with
this property. Treat a single green as weaker evidence than it looks, and a red that nobody can
explain as *unexplained* rather than as noise.

What this change does do is make the next occurrence diagnosable. The suite step (14 when this was
written, **15** since the sql-clock lint) used to write its junit and
suite output to fixed paths, so run C destroyed run B's evidence before anyone could read it — which
is why four hypotheses had to be built on the names of the failing tests alone. Artefacts are now
stamped per run and the last 20 kept, and a red prints where they are.

## Consequences

A document that describes CI as the gate is now wrong by ruling, not merely out of date. `CLAUDE.md`
and `docs/roadmap.md` are corrected in this change. `.github/workflows/*` stay on disk, disabled and
drifted — they are already behind `bin/quality` by several lints. They are not deleted, because
restoring CI is a decision the owner has reserved, and a deleted workflow is harder to restore than a
stale one. Nobody should read them as describing what runs.
