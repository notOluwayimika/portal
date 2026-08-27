# A deterministic fold refusal is retried three times

**Found:** cold review round 3 of `feat/ccm-fold-surface` (PR #306), 2026-08-27, and observed
directly in the browser drive the same day.

## What happens

`MoveFromCcmJob` declares `$tries = 3`. `CcmFoldRefused` — the silent-drop guard's refusal — is
**deterministic**: a scored CCM component with no non-CCM counterpart will still have no counterpart
on the next attempt, and on the one after that. The job fails identically three times before the
failure is recorded.

The drive shows it exactly, three identical FAILs in one worker pass:

```
App\Jobs\MoveFromCcmJob .. RUNNING
App\Jobs\MoveFromCcmJob .. 97.42ms FAIL
App\Jobs\MoveFromCcmJob .. RUNNING
App\Jobs\MoveFromCcmJob .. 27.52ms FAIL
App\Jobs\MoveFromCcmJob .. RUNNING
App\Jobs\MoveFromCcmJob .. 25.91ms FAIL
```

Two of those three runs are pure waste, and each one re-executes the fold's read path — resolving the
target curriculum, cloning subject rows, and running the per-component `Score::count()` — inside a
transaction it will then roll back.

## Why `$tries = 3` is still right, and the fix is narrower

Do not lower `$tries`. It is correct for what it is for: a deadlock, a lock-wait timeout, a dropped
connection mid-fold are all transient, and a fold that dies on one should retry. The problem is not
the retry policy, it is that ONE exception class is known in advance never to succeed on a retry and
is treated like the rest.

Laravel already has the seam: an exception implementing (or a job honouring) a non-retryable contract
stops the attempt loop. Marking `CcmFoldRefused` non-retryable records the failure on attempt 1,
leaves the reason in `failed_jobs` exactly as it does now, and leaves every transient failure
retrying three times as before.

## Second-order effect worth stating

`$tries = 3` with **no backoff** is currently load-bearing for the drive: because a failed attempt
re-releases immediately, the queue is never observed empty between attempts, so a single
`queue:work --stop-when-empty` survives all three and the drive can drain a doomed fold in one pass.
That is pinned as an asserted invariant in `CcmFoldDriveFixtureTest`.

**Making the refusal non-retryable does not break that** — with one attempt there is no gap to fall
through — but the invariant's *reason* changes, and the arm asserting "no backoff" should be re-read
rather than assumed to still mean what it meant. It guards the transient path after this change, not
the refusal path.

## What closes it

- `CcmFoldRefused` marked non-retryable, so the refusal lands on attempt 1;
- an arm asserting a refused fold produces exactly ONE `failed_jobs` row and one attempt — currently
  nothing pins the attempt count, which is why three-instead-of-one was invisible until a human
  watched a worker;
- and the `CcmFoldDriveFixtureTest` no-backoff arm's docblock updated to say which path it now
  guards.

## Not a correctness bug

The refusal is recorded correctly, the reason reaches the panel, and the fold rolls back clean on
every attempt — the drive confirmed `curriculum#4` untouched afterwards. This is waste and noise
(three stack traces in the log for one deterministic config problem), not incorrect behaviour.

## Related

- `docs/handoff/reports/feat-ccm-fold-surface-drive.md` § "Scenario 2 — the refusal, rendered"
- `tests/Feature/CcmFoldDriveFixtureTest.php` — the no-backoff invariant arm
