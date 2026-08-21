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

### Body — as it stands after the cold-review fixes

```sql
BEGIN
                DECLARE v_amount BIGINT;
                DECLARE v_currency CHAR(3);
                DECLARE v_already BIGINT;
                DECLARE v_foreign BIGINT;

                SELECT amount_minor, amount_currency INTO v_amount, v_currency
                  FROM finance_payments WHERE id = NEW.payment_id;

                -- ARM 1 — the incoming allocation must be in the payment's currency.
                --
                -- CAST(x AS BINARY) and not BINARY x: the latter is deprecated and emits
                -- warning 1287 twice per insert. Both forms are collation-agnostic, which
                -- is what this comparison needs — a routine variable takes the connection
                -- collation and the column takes the table collation, and where those
                -- disagree a plain <> raises 1267 on EVERY insert, matching currency or
                -- not, turning this guard into a total outage. A currency code is a
                -- 3-letter ASCII token, so a byte comparison is exactly right.
                IF CAST(NEW.amount_currency AS BINARY) <> CAST(v_currency AS BINARY) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
                        'finance_payment_allocations.amount_currency must match the payment currency.';
                END IF;

                -- ARM 2 — the payment must not ALREADY carry an allocation in some other
                -- currency. Arm 1 stops new ones; this stops a payment that legacy data
                -- (or a 5.7 server on which no CHECK ever ran) left holding two. Without
                -- it, scoping the sum below would let EACH currency be allocated up to the
                -- full payment amount, which is a worse failure than the one being fixed.
                SELECT COUNT(*) INTO v_foreign
                  FROM finance_payment_allocations
                 WHERE payment_id = NEW.payment_id
                   AND CAST(amount_currency AS BINARY) <> CAST(v_currency AS BINARY);

                IF v_foreign > 0 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
                        'This payment carries allocations in more than one currency; no total is comparable. Investigate before allocating more.';
                END IF;

                -- ARM 3 — the ceiling. SCOPED TO THE PAYMENT CURRENCY, so this statement
                -- cannot add two currencies together even if arm 2 were deleted. The table
                -- is append-only, so this is the whole history. COALESCE because the first
                -- allocation sees no rows and SUM over none is NULL, not 0 — and NULL + x
                -- > y is NULL, which is not true, so the row would be accepted.
                --
                -- NO READ OF allocation_rule ANYWHERE ABOVE, deliberately: the ceiling is a
                -- property of the payment, not of why the allocation was written.
                SELECT COALESCE(SUM(amount_minor), 0) INTO v_already
                  FROM finance_payment_allocations
                 WHERE payment_id = NEW.payment_id
                   AND CAST(amount_currency AS BINARY) = CAST(v_currency AS BINARY);

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
  cleanly over a violating one. **The query this section originally published was itself
  defective** — it performed the same cross-currency addition the trigger did, and on the
  fixture in FIX 2 below it returns zero rows and reports a corrupt payment as clean. Both
  corrected clauses now live in the migration docblock, which is where the July sibling puts
  its equivalent, and both are pinned by PROOFS e4 and e5.
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

---

# Cold review, and the five fixes it produced

The branch went to cold review after the first push. It returned four findings; a fifth was
declined by the reviewer and reinstated by the project lead; a sixth became a ticket. **The
severities below were set by the project lead, not by me and not by the reviewer.** All five are
FIX. One commit: `fix(finance): the guard was untested against its own writer, and it was adding
naira to dollars`.

Two of the findings were serious. One of them — FIX 2 — is a defect in the shipped trigger, on a
money table, that the first version of this report described as a feature.

Every fix below was **reproduced before it was changed**, and every new arm was run green before
its mutation. Suite: **676 → 692**, 3383 → 3452 assertions, all green.

## FIX 1 — the guard was untested against the writer it exists for

**Reproduced first.** The reviewer's mutation, applied to the migration body:

```sql
IF NEW.allocation_rule = 'payment_against_named_invoice' AND v_already + NEW.amount_minor > v_amount THEN
```

```
tests: 676, passed: 676, assertions: 3383, duration: 257,175 ms
```

**All 676 Finance tests green** with the ceiling disabled for exactly
`credit_applied_forward_oldest_first` — the rule `applyCreditForward` stamps
(app/Finance/Actions/GenerateInvoice.php:479 (applyCreditForward)), and the one writer this very
report calls a read-then-write with no payment-row lock. Every refusal arm hardcoded the other
rule at the helper. The guard was untested against the writer it exists for, and the report's own
concurrency section is what makes that the wrong writer to miss.

**The fix.** `payAxisAllocate` takes the rule as a parameter, `payAxisRules()` returns both
constants (from `PaymentAllocation::RULE_*`, not retyped, so a rename breaks the test instead of
narrowing it), and four arms were added:

- **a3** — exact fill permitted, under every rule (dataset over both).
- **b4** — one kobo past refused, under every rule, same message (dataset over both).
- **b5** — the cross case: a `credit_applied_forward_oldest_first` row blocks a
  `payment_against_named_invoice` row, and the reverse. Neither single-rule arm reaches this.
- **b6** — structural: the stored body does not contain `allocation_rule` at all, comments
  stripped.

**The mutation now reds**, three arms:

```
PROOF b4 [credit_applied_forward_oldest_first]  — The write was ACCEPTED. No trigger refused it.
PROOF b5                                        — The write was ACCEPTED. No trigger refused it.
PROOF b6                                        — Expecting 'BEGINDECLAR…   END' not to contain 'allocation_rule'.
```

`tests: 22, passed: 19, failed: 1, errors: 2` — and `b4 [payment_against_named_invoice]` stays
green, which is correct and is what makes the dataset arm meaningful rather than duplicated.

## FIX 2 — the trigger was adding naira to dollars

**Reproduced first**, on the reviewer's fixture: an NGN payment of 10000 carrying legacy
allocations of 5000 NGN and 5000 USD, planted with both allocation triggers dropped.

```
raw SUM(amount_minor)              => 10000     <- 5000 NGN + 5000 USD, added together
finance_payments.amount_minor      => 10000
pre-flight rows flagged            => 0         <- passes the database as clean
real NGN room left                 => 5000
1-kobo NGN allocation REFUSED with => "Allocation would exceed the payment amount:
                                       Σ(allocations) must be ≤ finance_payments.amount_minor."
