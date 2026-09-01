# `RecordPayment` checks `isVoid()` on the unlocked instance

**Raised:** 2026-09-01 · **From:** `feat/paystack-webhook`, second cold review · **Severity:** ticket (one line)

## What

`app/Finance/Actions/RecordPayment.php` refuses a void invoice **before** it opens its transaction:

```php
if ($invoice->isVoid()) { throw new BusinessRuleException(...); }   // the passed-in instance
...
$locked = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();
```

`$locked` is then used only for `id` and `total`. Its status is never re-read.

## The failure

A void committing between the read and the lock is missed: the payment, the allocation and the
ledger credit all land against a void invoice. The refusal is real and correct — it simply looks at
a copy of the row taken before the serialisation point.

## Why it is a ticket and not a fix on this branch

Pre-existing on the bursar path, where the two statements are microseconds apart and a human is
present. **But `feat/paystack-webhook` newly routes gateway money through this action**, and gateway
settlement is the caller with the widest window: it holds its own `FOR UPDATE` on the gateway row
across both statements, and the invoice instance is loaded before any of it.

Worth recording now rather than discovering from a void invoice with a payment allocated to it, on
an append-only table.

## The fix

Move the check onto `$locked`, inside the transaction — where every other decision in that method
already reads from. One line, and it makes the guard read the row the lock exists to make
authoritative.

## Not the fix

Adding a second check before the lock and keeping the first. Two checks around a lock is the shape
that looks careful and still reads the stale row on the path that matters.
