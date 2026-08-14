# TICKET — 75 assertions accept ANY database error as proof of a specific guard

**Status:** open, not implemented. Raised by `feat/u8-wire-ids-uuid` after one instance of this shape
was found to be unfalsifiable; the sweep is the generalisation, and fixing 75 assertions across 26
files is not a wire-format change.

**Root:** `expect(fn () => …)->toThrow(QueryException::class)` is satisfied by *every* database error.
A syntax error, an unknown column, a missing table, a nullability violation, a deadlock — all of them
pass an assertion whose test name says "the trigger refuses this".

## The count, re-derived

Run against the branch tip:

```bash
grep -rn "toThrow(QueryException::class)" tests/ | wc -l     # 75   ← the bare form
grep -rln "toThrow(QueryException::class)" tests/ | wc -l    # 26   files
grep -rn "toThrow(QueryException" tests/ | wc -l             # 85   all forms
grep -rln "toThrow(QueryException" tests/ | wc -l            # 27   files
```

The 10-assertion difference is a single file, `tests/Feature/Rbac/MakerCheckerSeparationTest.php`,
where every call passes the constraint name as the second argument:

```php
->toThrow(QueryException::class, 'finance_credit_notes_maker_ne_checker');
```

That form is not affected by this ticket. It names the constraint, so an unrelated error fails it. It
is also the cheapest available fix for most of the 75, and this file is the in-repo precedent for it.

Heaviest concentrations (`grep -rc … | sort -rn`), for anyone scoping a first pass:

| File | Bare assertions |
|---|---|
| `tests/Feature/Finance/FinanceApiAcceptanceTest.php` | 9 |
| `tests/Feature/Finance/SchemaConventionsTest.php` | 7 |
| `tests/Feature/Finance/FeeScheduleChangeTest.php` | 7 |
| `tests/Feature/Isolation/EnrollmentSchoolIntegrityTest.php` | 6 |
| `tests/Feature/Sequences/IdentifierGeneratorTest.php` | 4 |
| `tests/Feature/Finance/WalkingSkeletonTest.php` | 4 |
| `tests/Feature/Finance/CreditNoteTest.php` | 4 |

**These are 75 assertions that CAN be vacuous, not 75 that ARE.** Each needs reading. The count is a
work estimate; treating it as a defect count would be the same mistake
`PestNegatedExpectationMessagesTest`'s docblock warns about at length — a property of the
discriminator read as a fact about the tree.

## The worked example that started this

`tests/Feature/Finance/ReductionEnforcementTest.php:134-149`, `proof 12 (DB)`. Its raw insert carries a
`bank_account_id` key; `finance_invoice_lines` has no such column, so MySQL rejects the statement at
**1054** before firing any trigger. The assertion passes. It passes just as well with a policy the
guard is supposed to ACCEPT — measured by swapping one in — so it is insensitive to the condition its
name describes.

Full write-up, including the migration evidence that the column's absence is deliberate:
`docs/handoff/tickets/reduction-guard-proof-12-db-is-vacuous.md`.

## The shape a fix takes

`proof 14 (DB)` in the same file, added by U8 commit 1. It catches the exception, reads
`errorInfo[1]`, and requires **1644** — the driver code MySQL reports for the `SIGNAL SQLSTATE '45000'`
that every finance guard raises — plus a fragment of the guard's own message:

```php
} catch (QueryException $e) {
    $code = (int) ($e->errorInfo[1] ?? 0);
    $message = (string) ($e->errorInfo[2] ?? '');
}

expect($code)->toBe(1644, '…')->and($message)->toContain('another School');
```

Bite-proved by substituting a row the guard should accept, which reds it on
`Failed asserting that null is identical to 1644`.

Which code to require depends on what the arm is about, and getting that wrong reintroduces the
problem in a new shape. From `docs/` and verified in this repo's own tests: **1644** for a trigger
`SIGNAL`, **1062** for a unique violation, **1451/1452** for foreign keys, **3819** for a CHECK,
**1048** for NOT NULL. An arm about an append-only trigger and an arm about a unique index need
different numbers and neither should accept the other's.

## Scoping a first pass

The Finance immutability and guard arms are where a false green costs the most, because that is where
the assertion is standing in for a money invariant. `SchemaConventionsTest` and
`EnrollmentSchoolIntegrityTest` are the two largest non-Finance blocks. Nothing here needs doing in one
commit; a file at a time, each with a bite-proof, is the point.
