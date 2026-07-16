# 0002 — Money as integer minor units + explicit currency

**Status:** Accepted · implemented (M1.4a: `app/Support/Money.php`, `app/Casts/MoneyCast.php`, `formatNaira()`)

## Context

Constitution rule 10: money is never a float and never a `decimal:N` cast
(Laravel's `decimal` cast is `number_format` — a string formatter, not
arithmetic-safe). The Finance module (Ph2+) is built on an exact money
primitive that must exist, tested, before any Finance schema.

## Decision

- `App\Support\Money` is an immutable, final value object holding **integer
  minor units** (kobo) + an **explicit ISO 4217 currency**, default `NGN`.
  The currency field is not multi-currency support; it is the invariant that
  makes cross-currency arithmetic impossible by construction — `plus()`,
  `minus()` and `equals()` throw on a currency mismatch. NGN is the only value
  written.
- `fromNaira()` accepts an integer or a string with **at most two decimals**
  and **rejects** more precision rather than rounding.
- The only multiplication is **`times(int)`** — exact integer scaling
  (quantity × unit price). **No scalar/percentage multiplication and no
  division exist**, because they require a rounding policy (banker's vs
  half-up; who absorbs the remainder when splitting across siblings) that must
  be **co-signed by Brookstone Finance before the first Finance migration**
  (v10 §12.3). `accounting-policy.md` is unsigned; adding a rounding-bearing
  operation before it is signed is a Constitution violation, not a feature.
  This is a hard boundary, not a TODO.
- `App\Casts\MoneyCast` maps a Money to two storage columns (amount +
  currency; names as cast arguments). Its `get()` distinguishes three cases:
  column not selected → query-construction error; both columns NULL → null;
  exactly one NULL → data-integrity error. It never returns null on a partial
  select and never defaults a currency.

## Consequences

- Round-trips are exact end-to-end; the boundary-lint `decimal-money-cast`
  rule fails CI on any `decimal:` cast on a money-named attribute, app-wide.
- Percentage discounts/allocations (Ph4+) are blocked on the signed rounding
  policy — by design.
- Wire shape and column naming are fixed by ADRs 0037 and 0038.