```

A payment holding 5000 NGN of genuine room, refusing a 1-kobo NGN allocation, on the strength of
a total that added two currencies. And the pre-flight this report published would have called
that payment clean on the way in.

This is a defect in the **trigger**, not only in the pre-flight, and the schema permits the state:
`applyCreditForward` selects a student's payments with **no currency filter** and stamps the
**invoice's** currency on the allocation, so before this trigger existed nothing stopped it — and
on 5.7, where no `CHECK` ever ran, nothing stopped it there either.

### The shape chosen, and why, against both failure modes

Two shapes were available. **Both were taken**, because each alone fails in a different direction:

- **Scoping the sum alone is worse than the defect.** With a per-currency sum and nothing else,
  *each* currency could be allocated up to the *full* payment amount — an NGN payment of 10000
  accepting 10000 NGN **and** 10000 USD. That converts a wrong refusal into a wrong acceptance on
  a money table, which is the worse direction.
- **Refusing the mixed payment alone** leaves the addition in the code, one deleted line from
  returning.

Together they are exact. Arm 2 guarantees every surviving allocation of a payment shares its
currency; the scoped sum guarantees that even with arm 2 removed the trigger still cannot add two
currencies. The property *this trigger never adds two currencies* therefore holds **locally**,
from the `WHERE` clause, rather than depending on a check three statements earlier.

The message names the actual fault:
`This payment carries allocations in more than one currency; no total is comparable. Investigate
before allocating more.` — 119 characters, pure ASCII, under MySQL's 128 cap. A payment carrying
two currencies is corrupt data, not an over-allocation, and sending a bursar to look at amounts
when the fault is currencies sends them somewhere there is nothing wrong.

### Arms, both directions

- **e1** — the mixed payment is refused as **mixed currency**, and explicitly *not* with the
  over-allocation message. Also pins that the raw cross-currency sum is 10000 while the scoped
  sum is 5000, so the arm names the true figure the old trigger got wrong.
- **e2** — the wrong-acceptance direction: with a foreign row planted, a 10000 NGN allocation is
  refused (`more than one currency`) where a scope-only fix would have accepted it.
- **e3** — the scope does not weaken the ordinary ceiling: 4000 + 6000 fills a 10000 payment and
  a further kobo is refused.
- **e4** — the pre-flight: the **naive query is pinned red-side**, returning zero rows on the
  corrupt fixture, so nobody reinstates it; clause 1 is correctly silent; clause 2 finds it.
- **e5** — clause 1 catches a genuine single-currency over-allocation, so its silence in e4 is
  not vacuity.
- **e6** — structural: the stored `SUM` statement is currency-scoped.

**A limit, stated rather than papered over.** While arm 2 stands, a mixed payment is refused
*before* the sum runs, so removing the currency scope from the `SUM` changes no observable
behaviour and **no behavioural arm can go red for it**. The scope is defense in depth; e6 is a
structural assertion because that is the only honest way to pin it.

### Mutations

**M7 — arm 2 deleted:**

```
PROOF e1 — The write was ACCEPTED. No trigger refused it.
PROOF e2 — The write was ACCEPTED. No trigger refused it.
```

**M8 — arm 2 deleted AND the sum unscoped** (the body exactly as it shipped before this fix):

```
PROOF e1 — Failed asserting that 'Allocation would exceed the payment amount: Σ(allocations)
           must be ≤ finance_payments.amount_minor.' contains "more than one currency".
