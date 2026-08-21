# feat/allocation-payment-axis-guard — the payment axis gets the guard the invoice axis has had since July

**Branch:** `feat/allocation-payment-axis-guard`, cut from `origin/staging` at `47de056`.
**Ticket:** [`docs/handoff/tickets/nothing-constrains-allocations-to-a-payments-amount.md`](../tickets/nothing-constrains-allocations-to-a-payments-amount.md)
**Measured on:** MySQL **8.0.43**, database `portal_testing`, 2026-08-21. Every number and
every SQL reading below was taken on this branch, on that server, in the session that wrote
this file. No 5.7 server was available — see § "What I could not verify".

## What shipped

One migration, two test files, and one edit to an existing test.

| File | What |
| --- | --- |
| `database/migrations/2026_08_21_110000_finance_allocation_not_over_payment_amount.php` | new — the `BEFORE INSERT` trigger |
| `tests/Feature/Finance/PaymentAxisGuardTest.php` | new — proofs a, b, b2, c, d, d2, currency, no-regression (8 arms) |
| `tests/Feature/Finance/PaymentAxisConcurrencyTest.php` | new — proofs f0, f1, f1b, f2, f3 (5 arms) |
| `tests/Feature/Finance/WalletW3ConcurrencyTest.php` | **modified** — its PROOF 5 fixture was writing an illegal allocation. See § "The existing suite contained a violation" |

Nothing in `RecordPayment` or `GenerateInvoice` was touched. Their caps stay; this is a floor
beneath them. `PaymentReceiptController` was not touched — the ticket names flooring
`unallocated` at zero as explicitly not-the-fix and it was not done.

## The trigger, read back from `information_schema` — not from the migration's exit code

Per ADR 0052 the migration reporting `DONE` proves nothing. This is
`information_schema.TRIGGERS` after `migrate:fresh`, and the body is the byte-for-byte
verbatim `ACTION_STATEMENT` as MySQL stored it. (This project has previously had a
dump-safe oracle pass a broken body —
[`dump-safe-trigger-oracle-passes-a-broken-body.md`](../tickets/dump-safe-trigger-oracle-passes-a-broken-body.md) —
so the body is reproduced in full rather than summarised.)

### Shape

```
TRIGGER_NAME                                   TIMING EVENT  ORDER ORIENTATION
finance_allocation_not_over_invoice_total      BEFORE INSERT ORDER=1 ROW
finance_allocation_not_over_payment_amount     BEFORE INSERT ORDER=2 ROW
finance_payment_allocations_no_update          BEFORE UPDATE ORDER=1 ROW
finance_payment_allocations_no_delete          BEFORE DELETE ORDER=1 ROW
```

`ACTION_ORDER=2` is deliberate and is measured, not assumed: the new trigger was created
after the July sibling, so for the same `BEFORE INSERT` event MySQL fires the invoice-axis
guard first. When a row violates **both** axes the bursar sees the invoice-axis message —
which is the older, more familiar one. PROOF d2 pins that each axis answers only its own
violation.

### Body

```sql
BEGIN
                DECLARE v_amount BIGINT;
                DECLARE v_currency CHAR(3);
                DECLARE v_already BIGINT;

                SELECT amount_minor, amount_currency INTO v_amount, v_currency
                  FROM finance_payments WHERE id = NEW.payment_id;

                -- Defense in depth: an allocation must share the payment's currency, so the
                -- sum below compares like with like. Without it the comparison is not merely
                -- weak, it is undefined — minor units of two currencies summed into one
                -- total and measured against a third quantity.
                --
                -- BINARY, not a plain <>, for the reason the sibling trigger records at
                -- length: a routine variable takes the connection collation while the column
                -- takes the table collation, and on a database created with a different
                -- default collation those disagree and MySQL raises 1267 on EVERY insert,
                -- matching currency or not. A currency code is a 3-letter ASCII token, so a
                -- byte comparison is exactly right and is collation-agnostic.
                IF BINARY NEW.amount_currency <> BINARY v_currency THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
                        'finance_payment_allocations.amount_currency must match the payment currency.';
                END IF;

                -- Sum of ALL prior allocations of this payment. The table is append-only, so
                -- this is the whole history. COALESCE because the first allocation sees none
                -- and SUM over no rows is NULL, not 0.
                SELECT COALESCE(SUM(amount_minor), 0) INTO v_already
                  FROM finance_payment_allocations WHERE payment_id = NEW.payment_id;

                -- <=, not <: an allocation exactly exhausting the payment is legal and is the
                -- ordinary case (a payment that settles its invoice to the kobo).
                IF v_already + NEW.amount_minor > v_amount THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
                        'Allocation would exceed the payment amount: Σ(allocations) must be ≤ finance_payments.amount_minor.';
                END IF;
             END
```

