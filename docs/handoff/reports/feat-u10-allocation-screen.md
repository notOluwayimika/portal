# feat/u10-allocation-screen — the allocation screen, its writer, and the lock that makes it safe

**Branch:** `feat/u10-allocation-screen`, cut from `origin/staging` at `bdc2e9c`.
**Spec rows:** U10 (`docs/handoff/finance-mvp-cut-brief.md:131`), with §9 item 6's mismatch
requirement at `:307` and the half-built warning at `:330`.
**Measured on:** MySQL **8.0.43**, PHP 8.3.32, databases `portal_testing` and `portal_drive`,
2026-08-22. Every number below was taken on this branch in the session that wrote this file.

Four commits, each gated on its own push:

| | Commit | What |
| --- | --- | --- |
| 1 | `3751643` | read side — the proposal endpoint, its read model, the permission |
| 2 | `9ee3ece` | write side — the Action, the account lock, the third rule, the provenance pairing |
| 3 | `661f217` | the screen, and the statement's entry point to it |
| 4 | this one | the drive fixture U10 needed, the captures, and this report |

---

## 0. Two things I got wrong, at the top

**I disagreed with the brief on a factual premise before building, and I was right to.** The brief
says "Read how the account is snapshotted onto the invoice line before deciding how to surface it."
It is not snapshotted. `finance_invoice_lines` has no `bank_account_id` and that is argued at length
in `2026_08_10_120000_finance_bank_account_foreign_keys.php` § "finance_invoice_lines — DELIBERATELY
NOT IN SCOPE": S11's snapshot cannot land until fee items are the source of lines, and a nullable
column with no writer would be "a primitive ahead of its consumer". What I built instead is § 4.

**And I broke my own gate run by editing the tree under it.** The first push of commit 1 came back
red with **319 failures**, and 312 of them were one message — `The use statement with non-compound
name 'RuntimeException' has no effect` — a PHP warning from commit 2's migration file, which I wrote
into the working tree *while the hook's suite was running*. Pest promotes warnings to errors, so
every test that boots migrations errored. The 313th, `FinanceNavCoverageTest`, failed because commit
3's route was in the tree before its exemption was. **`bin/quality` gates the WORKING TREE, not the
commit**, and nothing in it can defend against the author typing during the run. The artefacts are
kept at `scratchpad/run1/` (junit 2.0 MB, suite log 124 KB, gate stdout); the re-run on a stashed
tree passed 16/16. No conclusion in this report rests on that first run.

---

## 1. What U10 is, as built

For a payment carrying an unallocated remainder: a surface that shows the engine's proposed split
across that student's open invoices, lets an operator change it, and on submit writes the allocation
rows. Entry is the student statement's payments tab.

```
GET  /api/v1/finance/payments/{payment:uuid}/allocation-proposal   finance.payment.allocate
POST /api/v1/finance/payments/{payment:uuid}/allocations           finance.payment.allocate
GET  /finance/payments/{payment:uuid}/allocate                     finance.payment.allocate
```

**The proposal is oldest-invoice-first**, capped at each invoice's outstanding and at the payment's
remainder. Not a fresh choice: ADR 0048 D2 made `GenerateInvoice::applyCreditForward`'s oldest-first
the single settlement order in the system by deleting the newest-first workaround that competed with
it. This screen proposes what that engine would do if the next generation drew the remainder
forward, so an operator who closes the tab gets the same answer.

---

## 2. The four constraints, each answered

### 2.1 The action takes the account row lock

`AllocatePayment::handle` opens its transaction with `StudentAccount … lockForUpdate()->first()` as
the **unconditional first statement**, on the same row `GenerateInvoice` takes. A null account row
refuses rather than proceeding — running the rest unserialised is exactly the residual being closed.

The ticket
[`nothing-constrains-allocations-to-a-payments-amount.md`](../tickets/nothing-constrains-allocations-to-a-payments-amount.md)
closed naming this writer:

> a future writer that allocates against a payment without joining the account-row lock — a job, a
> bulk correction, a second path — would race, and this trigger would not catch it.

**PROOF B, `tests/Feature/Finance/AllocatePaymentConcurrencyTest.php`** — two real connections, a
deterministic interleave, `DatabaseTruncation` so the second connection can see the first's writes:

1. a competitor holds the student-account row `FOR UPDATE` and has inserted the payment's whole
   10000 against invoice 1, **uncommitted**;
2. this connection's plain `SUM(amount_minor)` still reads **0** — asserted, because that is the
   danger and it is also exactly what the payment-axis trigger's own `SELECT SUM` can see;
3. the real Action then blocks on the account row and fails **1205** (lock wait timeout), writing
   nothing;
4. after both, Σ for the payment is **10000**, not 20000.

**The mutation, run:**

```
### remove StudentAccount ... lockForUpdate() from AllocatePayment
MUTATION APPLIED: lockForUpdate() removed
{"result":"failed","tests":3,"passed":2,"errors":1,"error_details":[{
  "test":"…PROOF_B…",
  "message":"AllocatePayment allocated 10000 against a payment whose entire 10000 was already
   committed-in-flight by another writer. The account-row lock is not being taken, and the
   payment-axis trigger cannot see across transactions — Σ would end at 20000."}]}

### restored
{"result":"passed","tests":3,"passed":3,"assertions":9}
```