PROOF e2 — (same)
PROOF e6 — Failed asserting that 'SELECT COALESCE(SUM(amount_minor), 0) INTO v_already …
           WHERE payment_id = NEW.payment_id;' contains "amount_currency".
```

M8's e1 red **is** the reported defect, reproduced as a test failure.

### The pre-flight, corrected

Both clauses now ship in the migration docblock. Clause 1 is currency-scoped — the naive
`HAVING SUM(amount_minor) > amount_minor` is the same cross-currency addition and finds nothing
on the fixture above. Clause 2 selects any allocation whose currency differs from its payment's.
Run against the planted fixture: clause 2 returns exactly one row, `payment_id` matching,
`amount_currency = USD`, `payment_currency = NGN`.

## FIX 3 — the pre-flight was not where it ships

It lived in the ticket and the report. **Two places, not three** — the report never claimed
three; that miscount was the project lead's, recorded here because the correction belongs next to
the thing corrected. Neither location is executable and neither is the migration. The July
sibling puts its equivalent in its own docblock; both clauses now sit in this migration's, in the
same voice, with the reason stated: a `BEFORE INSERT` trigger does not inspect existing rows and
installs cleanly over a violating one, so the assertion has to travel with the thing that makes
it matter.

## FIX 4 — `BINARY expr` is deprecated

Changed in **this migration only**. Six occurrences (three comparisons × two operands) moved from
`BINARY x` to `CAST(x AS BINARY)`. Verified from the stored body: zero deprecated forms remain,
six `CAST(… AS BINARY)`.

**The reviewer's mechanism is right and its timing is not, and this cost an arm.** The finding
said 1287 fires "twice per insert". Measured on 8.0.43, on a scratch table, emulated prepares on
so `SHOW WARNINGS` is reachable at all (over the binary protocol it answers 1295):

```
body using `BINARY expr`        CREATE TRIGGER: 2 warnings (1287)   INSERT: 0
body using `CAST(… AS BINARY)`  CREATE TRIGGER: 0                   INSERT: 0
```

The warnings are raised when the body is **parsed**, not on each write. My first g1 arm inserted
a row and read `SHOW WARNINGS` — and stayed **green under the mutation that reverts CAST to
BINARY**. It was watching the wrong moment and proving nothing. The corrected arm takes the
stored body out of `information_schema`, re-creates it under a scratch name, and reads the
warnings from the `CREATE`; the scratch trigger is dropped in a `finally`, because DDL commits
implicitly and `RefreshDatabase` cannot roll it back.

**M9 — CAST reverted to BINARY**, against the corrected arm:

```
PROOF g1 — the trigger body still uses the deprecated `BINARY expr` form:
           6 × {"Code":1287,"Message":"'BINARY expr' is deprecated and will be removed in a
           future release. Please use CAST instead"}
```

**The collation property is preserved, measured not assumed** (PROOF g2). `BINARY` was never
decoration: a routine variable takes the connection collation, a column takes the table
collation, and where they disagree a plain `<>` raises 1267 on *every* insert — a total outage,
not a loose guard.

```
comparing 'NGN' COLLATE utf8mb4_general_ci with 'NGN' COLLATE utf8mb4_unicode_ci
  plain <>            ERROR 1267 Illegal mix of collations ... for operation '<>'
  BINARY expr         OK, equal, 2 warnings (1287 × 2)
  CAST(… AS BINARY)   OK, equal, 0 warnings
and CAST still discriminates: 'NGN' <> 'USD' is 1, 'NGN' <> 'ngn' is 1
```

Collation-agnostic, still a case-sensitive byte comparison, and the currency refusal still fires
end to end.

## FIX 5 — the message-text read-back (declined by the reviewer, reinstated)

`up()` now reads the trigger back from `information_schema` and refuses to record the migration
unless the name, timing, event, table **and all three `SIGNAL` message texts** are what `CREATE`
claimed.

**A citation correction that matters to the reasoning.** The brief said the cited house pattern,
`2026_08_17_100000`, "reads its SIGNAL message text back from information_schema at :126-130". It
does not. Lines 126-130 are docblock prose, and its `assertTriggerShape()` reads
`ACTION_TIMING`, `EVENT_MANIPULATION` and `EVENT_OBJECT_TABLE` — **shape only, no message text**.
The requirement stands on its own evidence and I implemented it as a **superset** of the cited
pattern: shape *and* messages.

The reason is the one the project lead gave, and it is the single production-only risk on the
list: `MSG_OVER` is 99 characters / **102 bytes** — it carries `Σ` and `≤`, the only non-ASCII in
any `SIGNAL` here — and every other `Σ`/`≤` in this schema sits inside a `--` comment where
mangling is invisible. A latin1 client on 5.7 could corrupt exactly this message, and the trigger
would still fire, still refuse the right rows, and still pass every shape and behaviour assertion
in the suite.

**M10 — the message mangled as a latin1 client would mangle it** (`Σ` → `Î£`, `≤` → `â‰¤`), with
the PHP constant untouched and no behaviour changed:

```
Trigger [finance_allocation_not_over_payment_amount] is stored without the expected SIGNAL
message text [...]. The guard would still fire and still refuse the right rows, so no
behavioural test can see this — it is what a latin1 client does to a message carrying Σ and ≤.
Refusing to record the migration.
  at database/migrations/2026_08_21_110000_…:281  assertTriggerShapeAndMessages()
```

The migration refuses to record. Bite-proven.

## TICKET — `BINARY expr` in the merged triggers

`docs/handoff/tickets/binary-expr-is-deprecated-in-the-july-allocation-trigger.md`. Not changed
here: a merged trigger on a money table gets its own migration and its own proof.

**Scope re-derived rather than taken from the finding.** The brief named the July sibling. It is
one of **seven** triggers, and not the largest — measured from `information_schema` on a freshly
migrated database, comments stripped, counting `BINARY` not preceded by `AS `:

| Trigger | Occurrences |
| --- | --- |
| `finance_invoice_lines_reduction_guard` | 6 |
| `finance_credit_notes_insert_guard` | 4 |
| `finance_credit_notes_update_guard` | 2 |
| `finance_fee_items_parent_state_guard_ins` | 2 |
| `finance_fee_items_parent_state_guard_upd` | 2 |
| `finance_fee_items_parent_state_guard_del` | 2 |
| `finance_allocation_not_over_invoice_total` | 2 |

**7 triggers, 20 occurrences, out of 61 in the schema.** The ticket carries the corrected
firing-time measurement, the 5.7 validity of `CAST`, the collation evidence, and the note that an
insert-time warning arm cannot see any of it.

## Two things the reviewer verified that this report never claimed

Both are load-bearing for this trigger and neither had been written down anywhere — which by the
house rule made them wishes rather than rules. They are now pinned.

**PROOF h1 — `finance_payments.amount_minor` cannot be mutated out from under an existing Σ.**
The ceiling is read from the payment when the *allocation* is inserted. If the payment amount
could later be lowered, a legal Σ would silently become an over-allocation with **no write to the
allocations table for any trigger to see**. It cannot: `finance_payments_no_update` (BEFORE
UPDATE, append-only) refuses with 1644, and the arm asserts the amount is unchanged afterwards.

**PROOF h2 — an allocation naming a non-existent payment would be ACCEPTED by this trigger, and
is stopped by the foreign key.** With no matching payment the `SELECT … INTO` leaves `v_amount`
NULL, and `NULL + 1 > NULL` is NULL — not TRUE — so the `IF` does not fire. The arm demonstrates
that directly (`SELECT (NULL + 1 > NULL)` is NULL) and then shows the composite FK
`finance_payment_allocations_payment_school_foreign` `(school_id, payment_id) → finance_payments
(school_id, id)` refusing with **1452, not 1644**. The distinction is the point: the trigger
contributes nothing here and must not be credited with it.

That arm also caught its own first version: at an amount of 999999 against a 100000 invoice, the
*invoice*-axis trigger refused with 1644 and the arm would have passed for the wrong reason. The
amount is now 100, which clears both ceilings and leaves the FK as the only refusal in the stack.

## The reviewer's limits, in its words

Recorded because a review's blind spots travel with its findings:

- **no 5.7 server** — every reading it took, like every reading in this report, is 8.0.43;
- **production state unknown** — it could not say whether any over-allocated or mixed-currency
  payment exists today;
- **one observation each side of the suite count** — the before/after numbers are single runs,
  and ADR 0053 records byte-identical code producing both PASS and FAIL;
- **Finance directory and arch group only** — it did not run the full suite;
- **it disclosed an injected CodeGraph blob that it did not use** — surfaced in its context and
  reported rather than silently relied on.

## Numbers

| | tests | passed | assertions | duration |
| --- | --- | --- | --- | --- |
| `origin/staging` `47de056` | 663 | 663 | 3325 | 240,074 ms |
| first push | 676 | 676 | 3383 | 252,797 ms |
| **after the cold-review fixes** | **692** | **692** | **3452** | 319,702 ms |

`+16` arms in `PaymentAxisGuardTest` (8 → 24). `PaymentAxisConcurrencyTest` is unchanged at 5.

## The gate caught one of these arms, and it was a real regression

The first push of these fixes was **refused** by `bin/quality` at step 16/16. Steps 1-15 all
green; the failure was the suite's own quality arm:

```
ratchet: 1 NEW test failure(s) not in the baseline (regression):
  ✗ tests/Feature/Quality/PestNegatedExpectationMessagesTest.php::it no test passes a custom
    failure message to a negated Pest expectation

tests/Feature/Finance/PaymentAxisGuardTest.php:617  ->not->toBeEmpty (message is argument #1, 1 supplied)
```

PROOF e6 was written as
`expect($m)->not->toBeEmpty('the ceiling SUM statement was not found in the stored body')`.
**Pest discards a custom message passed to a negated expectation** — `->not->` runs the positive
assertion and, when it succeeds, throws its own shortened-export sentence, so the message is
never the failure description. An arm added to make a limit legible would have printed an
exported array instead of the sentence explaining it.

Rewritten per the quality test's own guidance, which prefers the rewrite carrying the most
information: `expect(count($m))->toBeGreaterThan(0, $message)`, which also puts the count in the
output. Not a flake and not re-run to green — a regression I introduced, caught by the floor,
fixed. Artefacts were copied out before anything was re-run (`pest-20260821-222815-18011.log`,
`junit-20260821-222815-18011.xml`), per the capture-before-you-re-run rule.

That this is the one thing the gate found, on a branch whose entire subject is guards that do
not do what they claim, is worth stating plainly rather than tidying away.

## What still cannot be verified

Unchanged from the first version, minus the pre-flight query which is now correct and shipped:

- **MySQL 5.7.23-23**, which is production. No 5.7 server was available. `CAST(… AS BINARY)`,
  `SIGNAL SQLSTATE '45000'` and `DROP TRIGGER IF EXISTS` are documented as valid there; none of
  it is observed.
- **Whether any over-allocated or mixed-currency payment exists in production today.** Both
  pre-flight clauses now ship in the migration docblock; neither has been run against production.
- **The suite's own determinism** (ADR 0053). Each number above is one observation.