This is what was written. The escape-stripping failure mode the dump-safe oracle exists for
does not arise here: neither `SIGNAL` message contains an apostrophe, and the only
apostrophes in the body (`payment's`) sit inside `--` comments, where the rest of the line is
already discarded by any reader. `TriggerBodiesAreDumpSafeTest` passes in the full run below.

**A trigger and not a `CHECK`, and that is not a style choice.** Production is MySQL 5.7.23-23;
`CHECK` is parsed and ignored before 8.0.16. `2026_08_17_100000_maker_checker_and_payment_origin_as_triggers.php`
is the house pattern and the reasoning; this migration follows it.

## Isolation level — read, not assumed (PROOF f0)

The brief asked for whatever level this project's connection actually uses. `config/database.php`
sets **no** `isolation_level` key on the `mysql` connection, so Laravel issues no
`SET TRANSACTION ISOLATION LEVEL` and the session inherits the server global.

```
session=REPEATABLE-READ  global=REPEATABLE-READ  version=8.0.43  innodb_lock_wait_timeout=50
connection default = mysql;  config isolation key = NULL
```

This matters twice below: it is why f1's stale-snapshot demonstration works, and it is *not*
what makes f3's residual real — a plain read cannot see an **uncommitted** sibling at READ
COMMITTED either, so f3 would hold at either level.

## Proofs

Suite command throughout: `DB_DATABASE=portal_testing ./vendor/bin/pest <path>`.

Every allocation insert in `PaymentAxisGuardTest` is raw `DB::table(...)->insert`, never
`RecordPayment` and never `GenerateInvoice`. That is PROOF c, and it is the reason the other
arms mean anything: a test driven through either Action would be satisfied by the application
cap and would stay green with the trigger dropped.

Every refusal arm asserts the **message**, not merely SQLSTATE `45000`. Roughly fifty triggers
in this schema signal `45000` / driver code 1644 — the append-only guards, the maker-checker
guards, the invoice-axis sibling — so a `toThrow(QueryException::class)` would be satisfied by
any of them.

### a — an allocation exactly equal to the payment amount is permitted

Invoice 100000 (so the invoice axis has room and cannot be what is under test), raw payment
50000, raw allocation 50000. Accepted; `Σ = 50000`.

### b — one kobo past the amount is refused, by this trigger, with this message

After the 50000 allocation above, a further raw allocation of 1. The invoice axis still has
50000 of room, so nothing but the payment-axis trigger can refuse it.

```
SQLSTATE[45000]: <<Unknown error>>: 1644 Allocation would exceed the payment amount:
Σ(allocations) must be ≤ finance_payments.amount_minor.
```

`errorInfo[0] === '45000'`, `errorInfo[1] === 1644`, message contains
`Allocation would exceed the payment amount` and `finance_payments.amount_minor`. Σ is
unchanged at 50000 afterwards — a refused insert on an append-only table must leave no trace.

### b2 — the first-ever allocation, one kobo past the amount, is refused too

The `COALESCE` arm. `SUM` over zero rows is `NULL` on MySQL, so without it the comparison is
`NULL + 1 > 50000` → `NULL` → not true → **accepted**. PROOF b always has a prior row and never
reaches that branch; this arm is the only one that does.

### c — the refusal is at the database, with both Action caps bypassed

Raw payment of 10, raw allocation of 11, no Action constructed for the write anywhere in the
test. Refused as `45000` / 1644 — a MySQL `SIGNAL`, which cannot originate in PHP. Zero rows
land. This is the SQL-console / bulk-correction / restored-dump case the trigger exists for.

### d — the invoice-axis trigger still fires, independently

Payment 500000 (enormous headroom on the payment axis), invoice 1000, allocation 1001.

```
1644 Allocation would exceed the invoice total: Σ(allocations) must be ≤ finance_invoices.total_minor.
```

Asserted to contain `finance_invoices.total_minor` and to **not** contain
`finance_payments.amount_minor` — the two axes answered separately.

