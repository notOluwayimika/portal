# TICKET — `BINARY expr` is deprecated, and seven merged triggers still use it

**Status:** open, not implemented. Raised while fixing the payment-axis allocation guard on
`feat/allocation-payment-axis-guard` (2026-08-21), where cold review caught the same form. The
payment-axis trigger has **already moved** to `CAST(… AS BINARY)` — see
`database/migrations/2026_08_21_110000_finance_allocation_not_over_payment_amount.php`. The rest
have not, deliberately: a merged trigger on a money table gets its own migration and its own
proof, not a drive-by edit inside a branch about something else.

**Severity:** ticket, not stop. Nothing is broken today. `BINARY expr` still works on 8.0.43 and
still works on 5.7; what it does is warn, and warn about its own removal.

## The finding

MySQL 8.0 deprecates the prefix form `BINARY expr`:

```
Warning 1287: 'BINARY expr' is deprecated and will be removed in a future release. Please use CAST instead
```

`CAST(expr AS BINARY)` is MySQL's own documented replacement. It is **valid on 5.7**, so a
migration can move to it without splitting behaviour between the local 8.0.43 and the 5.7.23-23
production server — which is the constraint that governs every trigger decision in this
repository (`docs/finance/check-constraints-on-mysql-5-7.md`).

## When the warning actually fires — measured, because the first statement of it was wrong

The review that surfaced this described the warning as firing "twice per insert". It does not.
Measured on 8.0.43 against a scratch table, with emulated prepares on so `SHOW WARNINGS` is
reachable at all (over the binary protocol it answers 1295 instead of the warning list):

```
body using `BINARY expr`        CREATE TRIGGER: 2 warnings (1287)   INSERT: 0
body using `CAST(… AS BINARY)`  CREATE TRIGGER: 0                   INSERT: 0
```

The warnings are raised when the trigger body is **parsed**, at `CREATE TRIGGER` — twice, once
per operand — and not on each insert. So the operational cost today is a warning per migration
run, not per write. That makes this smaller than it first looked, and it is recorded here rather
than quietly dropped because the corrected number is what a future reader will plan against.

**It also means an arm that inserts a row and reads `SHOW WARNINGS` cannot see this.** One was
written that way on the branch above and stayed green with the deprecated form in place. The arm
that works re-creates the stored body under a scratch name and reads the warnings from the
`CREATE` (`tests/Feature/Finance/PaymentAxisGuardTest.php`, PROOF g1).

## Scope — measured across the schema, not assumed to be one trigger

Derived from `information_schema.TRIGGERS` on a freshly-migrated database, comments stripped,
counting `BINARY` not preceded by `AS `:

| Trigger | Occurrences |
| --- | --- |
| `finance_invoice_lines_reduction_guard` | 6 |
| `finance_credit_notes_insert_guard` | 4 |
| `finance_credit_notes_update_guard` | 2 |
| `finance_fee_items_parent_state_guard_ins` | 2 |
| `finance_fee_items_parent_state_guard_upd` | 2 |
| `finance_fee_items_parent_state_guard_del` | 2 |
| `finance_allocation_not_over_invoice_total` | 2 |

**7 triggers, 20 occurrences, out of 61 triggers in the schema.** The July allocation guard named
in this ticket's filename is one of seven, and it is not the largest. Re-derive this table before
acting on it — it was true on 2026-08-21 and the payment-axis trigger had already left it.

## Why it is not a shrug

`BINARY` is not decoration in any of these. It is there because a routine variable takes the
**connection** collation while a column takes the **table** collation, and where those disagree a
plain `<>` raises `1267 Illegal mix of collations` on **every** write — matching or not — turning
a guard into a total outage. That was found the hard way, on a freshly-created database whose
default collation differed from the dev database (`2026_07_22_120000` docblock).

So the substitution has to preserve two properties, not one, and both were measured on 8.0.43
before the payment-axis trigger moved:

```
comparing 'NGN' COLLATE utf8mb4_general_ci with 'NGN' COLLATE utf8mb4_unicode_ci
  plain <>            ERROR 1267 Illegal mix of collations ... for operation '<>'
  BINARY expr         OK, equal, 2 warnings (1287 x2)
  CAST(… AS BINARY)   OK, equal, 0 warnings
and CAST still discriminates: 'NGN' <> 'USD' is 1, 'NGN' <> 'ngn' is 1
```

Collation-agnostic, and still a case-sensitive byte comparison. Equivalent on both counts.

## What a fix would be

One migration per trigger, or one migration covering the six remaining finance triggers, each
`DROP TRIGGER IF EXISTS` + `CREATE TRIGGER` with the identical body and `BINARY x` replaced by
`CAST(x AS BINARY)`. Each needs, at minimum:

1. the body read back from `information_schema` and asserted, not the migration's exit code
   (ADR 0052);
2. a `CREATE`-time warning arm proving 1287 is gone — an insert-time arm cannot see it;
3. proof that each guard still refuses what it refused, since the comparison being changed is
   the one doing the refusing;
4. a `CREATE`-time collation arm, or an explicit note that the 1267 property is being taken from
   the measurement above rather than re-measured.

Not urgent, and not to be batched into a branch about something else.

## Not the fix

Removing `BINARY`/`CAST` and comparing plainly. That is the 1267 outage, and it is the reason the
form is there.
