# TICKET — `bin/quality` is not safe to run from two working trees at once

**Status:** open, and deliberately framed as a **harness question rather than a fix**. Two of the three
resources involved are shared on purpose, and narrowing them has costs that have to be decided, not
assumed. Raised by `feat/u8-wire-ids-uuid` after a reviewer running the gate in a fresh clone
invalidated its own first run against this tree's concurrent Pest.

**Root:** the gate's three pieces of per-run state — the test database, the artefact directory, and the
two fixed symlinks — are keyed to the machine and the user, never to the checkout. Two trees of the
same repository, run by the same person, contend for all three.

## The three, with citations

**1. One database name, hard-coded.**

```
bin/quality:238   check "arch" env DB_DATABASE=portal_testing ./vendor/bin/pest --group=arch
bin/quality:266   env DB_DATABASE=portal_testing ./vendor/bin/pest --log-junit "$QUALITY_JUNIT" …
```

The suite runs `RefreshDatabase`, so a concurrent run is not two suites sharing rows — it is one suite
migrating and truncating the schema the other is mid-assertion against. The failure is arbitrary and
does not look like contention: it looks like a red in whatever arm happened to be executing.

**2. One artefact directory, per-user not per-tree.**

```
bin/quality:259   QUALITY_ARTEFACTS="${TMPDIR:-/tmp}/quality-runs"
bin/quality:261   QUALITY_RUN_ID="$(date +%Y%m%d-%H%M%S)-$$"
bin/quality:274   ls -1t "$QUALITY_ARTEFACTS"/junit-*.xml | tail -n +21 | xargs -r rm -f
```

`TMPDIR` is a per-user path on macOS, identical across checkouts. The run id (timestamp + pid) keeps
files from colliding, so no run overwrites another's — but the **20-run retention window is shared**,
so a second tree's runs evict this tree's history at twice the rate the comment above `:259` assumes.
That comment is explicit that the artefacts exist so a red "is still on disk whenever anyone notices
it", which is the property the sharing erodes.

Observed on this machine, listing that directory: runs stamped `222349` (both files **0 bytes**, an
aborted run) and `222716` sit alongside this tree's `200656`, `212155`, `213147`, `215109`. The
0-byte pair is what an interrupted concurrent run leaves behind.

**3. Two fixed symlinks, last writer wins.**

```
bin/quality:271   ln -sf "$QUALITY_JUNIT" /tmp/quality-junit.xml
bin/quality:272   ln -sf "$QUALITY_LOG"  /tmp/quality-pest.log
```

Currently resolving to:

```
/tmp/quality-junit.xml -> …/quality-runs/junit-20260814-222716-26169.xml
/tmp/quality-pest.log  -> …/quality-runs/pest-20260814-222716-26169.log
```

— the other tree's run, not this one's. The block's own comment says the fixed paths stay "because
bin/ci-test-ratchet.php, the docs and everyone's muscle memory refer to them, and 'the latest run' is
the common case". That is right for one tree. Across two, "the latest run" silently stops meaning
"my latest run", and someone reading `/tmp/quality-pest.log` to diagnose their own red reads someone
else's suite.

Note what is *not* affected: `check "test-ratchet"` at `:277` is passed `"$QUALITY_JUNIT"` — the
stamped path — not the symlink. The ratchet always grades the run that produced it. The symlinks are a
human-facing convenience, and the confusion is a human one.

## Why this is a question and not a patch

Each obvious narrowing trades something the current design chose deliberately:

- **Per-tree database** (`portal_testing_<hash of the checkout path>`) removes the contention, and
  costs the property that everyone's suite runs against the same named database — which is what
  `CLAUDE.md` and every runbook tell a newcomer to create. It also multiplies local databases.
- **Per-tree artefact directory** restores the 20-run window per checkout, and costs the single place
  to look.
- **Dropping or per-tree-ing the symlinks** costs exactly what the comment at `:268-270` says it
  costs.
- **A lock file** (refuse to start while another `bin/quality` holds it) fixes the database
  contention without renaming anything, and costs a queued wait — plus a stale-lock story on a killed
  run.

There is also a fifth option — decide that concurrent runs are out of scope and say so in the script,
so the next person diagnoses a corrupted run in minutes instead of hours. That is a legitimate answer
and is cheaper than all four.

## What made this visible

A cold review of `feat/u8-wire-ids-uuid` was performed in a separate clone. Its first `bin/quality`
was invalidated by this tree's concurrent Pest run; the reviewer noticed because the failures did not
correspond to the diff. Nothing in the gate's output said "another run is in progress", and nothing
could have: no piece of the mechanism can see the other tree.

