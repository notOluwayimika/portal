# Observe mode has no liveness signal — a dead sink and a quiet month look identical

**Raised:** 2026-09-02 · **From:** the `authz_observations` census · **Severity:** ticket (blocks the `AUTHZ_ENFORCE` flip)

## What

`App\Support\Authz::record()` writes the observation inside a `try`, and swallows every failure
(`app/Support/Authz.php:80-87`):

```php
} catch (\Throwable $e) {
    // Observe mode must never break a request: a failed observation is
    // logged, not raised.
    Log::warning('authz-observe: failed to record observation', [ … ]);
}
```

**That is correct and must stay.** An evidence sink that can 500 a user's request is worse than no
sink. The defect is not the `catch`; it is that **nothing downstream can tell whether the sink was
running.**

## The consequence, and it is not hypothetical

An empty window in `authz_observations` has two readings that the table cannot distinguish:

1. no denials occurred; or
2. denials occurred and were not recorded.

**This blocked a real reading on 2026-09-02.** The census returned 149 rows, all between 2026-07-21
and 2026-07-31, and **nothing at all** on or after 2026-08-03 — the date the parent portal moved to
`/api/parent/wards`. That emptiness is the whole basis on which
[`guardian-view-asserted-on-a-route-its-users-cannot-satisfy.md`](guardian-view-asserted-on-a-route-its-users-cannot-satisfy.md)
would say the parent population has left the old route, and it could not be relied on.

Reading (1) was made *plausible* by a separate measurement — `activity_log` averages **2,452.5**
rows/day across July against **11.4** across August, a **215×** collapse, with thirteen August days
carrying no rows at all. Term-end traffic stopping explains a 100% observation drop without any
mechanism. **Plausible is not established.** Nothing in the table, or anywhere reachable from this
repository, excludes reading (2).

## This is NOT the gap the runbook already covers

`docs/runbooks/authz-observation-review.md:58-68` already says:

> **"Classes that never appear.** An ability nobody hit in the window is invisible here — that is
> exactly why §24 condition 4 (a live 403 probe after the flip) exists. **Absence of observations is
> not evidence of safety.**"

**The difference in one sentence: that is a property of TRAFFIC — nobody exercised the path — and
this is a property of the INSTRUMENT — the path was exercised and the recorder did not record it.**

And the live 403 probe does not cover this one. A5's probe fires **after** the flip and proves that
one chosen request is refused. It says nothing about whether the *pre-flip* window was measured — and
that window is the entire basis for deciding the flip is safe to attempt. A probe that confirms
enforcement works is not a probe that confirms the evidence you enforced on was complete. **The
runbook's answer is downstream of the decision this gap corrupts.**

## What Phase 1 established about the discriminator

**The discriminator for a dead sink is the application log on the host that served the traffic, and
nothing in this repository can reach it.**