### d2 — both axes on the same table, each refusing only its own violation

One invoice of 1000; payment P of 500000 and payment Q of 100.

- allocation 1001 against P → invoice-axis message (1001 > 1000 invoice, ≤ 500000 payment);
- allocation 101 against Q → payment-axis message (101 ≤ 1000 invoice, > 100 payment);
- allocation 100 against Q → accepted, one row in the table.

### currency

Payment in USD, invoice in NGN, allocation in NGN. The invoice-axis currency check passes
(allocation matches invoice), so only the payment-axis check can refuse:
`finance_payment_allocations.amount_currency must match the payment currency.`

Included beyond the ticket's literal text, and the reason is that without it the Σ comparison
is not weak but **undefined** — minor units of two currencies summed into one total and
measured against a third quantity. It is the sibling trigger's own stated rationale, one axis
over. Its one behavioural consequence is named in § "What this refuses that nothing refused
before".

### no-regression

`RecordPayment` handling a 100000 payment against a 100000 invoice still writes its allocation:
Σ = 100000 = the payment amount. This arm is where mutation M2 bites hardest (below).

## Bite-proofs — each mutation applied, run, red pasted, restored

The mutation is applied to the **migration**, not to the database, because `RefreshDatabase`
runs `migrate:fresh` per process and would silently restore a hand-dropped trigger.

| Mutation | Arms that went RED | Arms that stayed green |
| --- | --- | --- |
| **M1** — the Σ check deleted outright | b, b2, c, d2 | a, d, currency, no-regression |
| **M2** — `>` becomes `>=` (refuse the exact fill) | **a, b, d2, no-regression** | b2, c, d, currency |
| **M3** — `COALESCE(SUM(...), 0)` becomes `SUM(...)` | b2, c, d2 | a, b, d, currency, no-regression |
| **M4** — the currency check deleted | currency | all seven others |
| **M5** — `GenerateInvoice`'s account `lockForUpdate` replaced with a plain read (test-side) | f1 | f0 |

**M1**, four arms red, verbatim:

```
PROOF b   — The write was ACCEPTED. No trigger refused it.
PROOF b2  — The write was ACCEPTED. No trigger refused it.
PROOF c   — The write was ACCEPTED. No trigger refused it.
PROOF d2  — The write was ACCEPTED. No trigger refused it.
```

`pest`: `tests: 8, passed: 4, errors: 4`. PROOF a, d, currency and no-regression stayed green,
which is correct — none of them depends on the Σ check.

**M2** is the most useful of the five, because `no-regression` goes red:

```
PROOF a           — 1644 Allocation would exceed the payment amount ... (insert ... 50000, NGN ...)
PROOF b           — 1644 ... (insert ... 50000, NGN ...)
PROOF d2          — 1644 ... (insert ... 100, NGN ...)
NO REGRESSION     — 1644 ... (insert ... 100000, NGN, payment_against_named_invoice ...)
```

`pest`: `tests: 8, passed: 4, errors: 4`. An off-by-one on this boundary does not merely
loosen or tighten an edge case — it breaks the ordinary bursar path, because a payment that
settles its invoice to the kobo is the common case, not the corner.

**M3**, three arms red — every arm whose payment has no prior allocation:

```
PROOF b2  — The write was ACCEPTED. No trigger refused it.
PROOF c   — The write was ACCEPTED. No trigger refused it.
PROOF d2  — The write was ACCEPTED. No trigger refused it.
```

`pest`: `tests: 8, passed: 5, errors: 3`. This is why PROOF b2 exists as a separate arm.

**M4**, one arm red: `CURRENCY — The write was ACCEPTED. No trigger refused it.`
(`tests: 8, passed: 7, errors: 1`.)

**M5** (test-side, on the concurrency file) turns PROOF f1's own guard-arm red with the
message the arm carries for exactly this purpose:

```
PROOF f1 — B acquired the account lock while A held it: the account row does NOT serialise.
```

After every mutation the file was restored from a copy and `diff -q` reported byte-identical
before the next run.

## Concurrency — measured, and the answer is better than the ticket assumed in one place and worse in another

The ticket asked for a proof rather than an assertion, because the sibling trigger's docblock
already records what a trigger cannot see. All four scenarios are deterministic two-connection
interleaves on `DatabaseTruncation` (never a backgrounded race), following the house shape in
`InvoiceConcurrencyTest` and `WalletW3ConcurrencyTest`.

