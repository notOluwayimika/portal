# `route-middleware-baseline.json` is 67 routes stale on `staging`

**Raised:** 2026-08-31 · **From:** `feat/paystack-webhook` · **Severity:** ticket

> **Re-measured 2026-09-05** (`fix/return-route-in-both-route-oracles`): the backlog is **70**, not
> 67 — 437 registered routes against 367 fixture keys, reconciling exactly, with zero fixture keys
> that are not registered. That branch added three rows and left the rest; the figure had drifted
> upward before it did. **The 67 in the title is a point-in-time number and the file is not renamed
> for it — re-derive rather than deriving from either figure.**

## What

`php artisan rbac:derive-map` on `staging` + this branch produces **68 additions and zero
removals**. Exactly **one** is this branch's (`POST /api/webhooks/paystack`). The other **67** are
routes that already exist on `staging` and have never been snapshotted — `GET /academics/rollover`,
the `class-levels/{classLevel}/arm-map` family, `GET /api/curricula/queued`, and others.

## Why nothing has failed

`RouteMiddlewareBaselineTest` reds only on a route that is **both** absent from the fixture **and**
carrying no middleware starting `auth`. All 67 are authenticated, so they slip through the one
condition that would have surfaced them. The fixture is therefore not what it claims to be — a
snapshot of every route's gathered stack — and has not been for some time.

**What it costs:** the fixture exists to catch a *reorder* or a *membership change* in a
middleware stack (ADR 0043 §3). It cannot catch either for a route it does not contain. So 67
routes, including admin-area and academic-setup ones, currently have no ordering oracle at all.

## Why this branch did not fix it

Regenerating sweeps all 68 into one commit, where this branch's change is 1 line and the review
burden is 268. That is the same shape as `pint <directory>` sweeping unrelated files — twice
recorded in CLAUDE.md — and it hides the one entry that actually needed a human decision (an
*unauthenticated* money endpoint joining the register) inside 67 that did not.

So this branch registered **only its own route**, as a 4-line diff, in the exact shape
`rbac:derive-map` produces — so a later full regeneration is a no-op for that entry.

## The fix

Run `rbac:derive-map` on its own branch, review the 67 additions **as a batch that nobody has
reviewed** — they have never been through this oracle, so this is their first pass, not a
re-confirmation — and commit them alone.

## The general finding underneath

A drift-detecting fixture whose test only fires on a *subset* of its content accumulates unchecked
entries silently, and every later regeneration mixes real changes with backlog. The detector's
scope is narrower than the fixture's scope, and nothing states the difference. Worth deciding
whether the test should also red on **any** absent route, which would make the fixture
self-maintaining and would have surfaced these 67 the day each landed.