## And then it was reproduced by accident, in one tree, which is the sharper finding

Writing this ticket, the author launched `bin/quality`, believed it had died (its captured stdout was
0 bytes and a `ps` grep found nothing), and launched a second. Both were alive. The artefact directory
records the overlap plainly — two runs eleven minutes apart, each with a full-size log, which only
happens if both reached the suite:

```
pest-20260814-232332-63086.log  1.4M     ← second run
pest-20260814-231232-60036.log  825.3K   ← first run, still going
```

The second run reported **FAIL (2): larastan test-ratchet**:

- `larastan` did not fail an analysis. It **timed out** —
  `The process "phpstan analyse --no-progress --memory-limit=1G" exceeded the timeout of 300 seconds`
  — under the CPU load of two suites.
- `test-ratchet` reported **23 new failures**, spread across `WalkingSkeletonTest`,
  `WalletApplyForwardTest`, `WalletConcurrencyTest`, `WalletCreditTest`, `WalletW3ConcurrencyTest` and
  `MakerCheckerSeparationTest` — **not one of them a file the commit touched**, and
  `pest tests/Feature/Finance/` had passed 522/522 minutes earlier on the same tree.

**This is the important part: the output is indistinguishable from a real regression.** It names real
tests, in a plausible cluster (wallet, concurrency, maker-checker), with the ordinary "Fix the
regression, or add it to `tests/ratchet-baseline.txt`" instruction. Nothing in it says "you are
running two suites against one database". Following that instruction — baselining 23 arms — would
have permanently disabled a block of money and duty-separation proofs to silence a harness artefact.

So the failure mode is not confined to two checkouts. **One checkout is enough**, whenever a run is
believed dead and is not. That widens the ticket: whatever is chosen from the four options above, the
cheapest single improvement is for `bin/quality` to refuse to start — or at minimum say so loudly —
while another instance holds the database.

The artefacts for that run were copied out before anything was re-run, per
`docs/testing.md`'s capture-before-you-re-run rule. The clean single run afterwards passed
`test-ratchet`, which is what confirms the 23 reds were the harness and not the diff.

## A second, unrelated red the same run exposed: `larastan` reports a TIMEOUT as a failure

Separable from the contention above, and it survived the clean run, so it is not contention.

`bin/quality`'s larastan step invokes phpstan through composer, which imposes a **300-second process
timeout**. On a cold or largely-invalidated result cache that is not enough:

```
> phpstan analyse --no-progress --memory-limit=1G
The following exception is caused by a process timeout
  [Symfony\Component\Process\Exception\ProcessTimedOutException]
  The process "phpstan analyse --no-progress --memory-limit=1G" exceeded the timeout of 300 seconds.
```

Run directly with `COMPOSER_PROCESS_TIMEOUT=0`, the same analysis **passes with zero errors** and takes
**12m35s** (`212.37s user 564.30s system 102% cpu 12:35.33 total`). `build/phpstan` is a 100 MB
repo-local result cache (`phpstan.neon:24`, gitignored via `.gitignore:39`), and a warm run finishes
inside the window — which is why this step has been green on every previous run in this tree and went
red twice in a row here, on a cache the two concurrent runs had churned.

**The step cannot distinguish "the code has type errors" from "the analysis did not finish".** Both
print `✗ larastan` and both block the push. That is the same class as everything else in this ticket:
an output that names a real gate and gives no way to tell a finding from an artefact. Anyone who reads
that red as "my diff broke type checking" will go looking for an error that does not exist.

The narrow fix is to give the step its own timeout — `COMPOSER_PROCESS_TIMEOUT=0` in front of the
invocation, or calling `./vendor/bin/phpstan` directly and skipping composer's wrapper — and to print
something different when the process times out. That is a change to the enforcement floor, so it is
recorded here rather than made from a branch the floor is verifying.

Both of the residuals `CLAUDE.md` already records for the local floor — "Clean-room OS/env" and
"Determinism" — are about a red that cannot be told from a flake by looking at it. This is a third,
concrete instance of that class, and unlike the ADR 0053 non-determinism its cause is known.

## Third instance: ONE tree, and the second process is not another gate — it is the cold review

Recorded 2026-08-20 from `feat/u7-supplementary-invoice-wire`. Measurements and raw output in
`docs/handoff/reports/feat-u7-supplementary-invoice-wire.md` § 10.

The ticket's title says "two working trees". This instance had **one tree, one checkout, one
person**, and no second `bin/quality` anywhere. What contended for `portal_testing` was the
**gated push and the cold-review subagent, launched in the same breath** — the two halves of
this project's own method, which `finance-execute` requires to happen for every non-trivial
change. Resource #1 above is the whole cause; #2 and #3 are not involved.