### f1 — two concurrent `GenerateInvoice` drawing credit forward from one payment

The ticket recorded that `applyCreditForward` is a read-then-write with **no lock on the
payment row**, and that whether `RecordPayment`'s serialisation argument covers this axis "has
not been established here". Both halves are now measured. Setup: one payment of 5000 against a
2000 invoice, so 2000 is allocated and 3000 of carry-forward credit sits on a single payment
with 3000 of headroom — exactly the state two generations would both draw on.

1. **The payment row is NOT locked.** With A inside `GenerateInvoice`'s transaction holding
   the account row, connection B takes `SELECT ... FOR UPDATE` on `finance_payments` for that
   payment and **succeeds**. The ticket read the code correctly: nothing in
   `applyCreditForward` protects the payment row.
2. **The account row IS.** B's own first statement — the same
   `StudentAccount ... lockForUpdate` — blocks and times out with driver code **1205**
   (`innodb_lock_wait_timeout` set to 1 for the arm). So two generations cannot both be inside
   the read-credit→spend window.
3. **And the staleness that makes the lock load-bearing is shown:** B's *plain* read still
   reports 3000 of credit and 2000 allocated, so an unlocked `applyCreditForward` would re-draw
   the same 3000 from the same payment.

**The conclusion the ticket left open:** the payment axis is serialised, and the serialisation
point is the **account row**, not the payment row. It works because it is strictly *coarser* —
every payment `applyCreditForward` can draw on belongs to the one student whose account row is
held. Mutation M5 (pull the lock) turns this arm red, so the arm is load-bearing rather than
decorative.

### f1b — end to end, with the real action as the losing racer

The primitive-level arm above proves the lock blocks; this proves `GenerateInvoice` *uses* it.
The loser opens a transaction and takes its snapshot (credit 3000). The winner then commits, on
a second connection, an allocation drawing the full remaining 3000 forward plus the balance
move to 0. The loser's plain read is stale and still reports −3000. The real `GenerateInvoice`
then runs inside that stale transaction, and because its **first** statement is a current read
of the account row it sees balance 0 → credit 0 → `applyCreditForward` is never called.

Measured after the stale transaction ends: Σ for the payment is **5000**, exactly its amount —
not 8000. The loser drew nothing.

One thing this arm taught, recorded because it cost a red: Σ read from *inside* the loser's
transaction is 2000, not 5000, because that connection's REPEATABLE READ snapshot formed at the
first plain read — before the winner committed. My first version asserted the true total there
and failed. The staleness is the danger being demonstrated, so the assertion moved outside the
transaction rather than being softened.

### f2 — `RecordPayment` racing `GenerateInvoice` on the same payment

The axis turns out to be **vacuous** for `RecordPayment`, and the reason is measured rather
than argued. A runs the real `RecordPayment` inside an open transaction: its payment row and
its 6000 allocation exist but are uncommitted. From connection B:

- `finance_payments where id = <A's payment>` → **0 rows**;
- `finance_payments where student_id = <student>` → **0 rows**.

So the payment is not merely locked, it does not exist in B's snapshot at all —
`applyCreditForward`'s `Payment::where(student_id)->orderBy(id)->get()` cannot return it and
there is nothing for B to draw. B's account read shows the **pre-A** balance of 10000 (the
setup charge), so `max(0, −10000) = 0` credit and B never enters `applyCreditForward`.

`RecordPayment` is safe on this axis **by exclusivity, not by a lock**: it creates the payment
inside its own transaction and writes at most one allocation against it, capped at
`min(amount, outstanding)`. It needs no payment-row lock, and adding one would protect nothing.

### f3 — THE RESIDUAL, demonstrated rather than conceded

**The trigger does not close a race it cannot see, and this arm exists so that no one —
including this report — can describe it as airtight.**

A payment of 10000 with no allocations. A opens a transaction and inserts 5001: the trigger's
`SELECT SUM` sees 0 prior, `0 + 5001 ≤ 10000`, **accepted**. B, on its own connection, inserts
5001: the trigger runs inside B's transaction and its `SELECT SUM` is a **plain** read, so it
cannot see A's uncommitted row, sees 0 prior, and also **accepts**. Both commit.

Measured afterwards:

```
Σ(allocations) for the payment = 10002
finance_payments.amount_minor  = 10000
```

The invariant is violated and, because the table is append-only, the rows are permanent.

