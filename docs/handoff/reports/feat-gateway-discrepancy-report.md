# `feat/gateway-discrepancy-report` — the frame, without the classes

**Base:** `staging` @ `ead8d627`. **Branch:** `feat/gateway-discrepancy-report`.
**Shape:** one command, one service, one config file, one test file, two registrations. One commit.

## What is deliberately absent

**The detectors.** §8 of the boundary and addendum documents specifies the discrepancy classes, and
§8 was not available. The classes were **not reconstructed from the data model**: a partition guessed
from the schema is plausible by construction and wrong in whichever dimension the schema cannot show
— what reads here as one class may be two in §8, split on a distinction nobody in this session knows
about. Rebuilding a detector costs more than waiting for the spec.

So `detectors()` returns `[]`, and the work here is the frame that makes that safe.

## The property under test

**An unbuilt or partially-built report cannot render as a clean one.**

Coverage is three numbers, not two — `examined`, `excluded`, `unrecognised` — asserted to sum to the
population. `unrecognised` is separate from *examined-and-clean* because those are opposite facts
that a two-number report prints identically, and "no findings over an unexamined population" is the
green-that-measures-nothing shape this project has been bitten by repeatedly.

Two structural decisions follow from it:

1. **Examined ids come FROM the detector, never from the frame's belief about what it looked at.** A
   detector that narrows its own `WHERE` silently shrinks the denominator; here that narrowing
   surfaces as `unrecognised` instead of as nothing. An exclusion must be a **named rule** that gets
   printed, so a reader can disagree with it.
2. **The empty registry is judged BEFORE the arithmetic.** This was a real hole, found while writing
   the tests: on a database with no gateway transactions, population/examined/unrecognised are all
   zero and every downstream check passes — so the unbuilt report would have printed a clean result
   on exactly the environment most likely to run it first. Having no detectors is a fact about the
   file, not about the data, so it is answered on its own rather than inferred from a count that can
   collapse to zero. The degenerate-fixture class, in the production code rather than in a test.

## `--pending-hours`: config with no default, and the flag does not become one

`GatewayPendingWindow` refuses when neither the flag nor `finance.discrepancy.pending_hours` carries
a positive whole number. The reason it has no default is stronger than the family precedent: **the
number is the report's meaning.** At one hour every half-finished checkout is a finding; at a week,
money the provider took and this system never recorded is invisible for seven days. Those are two
different reports, not two settings of one.

`--pending-hours=N` overrides for an operator running by hand. When it is absent the config is used;
when the config is absent the command refuses. An override with nothing to override is still a
refusal — the tempting shortcut being to let the flag's own default paper over the missing ruling.

## Verified — every guard suppressed, watched red, restored

15 tests, 39 assertions, green restored after each. Raw counts as reported by the runner.

| suppressed | result |
|---|---|
| empty-registry guard | **RED ×3** — and the empty-database arm exited **0**, printing a clean report from a command with no detectors. That is the hole the guard closes, reproduced. |
| `unrecognised > 0` branch | **RED ×2**, both exiting **0**: an unexamined transaction reported as nothing. |
| coverage sum check (`unrecognised < 0`) | **RED ×1**, exit **0** — over-counting reads as "more than fully covered" and prints clean. |
| config default planted (`config(..., 24) ?? 24`) | **RED ×1** on the message assertion; exit code alone would not have moved. |

The last row is why every arm asserts **which** refusal. There are four distinct failure modes and
they all exit 1; an arm satisfied by any of them would pass against a command that always refuses —
the broken-closed shape `bin/db-exclusive` shipped with.

The second bite-proof is worth reading for the same reason: with the empty-registry guard removed,
the *transactions-exist* arm still exited 1 (via `unrecognised`) and was caught only by its message
assertion, while the *empty-database* arm flipped to 0. Two arms, one mutation, two different
signals — which is what having both arms is for.

**A fixture defect, found and fixed:** two transactions in one school collided on
`(school_id, number)`. It surfaced as a Pest **error**, not a failure — 13 of 15 "passed" — which is
the summariser trap this repo already records. The invoice number is now derived.

Also run: `--group=arch` 127/127, boundary lint OK, citation lint OK, Larastan clean on the new files.

## Deviations and things a reviewer should attack

- **`detectors()` is `protected`, not `private`,** so a test subclass can substitute the registry.
  Without it the coverage arithmetic could only be tested through real detectors, which do not exist
  — and a frame whose central claim is "an unexamined row is visible" must not ship with that claim
  unproven. It is a deliberate widening for testability; say so if you disagree.
- **`config/finance.php` is created here and is also created by PR #369** (the gateway initiation
  branch), which adds `gateway.minimum_part_payment_minor`. Both are top-level-key-disjoint, so the
  conflict is mechanical, but it **is** a conflict and whoever merges second must keep both keys.
  Branch stacking is forbidden here, so there was no way to avoid it. Same for `.env.example`.
- **Not scheduled.** Its three siblings in `routes/console.php` run daily. A nightly job that fails
  every night because the report is unbuilt is an alarm meaning "not finished", and an alarm that
  always fires is one people learn to close. Scheduling is owed in the same change as the classes.
- **`GatewayMinimumPayment` is named in prose, not as `{@see}`,** because it is not on this base — a
  citation at a class that does not exist is one nobody can follow.

## What is NOT closed

- **The §8 class set.** Nothing here guesses at it.
- **The window value.** Unruled. Not a data-model question, so not Developer 1's; not put to Segun.
  Named as open in the config comment, the service docblock and the command docblock, with **no
  owner invented** — writing a plausible one in would make an unasked question look answered.
- **The operational half** — who reads this, how fast, and what they may do about a finding. Also
  named as open, also unassigned.
- **D3 in particular is gated on #369**, in both halves and not one: `GatewayFeeCalculator` (the
  computed side of fee divergence) and the `bill_minor` columns are both on that branch, and staging
  carries no fee arithmetic at all.