PROOF C is the other half: once the competitor **commits**, the loser gets the lock uncontended, and
because the lock is the transaction's first statement its REPEATABLE READ snapshot forms at the
proposal's read *afterwards* — a current read of committed state. It sees a payment with nothing left
and refuses. PROOF A reads `@@session.transaction_isolation` off the server (`REPEATABLE-READ`, 8.0.x)
rather than assuming it.

### 2.2 Editable means before commit — and the screen says so

`finance_payment_allocations` carries `_no_update` and `_no_delete` (2026_07_19_110000). Editing
happens on the proposal; after submit a correction is a compensating write.

The sentence sits beside the submit button, read back off the rendered page by the drive:

> **Once submitted, these allocations cannot be edited or removed.** The allocation record is
> permanent. Correcting a mistake afterwards means raising a further document — a credit note or a
> new invoice — not changing these rows. Check the split before you submit.

It is beside the button and not in a tooltip because the operator has just spent two minutes editing
a **table**, which is the single strongest cue that it can be edited again.

### 2.3 A new rule constant

`PaymentAllocation::RULE_OPERATOR_DIRECTED_REMAINDER = 'operator_directed_remainder'`, named in the
voice of the two that exist — each says what its writer *does*. Its docblock names the writer
(`AllocatePayment`) and states that the amounts are the operator's.

It arrived **with** its writer rather than ahead of it. The model's header said "exactly two" for as
long as there were two, and `2026_08_09_120000` named the gap it was leaving — *"the third rule whose
screen (U10) has not been built"* — instead of reserving a constant. Accepting the proposal and
changing it are **one act**, told apart by `allocation_overridden`, not by a fourth rule name.

### 2.4 The action refuses before the database does — and the database still refuses

Every operator-trippable refusal is answered by the Action with a **field key**
(`App\Finance\Exceptions\AllocationRefused`), rendered by the controller in Laravel's own `errors`
shape, so a message lands on the row it is about. A 1644 as a 500 is not a usable refusal; an unkeyed
422 over a table of eight editable amounts is barely better.

The database is untouched as the floor. **PROOF 12** inserts seven rows raw — no Action in the
picture — and asserts each refusal by its own **message**, not by SQLSTATE 45000 (roughly fifty
triggers here signal 45000):

| raw row | refused with |
| --- | --- |
| operator rule, `allocated_by_user_id` NULL | `allocated_by_user_id is required for that rule` |
| engine rule, actor NOT NULL | `the two engine rules must leave allocated_by_user_id null` |
| `overridden = 1`, reason NULL | `allocation_override_reason is required` |
| `overridden = 1`, reason `'   '` | same — a blank reason is the same audit hole |
| `overridden = 0`, reason present | same, other direction |
| engine rule with `overridden = 1` | `Only an operator-directed allocation may be overridden` |
| `amount_minor` 5001 on a 5000 payment | `Allocation would exceed the payment amount` |

---

## 3. The migration this needed, and the defect its audit caught

`2026_08_22_100000_finance_allocation_provenance_pairing` adds
`finance_payment_allocations.allocated_by_user_id` and the trigger
`finance_allocation_provenance_pairing_bi`.

**Why the column.** `2026_07_26_120000_add_created_by_to_finance_invoice_lines` closed the same hole
one table over, and its sentence is this one's: *"Every other Finance document names its human …
The one gap was the discretionary reduction a bursar applies by hand."* Until U10 both writers of this
table were engines with no human to name. The table is append-only, so a column added after the first
operator-directed row leaves that row silent about its author forever.

**Nullable, and a LOOKUP not an FK** — the convention of `received_by_user_id`,
`created_by_user_id`, `cancelled_by_user_id`: an attribution must never block a user's lifecycle.
NULL here **carries information**: it means no human directed the row, and arm 2 of the trigger is
what makes that true rather than trusted.

**A trigger, not a CHECK**, because production is MySQL 5.7.23, which parses and ignores CHECK — the
same reason `2026_08_17_100000` rewrote two live CHECKs on `finance_payments` as triggers.

### The four-path `down()` audit found a real defect

The index was first written `(school_id, allocated_by_user_id)`, mirroring `finance_invoice_lines`.
`finance_payment_allocations` carries a **single-column** foreign key on `school_id` alone, and
InnoDB backs it with an index of the same name. Measured by listing `SHOW INDEX` with and without the
migration:

```
WITHOUT the migration                              WITH it (leading school_id)
  fee_payment_allocations_school_id_foreign          (absent)
    seq=1 col=school_id                              finance_alloc_..._index
                                                       seq=1 col=school_id
                                                       seq=2 col=allocated_by_user_id
```

Two consequences. The migration was silently changing which index backs a foreign key it has nothing
to do with. And `down()` could never drop its own index — MySQL **1553**, *"Cannot drop index … needed
in a foreign key constraint"* — so the rollback failed **half-done**: trigger dropped, column still
present, migration still recorded as run, and `migrate` then reported *"Nothing to migrate"* over a
database missing its guard.

Reordered to `(allocated_by_user_id, school_id)`, which cannot prefix that foreign key. Re-audited,
**asserting per object rather than on a bare exit-0**, with the rollback depth re-derived from
`migrate:status` (this migration is its last row, so `--step=1` is one, checked and not assumed):