**The control, without which the arm proves nothing:** the *same* two inserts, on **one**
connection, against a fresh payment, ARE refused —
`1644 Allocation would exceed the payment amount ...`, and Σ stops at 5001. So the pair above
got through because of cross-transaction blindness, not because the trigger is absent or its
arithmetic is wrong.

**What would close it,** demonstrated on the same rows rather than offered as advice: a
`SELECT ... FOR UPDATE` on the payment row inside each writer's transaction. With A holding
that row, B's lock request blocks and times out (1205), so B's trigger would then run after A
committed and would see the 5001. A trigger cannot take a lock that outlives its own statement,
so this cannot be pushed into the database — it has to live in the writer.

### The honest summary of the concurrency position

| | |
| --- | --- |
| **Serialised today?** | Yes, for both live writers — measured. `RecordPayment` by exclusivity (f2); `GenerateInvoice::applyCreditForward` by the account-row lock (f1, f1b). |
| **By this trigger?** | **No.** The trigger is the single-write / tamper / restored-dump backstop. |
| **The residual** | The coverage is a property of the two writers that exist today, **not of the schema**. A future writer that allocates against a payment without joining the account-row lock — a job, a bulk correction, a second path — would race, and this trigger would not catch it (f3). |
| **Closing it** | `SELECT ... FOR UPDATE` on `finance_payments` inside that writer's transaction. Nothing in the database can compel it. |

This is a **smaller, named residual** than the ticket's open question, not a closed one. The
ticket asked whether anything serialises the payment axis; the answer is the account row, and
the new question is only whether a future writer joins it.

## The existing suite contained a violation of this invariant

`WalletW3ConcurrencyTest`'s PROOF 5 inserted a 1000 allocation against a payment of 5000 that
`RecordPayment` had **already allocated in full** against invoice X. Σ for that payment would
have become 6000 against a 5000 payment. The fixture was illegal all along and the database had
no opinion; the new trigger refused it on the first run:

```
SQLSTATE[45000]: 1644 Allocation would exceed the payment amount: Σ(allocations) must be ≤
finance_payments.amount_minor. (Connection: w3_concurrent, ... insert into
`finance_payment_allocations` ... payment_id 1 ... 1000 ...)
```

**This is evidence for the change, not against it** — a test written by someone who knew this
schema well wrote an over-allocated payment without noticing, which is the ticket's point about
the guard existing on one axis of two.

The fix gives PROOF 5 a **separate, deliberately unallocated payment** of 1000 to settle from.
Nothing about the proof changes: it is about lock **ordering** — that an account-first sequence
completes without waiting on an invoice row held invoice-first, so no 1213 — and the allocation
is only the write at the end of that sequence. It still inserts, on the second connection, while
A holds invoice X. Only the payment it draws on now has room in it. The assertion at the end
(`Σ(allocations to invoice Y) === 1000`) is unchanged and not weakened.

## Suite numbers, before and after

Same command, same database, same machine, both on this branch:

| | tests | passed | assertions | duration |
| --- | --- | --- | --- | --- |
| **before** (`origin/staging`, `47de056`) | 663 | 663 | 3325 | 240,074 ms |
| **after** | **676** | **676** | **3383** | 252,797 ms |

`+13` tests = 8 (`PaymentAxisGuardTest`) + 5 (`PaymentAxisConcurrencyTest`). The two live
writers are unaffected: no pre-existing Finance test changed behaviour, and the single
pre-existing test that changed at all is the illegal fixture above.

### One red on the way through that was NOT this change

The first post-migration run also failed `SubledgerClockFrameTest` at line 127:

```
Failed asserting that 91 is identical to 90.
```

That is `test()->travel(90)->seconds()` plus a real second-boundary crossing between the two
captures — a wall-clock flake with nothing to do with a trigger. Recorded rather than quietly
re-run, per the capture-before-you-re-run rule. Conditions at the time: elapsed 244,579 ms
(the baseline run four minutes earlier was 240,074 ms, the same band), same invocation shape.
It has been green in every run since, including the full 676-test run above and a targeted
re-run of that file alone.

### Other gates

```
citation-lint: OK — no new citation violations (170 baselined key(s), 187 citation(s)).
authz-lint:    OK — no new commented-out authorization checks (0 known).
boundary-lint: OK — no new boundary violations (7 known temporary exceptions).
pest --group=arch: 103 passed, 564 assertions
pint: 1 file fixed (PaymentAxisGuardTest — fully_qualified_strict_types, ordered_imports)
```

