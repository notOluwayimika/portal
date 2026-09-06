# The both-set guard is PHP-only, and nobody has said that is the final answer

**Status:** open · **Opened:** 2026-09-06 · **Found by:** the tense correction to
`2026_09_04_100000`'s docblock, 2026-09-06 · **Severity:** ticket — the behaviour is correct and
proven; what is missing is a RULING, recorded, on the layer it lives at.

**RENAMED FROM THE FILENAME THE BRIEF SPECIFIED, deliberately.** The brief asked for
`the-both-set-guard-is-php-only-and-arm-a-gets-dearer-daily.md`. That name asserts something this
ticket measured and found false — arm (a) does NOT get dearer daily; see *"What arm (a) costs"*
below. A ticket filename is a claim other documents will cite for years, and this repository's rule
is that a description which quantifies has to be true or be weakened. The claim was weakened and the
filename follows it.

## The state, and where it is refused

`finance_invoices.reviewed_at` and `finance_invoices.returned_at` both non-null means *a bill that
Internal Audit released to the payer AND that is currently out with Finance for correction*. It is
incoherent and no path produces it.

**It is refused in application code, at both writers, and nowhere else:**

| direction | refused at |
| --- | --- |
| release over a return | `app/Finance/Actions/ApproveInvoice.php:174` — `->whereNull('returned_at')` in the compare-and-swap, with read-side refusals at `:163` and `:185` |
| return over a release | `app/Finance/Actions/ReturnInvoice.php:190` — `->whereNull(Invoice::RELEASE_STAMP_COLUMN)` in the mirror compare-and-swap, with the read-side `refuseIfAlreadyReleased` called at `:181` and defined at `:243` |

**At the database, nothing refuses it.** The migration that added the columns says so in its own
words and still does, deliberately, after the 2026-09-06 tense correction:
`database/migrations/2026_09_04_100000_finance_invoices_return_to_finance.php:60-84`. The triggers
that migration installs guard the row's INTERNAL CONSISTENCY — `returned_at` without its
`return_reason` and `returned_by_user_id` — and not this.

**The refusal is behaviourally proven, in both directions, by one arm carrying TWO assertions.**
`tests/Feature/Finance/ReturnedInvoiceQueueEndpointTest.php:115` opens `a1 — released-AND-returned is
unreachable in BOTH directions`; the assertions are at `:141` (a returned bill handed to
`ApproveInvoice`) and `:146` (a released bill handed to `ReturnInvoice` via `rbq_return`), and `:148`
then checks that neither reached the queue. The arm is also load-bearing for a neighbour:
`app/Finance/Http/Controllers/ReturnedInvoiceQueueController.php:149-161` documents its own release
filter as a BELT rather than a working exclusion, and cites this arm as what keeps that statement
true.

*One observation on the arm, recorded and not acted on:* **both** assertions are
`toThrow(BusinessRuleException::class)` with no message. `ApproveInvoice` raises that class from at
least three sites (`:157` void, `refuseIfAlreadyReleased`, `refuseIfOutWithFinance`) and
`ReturnInvoice` from three of its own (`refuseIfAlreadyReleased` at `:243`, `refuseIfAlreadyReturned`
at `:279`, and the actor check above them), so **neither** assertion names the MECHANISM —
CLAUDE.md's *"assert WHICH negative"*. On the fixtures as built the mechanism is determined in both
cases, so this is a robustness note for whoever touches the file, not a finding.

## Why this is a ticket and not a defect — and the difference from its sibling

`docs/handoff/tickets/void-eligibility-docblock-contradicts-its-own-code.md` records the same class:
a control that reads as structural and is procedural. There, `VoidEligibility`'s credit-note limb is
monotonic because of a PHP state machine, while a docblock cited a trigger that had been dropped six
weeks earlier.

**The difference is the whole reason this one is a ticket rather than a defect.** There, the layer
was MISDESCRIBED AFTER THE FACT — somebody reasoning from the database outwards would reach the wrong
answer, and the prose was simply wrong. Here it was DECLARED IN ADVANCE: the migration named both
routes before either was taken, said out loud that the schema does not close the gap, and deferred
the choice to the commit that would add the writer. That commit shipped, chose (b), and wrote its
reasoning beside the guard (`app/Finance/Actions/ApproveInvoice.php:87-101`).

So nothing here is wrong and nothing is undocumented. What is missing is narrower: **no one has
said that (b) is the FINAL answer rather than the first one.** The migration offered two routes; one
was taken; the other was never closed, and an option nobody closed reads to the next person exactly
like an option nobody noticed.

## What arm (a) costs, and the freeness argument — carried carefully, because it does not transfer

The migration makes a freeness argument, at
`database/migrations/2026_09_04_100000_finance_invoices_return_to_finance.php:54-58`, verbatim:

