# Forgetting a failed job makes its batch read "draining" forever

**Found:** cold review round 2 of `feat/ccm-fold-surface` (PR #306), 2026-08-26. Recorded in
`RolloverController::outstandingFailures()`'s docblock and left unguarded there; this is the ticket
for a real answer.

## What happens

`outstandingFailures()` counts ids in `job_batches.failed_job_ids` that **still have a `failed_jobs`
row**. That is deliberate and it is what makes an in-flight `queue:retry` read as draining: the
retry command pushes the job and then deletes the row, so a listed id with no row means a worker has
it.

The same shape is produced by **`queue:forget <uuid>`, `queue:prune-failed` and `queue:flush`** —
row gone, id still listed, but no retry and no worker. Then:

- `cancelled_at` null, `finished_at` null (a batch holding a failure never gets one),
- `outstanding` 0 → `pending > outstanding` → `settled_state` null → `is_draining` **true**,
- and `failureReasons()` returns `[]`, because the rows it reads are gone.

The operator gets **"Draining — do not change the current session yet"**, permanently, with no reason
beside it. That is defect B — the bug this branch exists to remove — reinstated in a new place, plus
the loss of the reason text.

## Why it is likely rather than exotic

**`queue:forget` is the natural response to a CCM fold refusal.** The refusal is deterministic
config: a scored CCM component with no non-CCM counterpart never succeeds on retry, and the panel's
own copy says so. An operator who reads "it will not resume on its own" and clears the dead job by
hand lands exactly here — the surface's advice leads to the state the surface then misreports.

Nothing schedules a prune (`routes/console.php` schedules only `authz:prune`), so it takes a
deliberate manual command. That is why it is a ticket and not a blocker.

## Direction, and why that sets the severity

It errs **falsely-cautious**: a warning that overstays. The operator waits on a batch that is dead
rather than acting on one that is live. That is the opposite of the direction which made the earlier
`failed_jobs` reading ship-blocking — *severity has a sign, not just a magnitude*
(`CLAUDE.md`, monotone-counter entry). Do not read "same defect as B" as "same severity as B".

## What an answer looks like

Options, none yet chosen:

1. **Treat a listed id with no row as terminal after a bound.** Needs a clock and a definition of the
   bound; risks calling a slow retry dead.
2. **Record the operator action** — a forget/prune that also prunes `failed_job_ids` would keep the
   batch row honest. Requires wrapping the queue commands, which is framework surface.
3. **Distinguish in-flight from forgotten by asking the queue**, not the failure table: a job pending
   on the `jobs` table for that batch is genuinely in flight. Costs a query and couples to the
   database queue driver.
4. **Say so on the panel** — "N failure(s) recorded, no detail available (cleared?)" when
   `failed_jobs > 0` and `failure_reasons` is empty. Cheapest, and honest rather than correct.

Option 4 is probably the right first move: it does not pretend to know, and it removes the
"draining forever with no reason" reading that is the actually-misleading part.

## Arms it needs

- id listed, row deleted, no retry → whatever the chosen semantics are, asserted explicitly;
- and the existing in-flight arm (`CcmFoldSurfaceTest`, "still DRAINING while a retried job is in
  flight") must stay green, because these two states are **indistinguishable in the batch row** and
  any fix has to say which one it is choosing to serve.

Note `tests/Feature/RolloverSurfaceTest.php:303-316` already plants this exact shape
(`failed_jobs: 1`, `failed_job_ids: '[]'`) and asserts `is_draining` true — so the suite currently
pins the prune shape as draining. Whatever answer is chosen must reckon with that arm rather than
quietly change it.

## Related

- `docs/handoff/reports/feat-ccm-fold-surface-drive.md` § "B, CORRECTED" — the derivation and what it
  knowingly does not cover.
- `CLAUDE.md` — a monotone counter is an accumulator, never a current-state signal.