```
PATH 1  after migrate:fresh   column=PRESENT my_trigger=PRESENT sibling_trigger=PRESENT my_index=PRESENT preexisting_fk_index=PRESENT
PATH 2  migrate:rollback --step=1 … finance_allocation_provenance_pairing .. 46.14ms DONE
PATH 3  after rollback        column=ABSENT  my_trigger=ABSENT  sibling_trigger=PRESENT my_index=ABSENT  preexisting_fk_index=PRESENT
PATH 4  migrate (re-up)       column=PRESENT my_trigger=PRESENT sibling_trigger=PRESENT my_index=PRESENT preexisting_fk_index=PRESENT
```

The sibling trigger (`finance_allocation_not_over_payment_amount`) staying PRESENT across path 3 is
what shows the rollback reverted **mine** and not the other stream's — the `--step=N` trap CLAUDE.md
names, checked rather than trusted.

---

## 4. The bank-account mismatch — what the brief assumed, what is there, what I built

**The brief's premise is false, and the correction is the design.** `finance_invoice_lines` has no
`bank_account_id`. The only destination anywhere is `finance_fee_items.bank_account_id` (NOT NULL),
reachable from a line only through `fee_item_id` — which is **nullable LOOKUP provenance with no
foreign key**, pointing at a **mutable** row on a schedule that can be superseded.

So the mismatch is **derived, and it is three-valued**:

| state | means |
| --- | --- |
| `matches` | every readable destination on this invoice equals the account the money landed in |
| `differs` | at least one readable destination does not — the cut brief's ordinary term-one case |
| `unrecorded` | no charge line on this invoice resolves to a fee item with an account |

`unrecorded` is **not** `matches`. That distinction is the whole point: today every line the "New
invoice" modal writes is free text with no fee item, so an unknown destination is the *common* case,
and rendering it as agreement would be "silently allocate across it" one level more subtle than the
brief's version. `charge_lines_without_destination` rides beside the state, so a `matches` covering
two of five lines says so instead of showing a bare tick.

**PROOF 4** pins all three (`['matches','differs','unrecorded']`) and reds when `unrecorded` is folded
into `matches`. All three were also **rendered** — § 6.

What this cannot answer: where a charge was destined **at the time it was billed**. It answers where
it would go if billed from today's catalog. When S11's snapshot lands, this derivation is replaced by
reading the line's own column.

---

## 5. The permission, and the no-maker-checker decision

`finance.payment.allocate`, granted to **`accounts_officer` alone**.

ADR 0048 D1 is the precedent and it agrees with the brief's ruling: `finance.payment.record` is held
by AO only, so "takes the money in" separates from "approves the write-off". Directing money that has
already arrived is the same operator's job. Coined as its own case rather than folded into
`.record`, so a school that later wants them on different seats moves one without the other.

Checked rather than assumed: `accounts_officer` is **not** governed by the forcing convergence
migration `2026_08_06_100000_move_head_of_school_finance_to_executive_director` (its docblock names
the three roles it freezes), so the grant lands via `rbac:sync` and is not revoked on the next deploy
— obligation 3 of `Permission`'s own checklist. The grants-convergence lint's exemption 1 applies
(the enum case is in the same diff) and the lint passed on every push.

**No maker-checker, and it is a decision.** All four actions behind `ApprovalRequirement` reduce a
receivable. An allocation reduces nothing: the ledger is untouched, the balance is identical before
and after, and only *which* invoice a payment settles changes. A second person for a write that
cannot change what is owed spends the checker's attention where nothing is at stake — and an approval
that is routine is one that gets rubber-stamped, which is how the four that matter lose their force.
Proportionate instead, and all three built: the row names the operator, a departure carries a marker
and a required reason, the table is append-only.

**What would change it**, written into the Action's docblock: an allocation that could reduce a
receivable (un-allocation, re-allocation — both out of scope, neither built), or a school asking for
it. Brookstone's stated approval list is scholarships, discounts, concessions, refunds, write-offs —
every item a reduction.

---

## 6. The drive

`APP_ENV=drive`, `portal_drive`, port 8001, Playwright-driven system Chrome with `playwright-core`
installed **outside the repository**. Captures in
[`docs/handoff/drives/2026-08-22-u10-allocation/`](../drives/2026-08-22-u10-allocation/).

### 6.1 The fixture needed a state it did not have — so the fixture changed