That makes it a **recurring structural collision, not an accident**. Every branch that follows
the method ends the same way: write the report, spawn the reviewer, push. The reviewer reads
the repository and runs tests; the push runs the full suite under the hook. Both use the one
hard-coded database at `bin/quality:238` and `:266`, and `RefreshDatabase` means the loser of
the race is mid-assertion against a schema the winner is dropping. Nothing in either output
mentions the other.

### The symptom, so it is recognisable in one line

**Both sides of this collision produced a red, and neither red resembled the other** — which
is the point, and the reason to write down more than one signature. The subsystem named is
whatever happened to be executing when the tables went; the *shape* is a DDL-under-a-live-suite
error, never an assertion failure about the diff.

**The cold review's run** (six-test file, spoiled by the push's suite; PID 33834 observed):

```
SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '46-11' for key 'role_has_permissions.PRIMARY'
SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction
        (update roles set name = accounts_supervisor where name = finance_director)   ×3
```

**The gated push's run** (full suite, spoiled by the review; blocked at step 15/15):

```
SQLSTATE[42S02]: Base table or view not found: 1146 Table 'portal_testing.schools' doesn't exist
SQLSTATE[42S02]: Base table or view not found: 1146 Table 'portal_testing.activity_log' doesn't exist
SQLSTATE[HY000]: General error: 1412 Table definition has changed, please retry transaction
SQLSTATE[42S22]: Column not found: 1054 Unknown column 'roles.id' in 'on clause'
```

**The one-line recognition rule: if a suite-wide red is dominated by `1146` / `1412` / `1054` /
`1213` / a `1062` on an RBAC seeder pivot — rather than by assertions about your own change —
you are looking at a second process on `portal_testing`, not a regression.** Check
`ps -eo pid,command | grep vendor/bin/pest` before believing anything else. A phantom of this
kind cost roughly one full gate cycle here, on top of the review's own lost run.

### It is worse to read than the 23-red instance above, for two reasons

**The ratchet's print understates it by an order of magnitude.** The push above was refused
with **23** regressions printed. The run underneath that print was **15 failed and 321 errors
out of 1833 tests** — the ratchet reports only what regressed against
`tests/ratchet-baseline.txt`, so a catastrophically corrupted run and an ordinary 23-test
regression present with the same headline. Anyone deciding what to do from the printed list is
deciding from about 7% of the evidence. Read the stamped log, not the step's summary.

**One of the casualties is a test built to refuse exactly this.** The only Finance entry in
the entire corrupted run was `tests/Feature/Finance/TriggerBodiesAreDumpSafeTest.php`:

```
no triggers found — this test would pass vacuously and prove nothing
```

That is the anti-vacuous guard doing its job on a database whose triggers had been dropped
underneath it — a correct refusal, appearing in a list of 23 "regressions" a reader is being
invited to baseline. Baselining it would permanently disable the guard.

### What separates this from ADR 0053's non-determinism

`CLAUDE.md` records byte-identical code producing both PASS 14/14 and FAIL 23, cause unfound.
This is **not** that, and the two must not be conflated or the known cause gets filed as the
unknown one:

| | ADR 0053 flake | This contention |
| --- | --- | --- |
| Signature | `FAIL 23`, tables all present | missing tables, `1412`, `1054`, deadlocks |
| Cause | unknown, investigated | known and observable with `ps` |
| Re-run | indistinguishable from fixing | legitimate once the other process is confirmed gone |

The re-run here was made after `ps` confirmed no live `pest`, on a byte-identical tree, and
passed **15/15**. That is the discrimination the capture-before-you-re-run rule exists to make
possible, and it only worked because the artefacts were copied out first.

### What this adds to the options above

The four narrowings and the fifth "declare it out of scope" all still apply, but this instance
changes which is cheapest. **The lock file is now the strongest candidate**, and its cost is
lower than the section above assumed: the contending processes are not two humans waiting on
each other, they are a push and a subagent on one machine, so a queued wait is a wait for
yourself. "Declare concurrent runs out of scope" is *not* an adequate answer any more —
concurrent runs are not a mistake here, they are what the documented method produces.

Cheapest useful thing short of a lock, and independent of it: `bin/quality` printing the
recognition rule above when it fails, so the phantom is named at the moment it is seen.

Until something changes in the script, the operational rule is a sequencing one and belongs
wherever the method is written down: **spawn the cold reviewer, wait for it, then push.** Never
in the same message.