> There is NO BACKFILL here: every existing row is correctly "not returned" already, and NULL says
> so. […] The consequence is that the guard below can be installed unconditionally — **the instant
> one unpaired row exists, it could never be added without a data fix first, so this is the only
> moment it is free.**

**READ WHAT THAT SENTENCE IS ABOUT.** It is about the PAIRING guard — `returned_at` without its
companions — and *"the guard below"* is that trigger, not a both-set arm. It is quoted here because
the SHAPE transfers, and the shape is the thing worth carrying: a trigger installed unconditionally
is free exactly while no row violates it, and never again.

**But the trigger condition is different, and so the cost curve is different.** The pairing guard's
clock starts ticking the moment anything writes a `returned_at`. Arm (a)'s clock starts only when a
BOTH-SET ROW exists — and both writers refuse to create one, with an arm pinning both refusals. So:

- **arm (a) is not getting dearer with every row.** It gets dearer the day a both-set row first
  exists, and until then its cost is unchanged.
- **What IS rising is the surface that would have to be re-checked before installing it.** Every
  future writer — an off-request job, a Phase B resubmission path, a backfill, an import — is one
  more thing to audit before an unconditional `CREATE TRIGGER` is safe, and one more thing that could
  produce the row that ends the free window without anyone noticing.
- **And the current row count is UNKNOWN, not zero.** It could not be measured here: the local
  development database has replayed **179 of the tree's 181** migrations, and the two it has not are
  `2026_09_04_100000_finance_invoices_return_to_finance` and
  `2026_09_04_110000_finance_invoices_auditor_queue_index`. `returned_at` does not exist locally, so
  the count is unmeasurable on this machine rather than measured as zero. On any environment where
  the column DOES exist, it arrived with no backfill — so it was 0 at the moment of arrival, and
  what it is now is a question for that environment, not for this one.

## The two positions. Choosing NEITHER.

**Position 1 — PHP-only is sufficient, and (b) is the final answer.**

The argument is already written, at `app/Finance/Actions/ApproveInvoice.php:92-101`, and it is a
real one: the pairing triggers guard faults ANY writer can commit, including a DBA at a prompt, so
they belong where every writer passes; approve-over-a-return is a SEQUENCING fault on one code path
with exactly one writer, so its guard belongs in the atomic UPDATE whose affected-row count already
decides the outcome. It adds that a trigger would make the Phase B *"re-review after resubmission"*
flow a SCHEMA change rather than a code change, and would refuse an operator from the database with
a message about a rule they did not break.

**What it costs today:** a raw `UPDATE` reaches the state, `down()`ing the actions restores it, and a
second writer added without both predicates opens it silently — there is no red, so by CLAUDE.md's
adoption-gradient rule the predicate propagates by memory alone. That last cost is partly paid
already: `tests/Arch/ReviewCompareAndSwapCarriesBothPredicatesTest.php` gates the PRESENCE of both
predicates and is explicit that it gates presence and NOT behaviour. Whether it covers a NEW writer,
or only the two that exist, was not measured here.

**Position 2 — money invariants belong at the database, and this one is on the wrong side.**

`finance_invoices` is append-only-by-trigger and every other invariant on it is a schema object.
This is the one exception, and it is the one that decides whether a payer can be shown a charge the
school has taken back for correction. A trigger holds against a writer nobody has written yet, and
against a prompt.

**What it costs today:** the third arm itself, plus a `COLLATE utf8mb4_bin` review if it ever touches
a string (it does not — two timestamp NULL-checks), plus a data check on every environment where
`returned_at` exists, plus accepting that Phase B's resubmission flow becomes a schema change. And
that last cost is not hypothetical: Phase B is named in the tree as explicitly about that flow.

## What would close it

**A ruling, written down, in one of two places.** Either (1) an ADR or a line in the migration
docblock saying (b) is final and arm (a) is closed, not deferred — in which case delete the option
from the reader's mind rather than leaving it named; or (2) a decision to add arm (a), scheduled
against a measured both-set count in every environment that carries the column.

**Do not build either from this ticket.** The point of this ticket is that a person has to choose,
and that the choice is currently being made by silence.

## Cross-references

- `database/migrations/2026_09_04_100000_finance_invoices_return_to_finance.php:60-81` — the
  paragraph, as corrected 2026-09-06.
- `app/Finance/Actions/ApproveInvoice.php:87-101` — the reasoning for (b), beside the guard.
- `tests/Feature/Finance/ReturnedInvoiceQueueEndpointTest.php:115` — the both-directions arm.
- `docs/handoff/tickets/void-eligibility-docblock-contradicts-its-own-code.md` — the sibling; same
  class, opposite provenance.
- `docs/handoff/what-correct-returned-invoice-must-satisfy.md:1135` (finding 14) and `:1216`
  (open question 11) — where this was first recorded as open. Both cite the paragraph as `:60-69`,
  which was its range BEFORE the 2026-09-06 tense correction; it is `:60-84` now.
