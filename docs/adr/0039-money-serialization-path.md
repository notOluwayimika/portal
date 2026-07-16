# 0039 — Money crosses the wire only via an API Resource

**Status:** Accepted · behaviour demonstrated by tests (M1.4a: `tests/Feature/Casts/MoneyCastIntegrationTest.php`)

## Context

`MoneyCast` attaches to a **virtual attribute** backed by two real columns
(e.g. `balance` ⇢ `balance_minor` + `balance_currency`). Laravel's
`attributesToArray()` only casts keys present in `$attributes`, so the virtual
money key **never appears** in raw `$model->toArray()` / `json_encode($model)`
— those emit the raw columns (integer + char(3)). Demonstrated output:

```
$model->toArray()   -> {"id":1,"balance_kobo":123456,"balance_currency":"NGN"}
Resource            -> {"data":{"balance":{"amount_minor":123456,"currency":"NGN"}}}
```

## Decision

**Money reaches the wire — including Inertia page props — only through an API
Resource (or equivalent explicit read of the attribute), which serializes via
the VO's canonical shape (ADR 0037). Raw model `toArray()` is never a money
wire format.**

## Consequences

- Ph2 endpoints and Inertia pages must pass money through Resources; passing a
  raw model to a response leaks the raw columns in an undocumented flat shape
  (type-safe — still integers — but not the contract).
- The cast's strict partial-select behaviour (ADR 0002) means a query feeding a
  Resource must select both money columns or omit the attribute entirely.
- No `$appends` convention is introduced; explicitness is the mechanism.
