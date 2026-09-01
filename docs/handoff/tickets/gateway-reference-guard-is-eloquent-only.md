# RESOLVED 2026-09-01 — `fix/gateway-reference-trigger`

The trigger arm is installed by `2026_09_01_100000_gateway_reference_is_enforced_by_the_database.php`
and held by `tests/Feature/Finance/GatewayReferenceTriggerTest.php`, which writes through the RAW
query builder on every arm — the path the model guard cannot see.

One thing the fix taught, kept here because it generalises: MySQL caps `MESSAGE_TEXT` at **128
characters**, and overrunning it makes the `SIGNAL` fail with **1648** rather than the intended
**1644**. The row is still refused, so a bite-proof asserting only "an exception was thrown" passes
while the guard reports a code no caller recognises. Assert the CODE.

---

# The gateway reference guard is Eloquent-only — REQUIRED BEFORE STEP 3

**Raised:** 2026-09-01 · **From:** `feat/paystack-webhook`, second cold review · **Severity:** fix, before step 3

## What

`GatewayTransaction::booted()` refuses, on `creating`, any reference that does not route to the
row's own `school_id`. `static::creating` fires on the **Eloquent** write and on nothing else — not
`DB::table()->insert()`, not `->upsert()`, not a raw statement.

The repository already writes past it. `tests/Feature/Finance/GatewayTransactionSchemaTest.php`
inserts hand-built references (`'REF-'.Str::random(12)`) through the query builder, and passes.

## Why it is required before step 3, not after

`bin/ci-boundary-lint.php` forbids `DB::table` on a `finance_` string literal **outside**
`app/Finance`. Step 3's initialise call will live **inside** `app/Finance`, where that escape hatch
is permitted. So the one component this guard exists to protect is the one able to walk around it.

The failure it is supposed to prevent is unchanged and still silent: an unroutable reference is
accepted, the parent is charged, Paystack delivers, the webhook enters the wrong school (or no
school) and finds nothing, and answers 200. Money taken, no payment recorded, one log line saying
the reference was unknown.

## Why the model guard was accepted as the interim

It closes the path every current writer uses, and it fails loudly and early — at the write, before
anyone is charged. That is worth having now. What it must not do is be **described** as enforcement
of the contract, because the difference between "enforced" and "enforced on one of two write paths"
is invisible to a reader and decisive for step 3. The docblock and the branch report now say
Eloquent only.

## The fix

An arm in `finance_gateway_transactions_insert_guard`, in a new dated migration:

```sql
IF NOT (NEW.reference COLLATE utf8mb4_bin LIKE CONCAT('bpsk-', NEW.school_id, '-%')) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '...';
END IF;
```

`COLLATE utf8mb4_bin` for the reason every sibling comparison carries it: under the table's default
`utf8mb4_unicode_ci` the match is case- and accent-insensitive, so `BPSK-1-…` would pass a guard
written to accept only `bpsk-`.

**It will red `GatewayTransactionSchemaTest`**, which is the point — that file is the proof the
current guard is bypassable. Its fixtures move to minted references, and one arm stays raw and
asserts the 1644.

## The general shape

A guard at the application layer and a guard at the database layer are not the same guard, and this
project has already decided which one it trusts: the `origin` pairing rule was moved from a `CHECK`
to a trigger, and `finance_payments`, `finance_invoices` and the events table are all defended by
triggers rather than by model hooks. A model hook alone is the pattern this codebase deliberately
does not use for money.
