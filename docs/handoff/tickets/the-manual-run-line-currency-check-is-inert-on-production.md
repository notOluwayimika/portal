# `finance_manual_invoice_run_lines_amount_currency_shape` is inert on production

**Status:** open, deliberately deferred. Raised by the implementer during the claim-then-bill commit
on 30 August, and the deferral is recorded here rather than left as a shrug.

## The fact

`2026_08_30_100000` installs the currency-shape constraint as a `CHECK`:

```sql
CHECK (amount_currency IS NULL OR amount_currency COLLATE utf8mb4_bin REGEXP '^[A-Z]{3}$')
```

Production is Percona **5.7.23**, which parses `CHECK` and discards it
(`docs/finance/check-constraints-on-mysql-5-7.md`). The migration's four triggers cover the
status and outcome enum domains — **none of them backs this constraint.** So on production the only
guard is `Money`'s constructor, in application code.

**I asserted the opposite when I told the implementer this constraint was "backed by a trigger,
matching 2026_08_01_120000", and used that as the reason it belonged in the allowlist. The reason
was false; the implementer checked and said so.** The constraint may still belong there, but not for
the reason given, and the docblock now states the true position.

## Why it was not fixed immediately

Three reasons, all still current at the time of writing:

1. **Nothing writes those tables through a path that bypasses `Money`.** The exposure is a raw
   insert or a future writer, not today's code.
2. It is **consistent with ten existing columns** from `2026_08_01_120000`, which carry the same
   inert CHECK. Converting one and leaving ten is worse than converting none.
3. The tables were already merged, so fixing it means a **second migration in cutover week** —
   which open-findings Finding 0 warns is the shape that goes wrong, and which had already been
   conceded once.

The selection-and-report commit carried no migration, so it could not absorb the change either.

## What closes it

- [ ] The next commit touching these tables that ALREADY carries a migration converts it to a
      trigger, at no marginal cost.
- [ ] Decide separately whether the ten siblings from `2026_08_01_120000` follow. Converting one
      and leaving ten produces a set where the reader cannot tell which guards are real — worse
      than a uniformly inert set that everybody knows is inert.

**Do not close this by deleting the CHECK.** It is live on 8.0 and on every developer machine, where
it catches exactly the mistake it names.
