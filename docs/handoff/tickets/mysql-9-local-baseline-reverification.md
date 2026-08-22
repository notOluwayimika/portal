# TICKET — the local MySQL baseline moved to 9.7.1; measurements taken on 8.0.43 need re-taking

**Status:** open, not started. Raised 2026-08-22 while unblocking the push gate.
**Severity:** ticket, not stop. Nothing is broken today, and nothing here blocks a push, a merge or a
deploy. **Production is unaffected — it remains MySQL 5.7.23.**

## What happened

The developer machine's MySQL went **8.0.43 → 9.7.1** (Homebrew). The suite caught it immediately,
which is the point:

```
tests/Feature/Finance/PaymentAxisConcurrencyTest.php
  PROOF f0 — the isolation level this connection ACTUALLY uses, read from the server
  Failed asserting that '9.7.1' starts with "8.0."
```

That assertion is a deliberate tripwire — *"recorded so a future reader can tell whether the
measurement still applies"* — and it fired on exactly the event it was built for. It has been
**re-armed, not widened**: it now accepts `8.0.` **and** `9.7.`, the two prefixes actually verified,
and re-fires on the next move. A bare `9.` would have blessed 9.8 and everything after it unseen,
which is switching the alarm off while appearing to update it.

## What was re-verified on 9.7.1 (measured, not assumed)

| Claim | Reading on 9.7.1 |
| --- | --- |
| Session transaction isolation | `REPEATABLE-READ` |
| Global transaction isolation | `REPEATABLE-READ` |
| `BINARY expr` evaluates | yes |
| `COLLATE utf8mb4_bin` evaluates | yes |
| `student_curricula_promoted_requires_link_bi/_bu` still reject a promoted row with a NULL link | yes |
| `class_levels_progression_guard_bi/_bu` still reject a self-loop and a bad strategy value | yes |
| Every migration applies (the suite runs under `RefreshDatabase`) | yes |

So `PaymentAxisConcurrencyTest`'s f1/f3 reasoning — which depends only on REPEATABLE READ — still
holds, and the `BINARY`/`COLLATE` forms are **deprecated-not-removed** on 9.7. The shipped code is
fine there.

## What has NOT been re-verified, and is the actual work

Three measurements were taken on 8.0.43 and could plausibly *differ* on 9.7. Each ticket now carries
a banner saying so; this is the tracked list.

1. **Implicit `ON UPDATE CURRENT_TIMESTAMP` on rebuild**
   ([ticket](implicit-timestamp-defaults-on-rebuild.md)). The whole subject is a server behaviour
   under `explicit_defaults_for_timestamp`, so a server change is exactly the kind of thing that moves
   it. Re-run the scratch-table probe in that file on 9.7.1 and record the reading.

2. **Fee-item tie-order inversions** ([ticket](fee-item-tie-order-differs-across-read-paths.md)). The
   ~8k-row threshold at stock settings is an optimiser behaviour, and the optimiser is precisely what
   changes across major versions. The *inconsistency* the ticket describes is structural and unchanged;
   only the threshold number is in question.

3. **A future-proofing pass on `BINARY expr` and `COLLATE`**
   ([ticket](binary-expr-is-deprecated-in-the-july-allocation-trigger.md)). Both still work on 9.7, so
   this is not urgent — but 9.x is where MySQL removes deprecated syntax, and the seven merged triggers
   named in that ticket will need `CAST(… AS BINARY)` before a server that has actually dropped it.

## What this ticket deliberately does NOT ask for

Re-taking every "measured on 8.0.43" claim in `docs/handoff/reports/`. Those are **reports of work
already done** — historical records of what was true when the work shipped, not live claims. Editing
them would rewrite history rather than track a change. Only the open TICKETS carry the banner.

## Why the alternatives were rejected

- **Downgrade local MySQL to 8.0.x.** Pins the developer machine to an old server to keep a document
  literally true, fixes nothing for the next person who upgrades, and moves the alarm onto them.
- **Baseline the failing test.** Silences a signal that fired for exactly the reason it exists. The
  tripwire working is not a reason to remove the tripwire.
