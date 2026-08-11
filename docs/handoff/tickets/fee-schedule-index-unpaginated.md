# `GET /v1/finance/fee-schedules` is unpaginated

**Raised by:** U1 commit 1 (the fee-schedules data surface), which added the two filters below and
deliberately stopped there.

## What is true today

`FeeScheduleController::index()` returns every schedule for the School with its items; the term
filter bounds it for the screen but a caller passing no term still gets everything.

U1 commit 1 added two optional query filters — `term_id` (School-scoped `exists`) and `status`
(`Rule::enum(FeeScheduleStatus::class)`) — applied with `->when()`, so absent means unfiltered and
nothing that called the endpoint before it changed. That is enough for the fee-schedules screen,
which always names a term. It is not a bound on the endpoint.

Each row carries its items, and now also each item's bank account, so the response grows with
(schedules × items), not with schedules.

## Why it was not fixed here

Pagination is a contract change with a shape decision behind it — page size, cursor vs offset, and
what the screen does with it — and commit 1 has no screen to answer those against. Adding a paginated
envelope with no caller would be a primitive ahead of its consumer, and it would silently break the
current callers' array shape.

## Revisit when

A school has more than one year of schedules. In September this is a handful of rows; the row count
is (terms × class levels × versions) per year, per school, and it only ever grows — no schedule is
deleted, superseded and retired ones stay.

Related and adjacent, filed separately:
[opening-balance-index-hydrates-every-row.md](opening-balance-index-hydrates-every-row.md).