No prior state produces a payment with a remainder. **Every** payment in the fixture was recorded
*against* an invoice and capped at its outstanding, so its remainder was zero;
`settledThenCredited` leaves the **account** in credit, which lives on the balance, not on an
unallocated payment. The allocation screen would have opened on nothing while `Payments (portal)`
read 3 — the exact shape the finance-drive skill records twice already, and it is in scope as a
precondition of the drive (U1 commit 1's precedent).

Added: `DriveFinanceStates::ensureSecondBankAccount` (School A only — with one account per school the
mismatch is *unreachable*), `unallocatedRemainder`, and two count columns with their readers. Two
students, Alma and Arun: identical shape, and the money lands in the **second** account for one and
the **first** for the other. That is the whole mismatch axis, and it is why one drive sees all three
destination states.

Order is load-bearing in that state: invoices **first**, payment second, because
`applyCreditForward` draws every earlier remainder into each new invoice as it is raised.

### 6.2 Both count tables, verbatim from a fresh seed

```
Authoring slot per school — … the bulk-run screen (U6) prices a COHORT from an ACTIVE schedule and reports the unplaceable:
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+-----------------------+---------------+------------------+----------------+-------------+
| School       | Academic sessions | Terms | Class levels | Bank accounts | Discount policies | Payments (portal) | Payments (migrated) | Payments w/ remainder | Open invoices | Active schedules | Cohort at slot | Unplaceable |
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+-----------------------+---------------+------------------+----------------+-------------+
| A (school#1) | 2                 | 2     | 2            | 2             | 1                 | 5                 | 0                   | 2                     | 8             | 1                | 2              | 9           |
| B (school#2) | 2                 | 2     | 2            | 1             | 1                 | 0                 | 0                   | 0                     | 1             | 1                | 2              | 1           |
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+-----------------------+---------------+------------------+----------------+-------------+
Bulk invoice runs: /finance/bulk-invoice-runs — the cohort above sits at (term, JSS 1); JSS 2 has an empty one on purpose.

Authoring slot per school — … the guardians screen links a new guardian to students by admission number:
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+-----------------------+---------------+----------+-----------+
| School       | Academic sessions | Terms | Class levels | Bank accounts | Discount policies | Payments (portal) | Payments (migrated) | Payments w/ remainder | Open invoices | Students | Guardians |
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+-----------------------+---------------+----------+-----------+
| A (school#1) | 2                 | 2     | 2            | 2             | 1                 | 5                 | 0                   | 2                     | 8             | 12       | 0         |
| B (school#2) | 2                 | 2     | 2            | 1             | 1                 | 0                 | 0                   | 0                     | 1             | 3        | 0         |
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+-----------------------+---------------+----------+-----------+
```

School A's `Bank accounts` is **2** where every earlier drive saw 1 — that is this commit's second
account. School B's `Payments w/ remainder` is **0**, and that is correct rather than a gap: the
isolation seat has nothing to allocate, which is what capture 08 then shows from the other side.

### 6.3 What was on screen, and what each observation establishes

**Capture 01 — `01-statement-payments-before.png`.** The statement's payments tab before anything is
allocated. The row, read out of the DOM:

```
["Guardian at the window","#4","Manual","22/08/2026","₦2,000.00","₦2,000.00 Allocate","Receipt"]
```

*Establishes:* the Unallocated column renders the figure **and** the Allocate link beside it, and the
link is on the row rather than in a menu. `₦2,000.00` of `₦2,000.00` — an account-scoped payment
allocates nothing at record time.

**Captures 02 — `02-proposal-mismatch.png`, `02-proposal-match.png`.** The proposal, pristine.

```
[mismatch] /finance/payments/a29000a4-0dd4-…/allocate
  FACTS  ["AMOUNT ₦2,000.00","RECEIVED 22 August 2026","METHOD Manual","REFERENCE #4",
          "LANDED IN Drive Trips Account Drive Bank","UNALLOCATED ₦2,000.00"]
  ROW    ["000009 …","Term Bill","Drive account Not the account this money landed in.","₦1,500.00","₦1,500.00"]  input="1500.00"
  ROW    ["000010 …","Extra","Not recorded These lines name no bank account, so there is nothing to compare against.","₦1,000.00","₦500.00"]  input="500.00"
  FOOTER ["Allocating ₦2,000.00 of ₦2,000.00","Still unallocated: ₦0.00"]

[match] /finance/payments/a29000a4-1651-…/allocate
  FACTS  [… "LANDED IN Drive Account Drive Bank","UNALLOCATED ₦2,000.00"]
  ROW    ["000011 …","Term Bill","Same account","₦1,500.00","₦1,500.00"]  input="1500.00"
  ROW    ["000012 …","Extra","Not recorded …","₦1,000.00","₦500.00"]  input="500.00"
```

*Establishes*, in order:

1. **All five things the brief requires of the payment header are rendered** — amount, received date,
   method, reference, bank account — plus the remainder.
2. **The mismatch is visible and it NAMES the account.** Payment #4 landed in *Drive Trips Account*;
   invoice 000009's charge is destined for *Drive account*; the row says so in words an operator can
   act on. Payment #5 landed in *Drive Account* and its term bill reads *Same account*. Same fixture,
   same shape, one difference — which is what makes this a demonstration rather than a screenshot.
3. **`unrecorded` renders as its own thing.** Both supplementary invoices say *"These lines name no
   bank account, so there is nothing to compare against"* — not a tick, not a warning.
4. **`kind` is on screen**: *Term Bill* against *Extra*, so an operator directing money can tell which
   of several open bills is the term bill (#269's wire).
5. **The proposal is oldest-first and capped**: 000009 (older, owes 1,500) takes 1,500 in full;
   000010 (owes 1,000) takes the remaining 500. `1500 + 500 = 2000`, rendered
   `Allocating ₦2,000.00 of ₦2,000.00`, `Still unallocated: ₦0.00`. Nothing in the page computed the
   1,500 or the 500 — both came off the wire; the page summed two integers it was given.
6. **The finality sentence is present on the page**, quoted in § 2.2, read from the DOM.

**Capture 03 — `03-departure-reason-required.png`.** 1,000 / 1,000 typed over the 1,500 / 500 proposal.

```
SUBMIT (no reason)  {"disabled":true}
ROW ["000009 …","Term Bill","Drive account Not the account this money landed in.","₦1,500.00","₦1,500.00","Changed"]
ROW ["000010 …","Extra","Not recorded …","₦1,000.00","₦500.00","Changed"]
```

*Establishes:* both departing rows are marked **Changed** and tinted, and the submit is **disabled**
with no reason given. The marker is per row and computed against the proposal, not against zero.

**Capture 04 — `04-departure-reason-given.png`.** The reason typed.

```
SUBMIT (with reason) {"disabled":false}
```

*Establishes:* the required reason is what unblocks it, and the field carries the sentence saying it
will be written onto the rows and cannot be edited.

**Captures 05 / 06 — `05-statement-payments-after.png`, `06-statement-invoices-after.png`.** Submitted;
the page redirects to the statement.

```
payments  ["Guardian at the window","#4","Manual","22/08/2026","₦2,000.00","₦0.00","Receipt"]
          links=["/finance/payments/a29000a4-0dd4-…/receipt"]
invoices  ["000009","…","Issued Part-paid","₦1,500.00 ₦500.00 outstanding","Record payment  Submit credit note  Request void"]
          ["000010","…","Issued Settled","₦1,000.00","Submit credit note  Request void"]
```

*Establishes:* the remainder is now `₦0.00` and **the Allocate link is gone** while the Receipt link
remains — the server's flag flipped and the UI followed it without re-deriving anything. And the
invoices settled to **the operator's split, not the proposal's**: 000009 part-paid with
`1500 − 1000 = 500` outstanding, 000010 settled at its full 1,000. The proposal would have left
000009 settled and 000010 part-paid. The screen changed the outcome, which is what it is for.

**Capture 07 — `07-refusal-over-invoice-outstanding.png`.** 2,000 onto an invoice owing 1,500, with 0
on the other — a total the **client** finds legal (2,000 ≤ 2,000 headroom) so the submit is enabled
and the request is actually made.

```
FOOTER ["Allocating ₦2,000.00 of ₦2,000.00","Still unallocated: ₦0.00"]   (the client sees a legal total)
SUBMIT {"disabled":false}
ROW AFTER ["000011 …","Term Bill","Same account","₦1,500.00","₦1,500.00",
           "That is more than invoice 000011 still owes (NGN 1500.00)."]
```

*Establishes* the load-bearing half of constraint 4: a refusal the client could not have known about
came back from the Action **under the lock**, named its invoice, and rendered **on that row** rather
than in a banner. Nothing was written — confirmed in § 6.4.

**Capture 08 — `08-isolation-school-b-refused.png`.**

```
school-b GET /finance/payments/a29000a4-0dd4-…/allocate -> 404
school-b GET /finance/payments/a29000a4-1651-…/allocate -> 404
school-b GET /api/v1/finance/payments/<A's payment>/allocation-proposal -> 404
```

*Establishes:* isolation is by **id**, not by label. School B's bursar holds `finance.payment.allocate`
and is still refused on School A's payment uuids, at the route-model binding, on both the page and
the API. ADR 0036: authorization is bypassable, isolation is not.

**Capture 09 — `09-gating-admin-refused.png`.**

```
admin GET /finance/payments/<A's payment>/allocate -> 403
admin GET .../allocation-proposal -> 403
```

*Establishes:* `admin@drive.test` holds `finance.access` — enough for the statement beside it — and is
refused on both allocation surfaces. The narrower gate is real, not decorative.

### 6.4 What the drive actually wrote, read back from the database

```
alloc#1 payment#1 invoice#2  100000 rule=payment_against_named_invoice  overridden=0 actor=NULL reason=NULL
alloc#2 payment#2 invoice#3  300000 rule=payment_against_named_invoice  overridden=0 actor=NULL reason=NULL
alloc#3 payment#3 invoice#5  300000 rule=payment_against_named_invoice  overridden=0 actor=NULL reason=NULL
alloc#4 payment#4 invoice#9  100000 rule=operator_directed_remainder    overridden=1 actor=2 reason='Parent asked for the trip to be cleared before the term bill.'
alloc#5 payment#4 invoice#10 100000 rule=operator_directed_remainder    overridden=1 actor=2 reason='Parent asked for the trip to be cleared before the term bill.'
```

Three things at once, from one reading. The three **engine** rows carry `actor=NULL` — arm 2 of the
pairing trigger, which is what keeps NULL meaning "no human chose this" rather than "nobody recorded
one". The two **operator** rows name `user#2` and carry the marker and the reason. And **payment#5 has
no row at all**: the refusal in capture 07 wrote nothing, on a table where a partial write would be
permanent.

---

## 7. Every proof, and the mutation that caught it

Bite-proven means: run green, plant the regression, watch it red, restore, run green.

### Read side — `AllocationProposalTest` (7 arms)

| # | Arm | Mutation | Result |
| --- | --- | --- | --- |
| 1 | oldest-invoice-first, capped at outstanding | `orderBy('id')` → `orderByDesc` | RED |
| 2 | states what it could NOT place | `unproposed_remainder` → 0 | RED |
| 3 | settled invoices and prior allocations excluded | drop the `outstanding > 0` filter | RED |
| 4 | destination is three-valued | fold `unrecorded` into `matches` | RED |
| 5 | cross-currency listed and blocked | drop the currency block | RED |
| 6 | gated on `finance.payment.allocate` | drop the route middleware | RED |
| 7 | another School's payment 404s at the binding | — |

PROOF 5's USD invoice is inserted **raw**, and that is not a shortcut around a guard: `SubledgerPoster`
refuses a charge in a currency other than the account balance's — *"Ledger currency NGN does not match
account balance currency USD for student 5"*, hit while writing this arm — so no sequence of real
Actions can put a USD invoice and an NGN payment on one student. The row is still reachable from a
restored dump, and the read model must not propose an allocation the trigger will refuse.

### Write side — `AllocatePaymentTest` (12) + `AllocatePaymentConcurrencyTest` (3)

| Mutation | Reds |
| --- | --- |
| remove `StudentAccount … lockForUpdate()` | PROOF B (Σ would be 20000) |
| trigger arm 1 → `IF FALSE` (actor optional) | PROOF 12 |
| trigger arm 3 → `IF FALSE` (marker/reason unpaired) | PROOF 12 |
| remove the fingerprint check | PROOF 8 |
| marker per-submission instead of per-row | PROOFS 3, 4 |
| departure computed over submitted rows only | PROOF 4 |

**One of these was a false green first time and is recorded rather than quietly redone.** The first
attempt at the arm-3 mutation used a `perl -0pi` substitution that did not match; `grep -c` returned
**0** and the suite came back green. A green after a mutation that never applied is indistinguishable
from a guard that does not work. It was re-run with an exact line replacement, printed
`MUTATION M-b APPLIED`, and then went red on the right message. Every mutation in these tables was
verified to have landed before its result was believed.

### The screen — `FinanceNavCoverageTest`

| Mutation | Reds |
| --- | --- |
| remove the Allocate link from the statement | the new exemption arm |
| gate the link on `unallocated.amount_minor > 0` instead of the server flag | the new exemption arm |
| remove the `FNC_NOT_NAV` entry | "every finance page is reachable" |

**The `can_allocate`-appears-exactly-once arm caught this commit.** The identifier was in the
explaining comment as well as the condition. That count exists because on the sibling receipt arm every
second mention has turned out to be a HIDE. The comment was reworded; the count was not relaxed.

### Suites

```
tests/Feature/Finance                     715 / 715
tests/Feature/Rbac                        335 / 335
--group=arch                              103 / 103
phpstan (Larastan level 5)                0 errors
```

### The gate, per push

```
commit 1  3751643   ✓ quality: PASS   16/16
commit 2  9ee3ece   ✓ quality: PASS   16/16
commit 3  661f217   ✓ quality: PASS   16/16
```

Each read back from `origin` afterwards: `git diff --stat HEAD origin/feat/u10-allocation-screen`
empty at each step, and the new files present in `git ls-tree` of the remote ref.

---

## 8. What I could not verify

**MySQL 5.7.** The pairing trigger is written as a trigger *because* production is 5.7.23 and ignores
CHECK, and no 5.7 server was available here. Everything above is 8.0.43. The trigger uses no 8.0-only
syntax, but that is an argument, not a measurement.

**PHP versions other than 8.3.32.** The permanent residual named in CLAUDE.md.

**The screen under a real operator.** The drive is a script clicking a headless browser. It proves the
values, the refusals, the gating and the isolation; it proves nothing about whether the layout is
usable at a counter, whether the reason field is where a bursar looks for it, or whether "Extra" is
the word Brookstone uses for a supplementary invoice.

**Concurrency beyond two connections.** PROOF B is a deterministic two-connection interleave. Three
or more writers, and the behaviour under real contention rather than a forced 1-second lock timeout,
are not measured.

**A deploy pre-flight for the new trigger.** A `BEFORE INSERT` trigger does not inspect existing rows
and will deploy cleanly over a violating one. `finance_payment_allocations` was empty when
`2026_08_09_120000` ran, so no row can violate the new pairing today — but that is an inference from a
prior migration's precondition, not a count taken against production. The sibling's report owes the
same pre-flight and this one joins it.

**The `unrecorded` state under a real fee catalog.** Today almost every line is free text, so
`unrecorded` is the common case by construction. Once U1's catalog feeds invoice lines, the balance of
the three states changes completely and this screen's mismatch column becomes load-bearing in a way
the drive could not exercise.

**Whether the drive's fixture states are the right ones.** Alma and Arun differ only in which account
their money landed in. That was enough to render all three destination states; it does not exercise an
invoice whose lines resolve to **two different** accounts, which the read model handles
(`accounts` is a list) and nothing rendered.

---

## 9. Cold review — what it found, and what changed

A cold review of this branch returned **one stop, two fixes and three tickets**. The severities below
are the reviewer's, carried as set. Everything in this section landed in one commit,
`fix(finance): a numeric string wrote an override nobody made, and the invoice axis was never the
trigger's to guarantee`.

### STOP 1 — a JSON string wrote a false override marker onto an append-only row

`AllocatePaymentRequest` validated `amount_minor` with `integer`, which is
`filter_var($value, FILTER_VALIDATE_INT) !== false`
(`Illuminate\Validation\Concerns\ValidatesAttributes::validateInteger` — read, not assumed: the
`:strict` branch is `is_int($value)` and the default branch is the filter). The numeric **string**
`"3000"` passed, nothing cast it, and `AllocatePayment` decides the override marker with `!==`. So
`"3000" !== 3000` was **true**, and a submission byte-identical to the proposal was recorded as an
override the operator never made — with a reason they were compelled to invent — on a table carrying
`_no_update` and `_no_delete`.

**Reproduced against the live route before anything was changed**, all four cases, raw:

```
CASE 3  string "3000", no reason   -> 422 {"override_reason":["You changed the proposed split, so a reason
                                     is required. It is written onto the allocation rows and cannot be
                                     edited afterwards."]}   rows: NO ROWS
CASE 2  string "3000", with reason -> 201  rows: invoice#1 3000 overridden=true
                                     reason='A reason nobody should have been asked for.'
CASE 1  int 3000, no reason        -> 201  rows: invoice#2 3000 overridden=false reason=NULL
CASE 4  proposal as computed: 000003=>2000, 000004=>0
CASE 4a proposal verbatim, "0" str, no reason -> 422 {"override_reason":["You changed the proposed split…"]}
CASE 4b same, with the reason it demanded     -> 201  rows: invoice#3 2000 overridden=false reason=NULL
```

Case 4 is the quietest and the reviewer was right to single it out. The submission **is** the
proposal — 2000 on the row proposed 2000, zero on the row proposed zero — with the zero sent as a
string. A reason was demanded, and then written **nowhere**: the zero row writes no allocation at all,
and the row that does write is not a departure, so `allocation_override_reason` is NULL. The reason
was extracted from the operator and discarded, which is precisely what `AllocatePayment` says in its
own words, at the guard immediately below, that it refuses to do.

**Fixed in both places, and neither is sufficient alone.** `'integer:strict'` in the FormRequest
shuts the HTTP door; an explicit `(int)` cast in the Action's loop covers the callers the FormRequest
cannot see, because that Action's docblock states it is reachable off-HTTP and a job or console
caller handing it `['amount_minor' => '3000']` meets no FormRequest anywhere in the path.

Four new arms — PROOF 13 (a–d, over the real route) and PROOF 14 (the Action called directly). The
mutations, each verified to have landed before its result was believed:

```
### M1 — revert BOTH halves
  result failed 21 / 23
   RED: PROOF_13 …  Failed to find a validation error for key 'allocations.0.amount_minor' |
        Response has the following JSON validation errors: { "override_reason": [ …
   RED: PROOF_14 …  You changed the proposed split, so a reason is required.

### M2 — revert ONLY the request rule; the Action's cast stays
  result failed 22 / 23
   RED: PROOF_13

### M3 — revert ONLY the Action's cast; the request rule stays
  MUTATION LANDED
  result failed 14 / 15
   RED: PROOF_14 …  You changed the proposed split, so a reason is required.

### restored
  result passed 23 / 23
```

M1's red is worth reading rather than counting: the response carries `override_reason` where the arm
expected an amount error — the defect itself, printed by the arm that now catches it. M2 and M3
together are the argument for fixing both places: each guard reds an arm the other keeps green.

**M3 was a false green on its first attempt and is recorded rather than quietly redone.** The
substitution was passed through `python3 -c "…"` in a double-quoted shell string; bash left `\$`
intact, Python warned `SyntaxWarning: invalid escape sequence '\$'`, the pattern never matched, and
the suite came back 23/23. A green after a mutation that did not land is indistinguishable from a
guard that works. Re-run with a heredoc and an assertion on the pattern, it printed `MUTATION LANDED`
and then went red. This is the second time on this branch that a mutation harness lied; both are in
the record.

### FIX 2 — the page's permission gate was asserted nowhere

Removing `->middleware('permission:finance.payment.allocate')` from the **web** route left
`tests/Feature/Finance` 715/715, `tests/Feature/Rbac` 335/335 and `--group=arch` 103/103 all green.
PROOF 11 covers the two API doors; `FinanceNavCoverageTest`'s arm is a text check on `statement.tsx`
that issues no request. § 7 of this report enumerates gate arms and did not notice the page had none
— an omission in this report's own frame.

PROOF 15 adds it: a `finance.access`-only seat gets **403**, the officer gets **200**.

```
### M4 — remove the PAGE route middleware
  result failed 22 / 23
   RED: PROOF_15_—_the_PAGE_route_is_gated_too__and_not_only_the_two_API_routes
```

### FIX 3 — the Action claimed an authority the trigger does not have

`AllocatePayment` said `finance_allocation_not_over_invoice_total` "is the authority and stays
reachable for any writer that does not come through here". Measured false, the same way the
payment-axis ticket demolished the identical claim for this trigger's sibling: `RecordPayment` locks
the **invoice** row, while `applyCreditForward` and `AllocatePayment` lock the **account** row and
never touch the invoice row. Disjoint locks, and the trigger's `SELECT SUM` cannot see across
transactions. **The reviewer measured Σ = 20000 against a 10000 invoice.**

The sentence now says what is true — the trigger refuses what a single transaction can see, and the
invoice axis is not serialised across writers — and points at TICKET 4. The axis is deliberately not
fixed here.

### FIX 7 — a two-account invoice named the matching account as a mismatch

`state` is `differs` as soon as one resolved destination disagrees, and the cell rendered the **whole**
of `accounts` under "Not the account this money landed in." An invoice resolving to two accounts, one
of them the payment's, therefore listed the matching account under a sentence that is false of it.
§ 8 of this report recorded two-account invoices as handled by the read model and never rendered;
this is what would have been seen.

`differing_accounts` is now on the wire as a subset of `accounts`, and the screen lists only that
under the sentence, with a second line saying the remaining lines are destined for the account the
money is in. Narrowing `accounts` instead would have hidden the agreeing half, which is part of the
operator's picture of where the invoice's money was meant to go.

```
### M5 — render the FULL account list as the mismatch (the pre-fix rendering)
  result failed 22 / 23
   RED: PROOF_4b_—_an_invoice_resolving_to_TWO_accounts_names_only_the_DIFFERING
```

### Tickets 4, 5 and 6

- [`the-invoice-axis-is-not-serialised-across-writers.md`](../tickets/the-invoice-axis-is-not-serialised-across-writers.md)
  — the measurement, the three writers and which row each locks, and that the disjoint pair
  (`RecordPayment` × `applyCreditForward`) **pre-dates this branch**, which adds a third writer on an
  axis that was already uncovered. It also records the falsified prediction: the reviewer looked for
  FK-check contention on the invoice row that would have made the "account row and no other row lock"
  claim false, and found none — so that claim stands as measured rather than reasoned.
- [`server-side-money-has-no-single-formatter-and-no-lint.md`](../tickets/server-side-money-has-no-single-formatter-and-no-lint.md)
  — `NGN 1500.00` beside `₦1,500.00`, `Money::toNaira()` emitting no thousands separator so a real
  term bill reads `NGN 125000.00`, `SubmitCreditNote:92` using the identical notation so patching one
  site creates a third, and `bin/ci-money-lint.php` walking `resources/js` only with no server-side
  arm — so no baseline hides this; the lint never looked. **Not patched**, deliberately.
- [`the-proposal-fingerprint-does-not-cover-destination.md`](../tickets/the-proposal-fingerprint-does-not-cover-destination.md)
  — a fee item's `bank_account_id` is mutable and is not in the token, so an operator can submit
  against a stale `Same account` reading with no reload prompt. **No legality consequence** — the
  destination decides nothing and every rule that governs the write is re-derived under the lock —
  **and** it is the one figure on the screen the token does not defend. Both halves are in the ticket.

### What the reviewer verified that this report had not claimed

Two findings in the branch's favour, recorded because a review that only subtracts is not being read
properly:

- **The engines cannot be stamped with the operator rule.** `RecordPayment` and
  `applyCreditForward` both pass `allocated_by_user_id` as NULL, so an attempt to write either of them
  under `operator_directed_remainder` trips **arm 1** of the pairing trigger. The report argued arm 2
  (an engine row with an actor); the reverse direction is closed too, and by a different arm.
- **The two trigger arms this report does not list are armed.** § 2.4's table names all seven raw-write
  refusals, but § 7's mutation table listed mutations for arms 1 and 3 only. The reviewer mutated the
  **actor-forbidden** and **override-rule** arms as well and both went red.

### A pre-existing flake this work ran into, with its mechanism

Not a cold-review finding and not this branch's — recorded because it went red once during
verification and the next reader deserves the diagnosis rather than a retry.

`tests/Feature/Finance/SubledgerClockFrameTest.php` failed inside a full `tests/Feature/Finance` run:

```
finance suite: failed 718 / 719
  RED: it_lands_the_ledger_row_and_the_account_projection_in_ONE_CLOCK_FRAME…
       Failed asserting that 91 is identical to 90.
```

Neither that file nor `SubledgerPoster` is touched by any commit on this branch
(`git log origin/staging..HEAD --` on both paths is empty), and it is not in
`tests/ratchet-baseline.txt`. Re-run alone three times at 13:24:30, 13:24:46 and 13:25:03 it passed
2/2 every time.

**The mechanism, and it contradicts the test's own docblock.** The arm does
`test()->travel(90)->seconds()` between two posts and then asserts
`strtotime($secondPostedAt) - strtotime($postedAt) === 90`. Its comment says the difference "is
IMPOSED by the test clock, not raced for, so it is deterministic on any machine." That is false as
written: `travel()` anchors to `Carbon::now()` **at the moment it is called**, not to the first post's
instant, so the observed gap is `(t_travel + 90) − t_firstPost`. Any wall-clock second boundary
crossed between the first post and the `travel()` call yields **91**. The window is sub-second, which
is why it survives in isolation and lost once under the load of a 719-test run.

It is a real intermittent in a test that asserts it cannot be one — the same shape as a rule with no
enforcement behind it. Left alone here: it belongs to `SubledgerPoster`'s stream, not to U10, and a
drive-by fix to someone else's timing arm is how a green gets bought rather than earned.

### The reviewer's limits, in its words

Carried because a review's blind spots belong beside its findings:

- **No `PROCESS` privilege**, so its `information_schema.processlist` check could see only its own
  threads. That is a defect in the credential grant available to the review, not in the review.
- **MySQL 5.7 unavailable** — every measurement is 8.0.43, as with the original branch. The pairing
  trigger is written as a trigger *because* production is 5.7.23, and that remains unexercised.
- **The scratchpad artefacts are unverifiable by design** — the first gate run's junit and suite log
  live outside the repository and the review could not confirm they are what § 0 says they are.
- **The drive captures were not re-derived.** The review read the report's transcript of them; it did
  not re-run the drive.
- **True concurrency beyond deterministic two-connection interleaves is unmeasured**, on either axis.

## 9. Not built, and ticketed if you want them

**Out of scope by the brief, and untouched:** reallocation or un-allocation of existing rows; bulk
allocation across students; any change to `RecordPayment` or `GenerateInvoice` — their caps and their
locks are exactly as they were.

**Found while building, not fixed:**

- The Action's over-allocation message renders money as `NGN 1500.00` while the screen renders
  `₦1,500.00`. Now [ticketed](../tickets/server-side-money-has-no-single-formatter-and-no-lint.md) and
  **deliberately not patched** — the cold review established that `toNaira()` plus a currency code is
  the repository's only server-side money rendering and that `SubmitCreditNote:92` uses it identically,
  so changing one site would create a third notation rather than remove the second.
- The login redirect for `maker@drive.test` still lands back on `/login` (the `/dashboard` 403 the
  discount-policies drive filed). Pre-existing, unrelated, and it makes a working login look broken to
  a first-time driver.