`Authz::record()`'s `Log::warning` emits a fixed string, `authz-observe: failed to record
observation`, so matches in production's log would confirm reading (2) outright. Grepping for it here
returns **zero matches across all six files in `storage/logs/`**, and that excludes nothing, for two
independent reasons:

- **The local log does not cover the window.** `storage/logs/laravel.log` carries only `2026-08` and
  `2026-09` entries, beginning 2026-08-29. July is not in the file. Absence in a log that does not
  reach the window is not absence of failures.
- **It is the wrong host anyway.** The rows are *production* rows read from a production copy;
  `storage/logs` is a developer machine's log of that machine's own requests. Even with full date
  coverage it would be the wrong instrument.

**So the gap is exactly this: the only discriminator lives somewhere the repository cannot see, and
the table that is supposed to be the evidence cannot speak for itself.**

## The requirement — stated as a requirement, not an implementation

> **A reader of `authz_observations` must be able to tell "no denials occurred" from "nothing was
> recorded", FROM THE DATA, without access to the serving host's log.**

Any answer satisfying that sentence closes this. The candidates visible in source, with their costs
— **not a recommendation; the choice belongs to whoever owns the flip:**

- **A heartbeat row.** The observer writes a periodic marker (scheduled, or on the first request of
  each interval) through the same code path. A window with heartbeats and no denials is a measured
  zero; a window with neither is an unmeasured one. *Cost:* it must traverse the **same** write path
  to be a real liveness signal — a heartbeat written by a different mechanism proves that mechanism
  is alive. It also adds rows to a table `authz:prune` bounds, and the prune must not delete the
  heartbeats that make a pruned window readable.
- **A failure counter that is not the log.** Increment a durable counter (cache, a metrics sink, a
  one-row table) in the `catch`, so failures are visible without the host's log. *Cost:* the counter
  can fail for the same reason the insert did — if MySQL is the problem, a MySQL counter is not the
  answer — so its storage must be independent of the sink's.
- **Emit the failure to a channel that leaves the host.** `config/logging.php` already resolves a
  stack; a channel that ships (or a dedicated `authz` channel with its own retention) makes the
  discriminator reachable. *Cost:* infrastructure, and it moves the answer outside the repo again —
  better than today, but still not "from the data".
- **Do nothing structural; record window provenance by hand.** Whoever relies on a window states,
  in the classification artifact, what independently corroborated that the sink was live for it —
  as the `activity_log` measurement was used here. *Cost:* it is a convention with no mechanism, and
  this repository's own rule is that such a rule is wallpaper. It is honest only if labelled as a
  manual step that will be skipped.

## The classification artifact has no state for a class whose check is being removed

Read from source, because this ticket asserts a gap in it and the assertion has to be checked.

`docs/runbooks/authz-observation-classifications.json` holds `{"_readme": …, "classes": []}` —
**empty**. `AuthzObservations::classifications()` (`app/Console/Commands/AuthzObservations.php:147-160`)
keys entries by `ability.'|'.controller_action`, and the summarizer renders
`$classifications[$key]['classification'] ?? 'UNCLASSIFIED'` (`:113`).

The `_readme` defines exactly two values, `expected` and `regression`. **A class whose check is being
REMOVED is neither** — on the flip it produces no denial at all. It is **obsolete**, and there is no
state for that.

**Two things follow, and the second is a finding in its own right:**

- **A third state is needed** — `obsolete`, meaning "this class disappears when its ticket lands, and
  the ticket is the evidence". Without it, a removed check must be recorded as `expected`, which
  asserts something false (that the denial is correct and will happen), or left `UNCLASSIFIED`, which
  holds the gate closed for a class nobody will ever hit.
- **The gate does not enforce the vocabulary.** `--unclassified` filters on
  `$r['classification'] === 'UNCLASSIFIED'` (`:118`), so **any** other string satisfies it — a typo,
  a placeholder, or a state nobody agreed to. Adding `obsolete` therefore costs nothing mechanically
  and buys nothing either, unless the allowed set is asserted somewhere. The vocabulary is documented
  in a `_readme` and enforced nowhere: the same stated-and-ungated shape as
  [`guardian-binding-applicability-is-ungated.md`](guardian-binding-applicability-is-ungated.md).
  Whoever adds the third state should pin the set of three in the same change, or the artifact's
  values are a convention and the gate only checks that *something* was written.

**No entries were added by the change that raised this ticket.** Classification follows disposition:
a class cannot be marked until its ticket says whether the check stays, changes, or goes.

## Retention — the window is 33 days old and the default prune deletes all of it

The 149 rows span 2026-07-21 to 2026-07-31. The newest is **33 days old** as of 2026-09-02.
`authz:prune --older-than=30` — the documented default, and the retention `post-deploy-tasks.md`
states for Track A — cuts at 2026-08-03 and would delete **every one of them**. **August produced no
observations at all** to replace them.

So the evidence for all four observed classes is one prune away from gone, and it cannot be
regenerated: the traffic that produced it was a term ending. **Classification must precede any
prune.** And note the bind this ticket describes, sharpened: after such a prune the table would be
empty and *still* unable to say whether that emptiness means anything.

## Precondition for the `AUTHZ_ENFORCE` flip

**Every row in `authz_observations` is a 403 the flip will make real.** The flip is blocked until
that table is empty, or until every remaining row is a denial we have read and intend to enforce.
The production census is not a supporting check — **it is the flip's blast radius**.

**And this ticket adds the condition on top of that:** an empty window is evidence **only if the sink
is known live for that window**. Until this is answered for the window being relied on, an empty
result cannot be counted as "no denials" — which means the flip is blocked on this ticket for exactly
as long as the safety argument rests on a window nobody can vouch for.