Pint was run against the four explicit files, never against a directory. `git diff --stat`
reads one modified file (29 insertions, 1 deletion) plus three new ones, which matches the
change exactly.

The citation lint refused the migration docblock twice before it passed, and both refusals were
correct: a bare `path:LINE` range names no symbol, and a symbol-first citation split across two
comment lines is not adjacent to its parenthesis. The final form is symbol-last on one line, and
the line numbers were re-derived from the file rather than copied from the ticket — the ticket's
`GenerateInvoice.php:412-418` had already drifted to 479+.

## Four-path rollback audit

Re-derived per run rather than trusting `--step=1` blindly (the `--step=N`-is-relative trap).
`migrate:status` confirmed `2026_08_21_110000_finance_allocation_not_over_payment_amount` was
the last migration, so `--step=1` names it — and the rollback output names it too:

```
== BEFORE rollback ==
  finance_allocation_not_over_invoice_total
  finance_allocation_not_over_payment_amount
  finance_payment_allocations_no_delete
  finance_payment_allocations_no_update
== rollback --step=1 ==
  2026_08_21_110000_finance_allocation_not_over_payment_amount .. 13.43ms DONE
== AFTER rollback ==
  finance_allocation_not_over_invoice_total
  finance_payment_allocations_no_delete
  finance_payment_allocations_no_update
== AFTER re-up ==
  finance_allocation_not_over_invoice_total
  finance_allocation_not_over_payment_amount
  finance_payment_allocations_no_delete
  finance_payment_allocations_no_update
```

My trigger is gone after the rollback, the other three survive it, and the re-up restores it.
The assertion is on the object, not on a bare exit-0.

## What I could not verify

- **MySQL 5.7.23-23, which is production.** No 5.7 server was available. `TRIGGER` +
  `SIGNAL SQLSTATE '45000'` is documented 5.5+ and `BINARY` comparison is ordinary SQL, so this
  trigger is *expected* to work there — but that is documentation, not observation, exactly as
  `2026_08_17_100000_maker_checker_and_payment_origin_as_triggers.php` records for its own
  fourteen. Every measurement in this report is 8.0.43 and only 8.0.43.
- **Whether any over-allocated payment exists in production today.** This migration installs a
  `BEFORE INSERT` trigger, so it does not inspect or repair existing rows and will deploy
  cleanly over a violating one. The sibling's docblock calls for a deploy pre-flight asserting
  zero exist; the same pre-flight is needed here and is not part of this branch. The query is
  `SELECT payment_id FROM finance_payment_allocations GROUP BY payment_id HAVING SUM(amount_minor) > (SELECT amount_minor FROM finance_payments WHERE id = payment_id)`.
- **The mixed-currency draw.** The currency arm of this trigger would refuse an
  `applyCreditForward` draw where the payment's currency differs from the invoice's — see the
  next section. No such row was found and none was looked for beyond the local test database;
  whether any exists in production is unknown.
- **Any behaviour under a writer that does not yet exist.** f3 shows the shape of the failure;
  it cannot show which future writer will have it.
- **The suite's own determinism.** ADR 0053 records byte-identical code producing both PASS and
  FAIL. The `SubledgerClockFrameTest` red above is a concrete instance of that class. A green
  here is one observation, not a proof.

## What this refuses that nothing refused before

Beyond the over-allocation itself, the currency arm changes one path. `applyCreditForward`
draws payments oldest-first for a student **regardless of currency**, and stamps the allocation
with the **invoice's** currency (`GenerateInvoice.php` — `$currency = $invoice->total->currency`).
For a student holding a USD payment and being billed in NGN, it would write an NGN allocation
against a USD payment. The invoice-axis trigger has no objection (the allocation matches the
invoice); the payment-axis trigger now refuses it with
`finance_payment_allocations.amount_currency must match the payment currency.`

That is a **hard refusal where there was previously a silent nonsense** — two currencies summed
into one total. It surfaces as a 500 rather than a 422, because per `bootstrap/app.php` driver
code 1644 has no mapping and falls through to the generic handler, exactly like every other
`45000` guard in this schema. It is called out here because a reader auditing this branch for
blast radius should see it named rather than discover it in production. If the project would
rather have that path bank the credit at the account level instead, that is a change to
`applyCreditForward` — which this branch was told not to touch, and did not.
