# 0037 — Money wire contract

**Status:** Accepted · implemented (M1.4a: `Money implements Arrayable, JsonSerializable`)

## Context

Without a canonical serialization on the VO, the first Phase-2 API Resource
would set the JSON shape by accident, and each consumer could invent its own.
A bare `"amount"` key invites the minor-vs-major misreading (`123456` read as
₦123,456 instead of ₦1,234.56). The spec's vocabulary for a standalone money
amount is `amount_minor` (v10 §12.9).

## Decision

The canonical wire shape, implemented once on the VO via
`toArray()`/`jsonSerialize()`:

```json
{ "amount_minor": 123456, "currency": "NGN" }
```

- `amount_minor` is an **integer** in minor units (kobo) — never a decimal,
  never a string, never naira-major.
- Display divides by 100; formatting is the frontend's job (a shared frontend
  `formatNaira` mirroring `formatNaira()` in `app/Helpers/Helper.php` is a
  recorded follow-up — today only ad-hoc `toLocaleString` rendering exists).

## Consequences

- Every `response()->json()` / API Resource that serializes a `Money` produces
  this shape by default; consumers cannot silently diverge.
- The key name is a permanent contract: renaming after Ph2 consumers exist
  would be a breaking wire change (it was renamed from `amount` while there
  were zero consumers, for exactly this reason).
