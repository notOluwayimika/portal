# 0038 — Money column naming: `{name}_minor` + `{name}_currency`

**Status:** Accepted (no money columns exist yet; binds all Ph2+ schema)

## Context

`MoneyCast` takes its two column names as cast arguments, so schema naming is a
convention that must be fixed before the first Finance migration. The spec uses
`amount_minor` (§12.9) and pairs every amount with a currency column (§12.1,
§12.2). A `_kobo` suffix would hardcode NGN into the schema **beside an
explicit currency column** — self-contradictory the day the currency column
holds anything else.

## Decision

Every stored money value is two columns:

- `{name}_minor` — `bigint`, integer minor units
- `{name}_currency` — `char(3)`, ISO 4217

wired as `MoneyCast::class.':{name}_minor,{name}_currency'`. Not `_kobo`; not a
bare `{name}`; never a lone amount column with an implied currency.

## Consequences

- Ledger/document schemas (Ph2+) inherit the naming mechanically; the wire key
  (`amount_minor`, ADR 0037) matches the column suffix.
- Known deviation to align opportunistically: the MoneyCast **test probe
  fixtures** predate this ADR and use `balance_kobo` for their throwaway
  tables. Fixture-only (no real schema exists); rename when those tests are
  next touched.
- `MoneyCast`'s built-in defaults (`amount`/`currency`) are generic fallbacks;
  real migrations always pass explicit `{name}_minor`/`{name}_currency` args.
