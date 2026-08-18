# `TriggerBodiesAreDumpSafeTest` passes a trigger body that will not re-import

**Raised** 2026-08-18, on `feat/supplementary-invoices`. Found while writing
`2026_08_18_100000`; confirmed independently by two cold reviews, the second of which bite-proved it
at `HEAD`.
**Scope** `tests/Feature/Finance/TriggerBodiesAreDumpSafeTest.php` — the oracle, not any shipped
trigger. Every trigger currently in the schema is clean.
**Severity** ticket. Nothing in the tree is broken today; the guard that is supposed to keep it that
way reports green on a class of body it was written to catch.

## Background — why the oracle exists

MySQL accepts an escaped apostrophe in a `SIGNAL … SET MESSAGE_TEXT` at `CREATE TRIGGER` time and
then **stores the body with the escape stripped**. The trigger works in place. `mysqldump`,
phpMyAdmin's copy and every other reader then emit invalid SQL. This is not hypothetical here: a
database copy failed with `#1064 … near 's terms are immutable` long after the migration that
introduced it had run green. The test's own docblock records that, and records that **neither**
escape survives — the SQL-standard doubled quote `''` is stored stripped exactly as the backslash
form is.

## The hole

The invariant the test actually enforces (`TriggerBodiesAreDumpSafeTest.php:49`) is:

```php
if (substr_count((string) $sql, "'") % 2 !== 0) {
    $broken[] = $trigger->TRIGGER_NAME;
}
```

**An ODD count of single quotes.** That catches a possessive — one stripped escape leaves an odd
number. It does not catch an EVEN number of stripped escapes, and quoting a pair of enum values in a
message produces exactly that.

**Balance is necessary and not sufficient.** A body can be perfectly balanced and still be a
sequence of adjacent string literals with bare words between them, which is not valid SQL.

## Bite-proof, run at `HEAD` on 2026-08-18

The domain trigger's message was temporarily reverted to the quoted-values form that the first draft
of `2026_08_18_100000` shipped, and the database migrated fresh:

```
-  'finance_invoices.kind must be exactly scheduled or supplementary (lowercase, exact).'
+  'finance_invoices.kind must be exactly ''scheduled'' or ''supplementary''.'
```

The oracle's verdict:

```
{"tool":"pest","result":"passed","tests":1,"passed":1,"assertions":2}
```

What is stored, read back from `information_schema.TRIGGERS`:

```
quote count: 12 (even = oracle passes)
'finance_invoices.kind must be exactly 'scheduled' or 'supplementary'.';
```

Twelve quotes, balanced, green — and that `MESSAGE_TEXT` line is not valid SQL. A dump containing it
fails to restore in exactly the way the `#1064` incident did. The message was restored to the
quote-free form immediately afterwards; **no shipped trigger carries this shape.**

For contrast, the same commit's immutability message originally carried a possessive (`episode''s`),
left five quotes, and the oracle **did** flag it:

```
trigger body has an unterminated string literal (usually an apostrophe in a SIGNAL message,
which MySQL stores unescaped): finance_invoices_kind_immutable
```

So the oracle catches the odd case and misses the even one, and the two arrived in the same commit —
which is what makes this a gap in the check rather than a gap in anyone's care.

## Why it was not fixed in the commit that found it

`TriggerBodiesAreDumpSafeTest` spans **every** trigger in the schema, not just Finance's. A stricter
check may well flag pre-existing bodies, and each one then needs its message re-worded, re-measured
against the 128-character `MESSAGE_TEXT` cap, and re-proven. That is its own change with its own
blast radius and its own proof obligations, and it should not ride in on a supplementary-invoicing
commit. Both cold reviews independently agreed with deferring it.

## Options, none costed

- **Forbid the quote character outright in a `MESSAGE_TEXT` literal.** Parse `ACTION_STATEMENT` for
  `MESSAGE_TEXT = '…'` and fail if the literal's interior contains `'` at all. Directly expresses
  the rule the docblock already states in prose ("an apostrophe cannot be carried in a trigger
  message at all"), and is the narrowest thing that closes this. Needs a tolerant enough parse for
  bodies with several `SIGNAL`s.
- **Round-trip the body for real.** `CREATE` each stored body into a scratch schema and assert it
  parses. Strongest — it tests the actual property (restorability) rather than a proxy — and the
  most machinery: a scratch schema, `DDL commits implicitly` cleanup in a `finally`, and the trigger
  names collide unless the scratch tables are renamed.
- **Keep the balance check and add a bare-word heuristic.** Flag a body where a `MESSAGE_TEXT`
  literal contains `' ` followed by a word character. Cheap, and a heuristic — it would have caught
  this one.

The first option is the recommendation: it matches the rule as already written, and the rule is
absolute, so it needs no heuristic.

## Related

- `docs/finance/check-constraints-on-mysql-5-7.md` — why this schema uses triggers rather than
  `CHECK`, and therefore why trigger-body integrity carries the weight it does.
- `docs/handoff/tickets/aborted-migration-leaves-schema-changed-and-unrecorded.md` — the other
  standing migration-mechanics gap raised on this branch.
