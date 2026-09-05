# Fifty-four routes are missing from the access map, and the parity test cannot see them

**Status:** open · **Opened:** 2026-09-05 · **Found by:** the returned-bills-queue commit
(`feat/finance-returned-bills-queue`), which needed to add two routes to the map and discovered what
else was absent · **Severity:** ticket — nothing is broken today; 54 access sets are simply
unreviewed

## What is true

`tests/fixtures/route-access-map.json` is the access oracle: for every route, which roles may reach
it. Regenerating it with `php artisan rbac:derive-access` against a freshly migrated and synced
database adds **54 routes**, removes none, and changes none.

Re-derived on `ca8dbc45`, not carried:

```
committed fixture   383 keys
regenerated         437 keys
ADDED (the drift)    54
REMOVED               0
CHANGED               0
```

By method: **GET 31 · POST 17 · PUT 3 · DELETE 2 · PATCH 1.** One of the 54 is
**unauthenticated** — `POST /api/webhooks/paystack`.

The drift is not obscure corners. It includes the whole bulk-invoice-run surface, the whole
manual-invoice-run surface, the parent portal's payment routes (`POST
/api/parent/invoices/{invoice}/payment`, `…/payment/preview`, `GET /api/parent/finance/wards`), the
rollover endpoints, and the student bulk-reassign and export routes.

## The cause is structural, deliberate, and stated in the test itself

`tests/Feature/Rbac/RouteAccessParityTest.php:18-22`:

> Deliberate asymmetry (same as RouteMiddlewareBaselineTest): only fixture
> routes are asserted, so NEW routes — Finance additions included — are never
> blocked here. An access change to an EXISTING route stays red until the
> fixture is regenerated (`php artisan rbac:derive-access` against a synced
> DB) as an explicit, reviewed diff.

The loop is over the **fixture**, not over the router — `tests/Feature/Rbac/RouteAccessParityTest.php:45` `foreach ($fixture as $key => $expected)`,
and the only absence it can report is `tests/Feature/Rbac/RouteAccessParityTest.php:47` `"REMOVED/RENAMED: {$key}"`, a route the fixture has and
the app lacks. **The opposite direction — a route the app has and the fixture lacks — is not a case
the loop has.**

That was a reasoned choice and it was the right one for what it was written for: it stops a route
addition from reddening a test about the `role:` → `permission:` swap.

## The consequence the design did not anticipate

**The asymmetry has no ratchet, so the gap only grows, and it is now 54.**

The docblock's phrase is *"never blocked here"* — which is true, and reads as "a new route is not
this test's business". What it also means, three years of routes later, is that **the oracle
describes 383 of 437 routes and says nothing about the other 54.** Each of those is an access set
that nobody reviewed as a diff, because the mechanism for reviewing it — a red test, then an
explicit regeneration — never fires for an added route.

Two of them stopped being drift on 2026-09-05, when `feat/finance-returned-bills-queue` added its
own two by hand. That is the only way an added route has ever entered this file, and it depends on
the author knowing to do it.

**Environment:** every environment; it is a test-fixture gap, so it bites at review time rather than
at runtime. Nothing is currently mis-gated — the 54 routes each carry whatever middleware they carry,
and the map's absence does not change that. What is missing is the record that anyone agreed to it.

## Read this together with THREE siblings, and the numbered twin is not the one you would guess

An earlier draft of this ticket named only
`docs/handoff/tickets/no-gate-asserts-a-new-routes-middleware-matches-its-intent.md` and called this
ticket "that ticket with a number attached". **That is the wrong pairing.** That ticket asks a
different question — whether a new route's gate matches what its author INTENDED — and it carries no
count. Four tickets now describe one family:

| ticket | what it measures |
| --- | --- |
| **this one** | `route-access-map.json` is **54** routes short. The ROLE SET each route resolves to is unreviewed. |
| `docs/handoff/tickets/route-middleware-baseline-is-67-routes-stale.md` | **the numbered twin.** `route-middleware-baseline.json` is **67** routes short — `rbac:derive-map` produces 68 additions and zero removals, exactly one of them that branch's own. Raised 2026-08-31 from `feat/paystack-webhook`. The MIDDLEWARE STACK each route carries is unreviewed. |
| `docs/handoff/tickets/duplicate-check-route-is-in-neither-route-oracle.md` | the intersection with **n = 1**: `GET /api/guardians/duplicate-check` absent from BOTH oracles. It quotes the same `RouteAccessParityTest` docblock this ticket quotes. |
| `no-gate-asserts-a-new-routes-middleware-matches-its-intent.md` | a different axis: nothing checks a new route's gate against its author's intent. Belongs in the family; is not the numbered twin. |

**The 67-stale ticket is this ticket's mirror image and it got there first.** It reports the same
zero-removals shape, makes the same argument against wholesale regeneration citing the same
`pint <directory>` precedent (`docs/handoff/tickets/route-middleware-baseline-is-67-routes-stale.md:26`), resolves it the
same way — register only the branch's own route — and lists the same remedy this one lists, *"whether
the test should also red on **any** absent route"* (`docs/handoff/tickets/route-middleware-baseline-is-67-routes-stale.md:44`). Its single registered route is
`POST /api/webhooks/paystack`, which is also the one unauthenticated member of this ticket's 54.

**Why that matters for scheduling.** The 54 and the 67 are not two backlogs. They are one command
family — `rbac:derive-access` and `rbac:derive-map` — with one design decision behind both
asymmetries, and reviewing them separately means reading most of the same routes twice and deciding
the same question twice. Whoever picks either up should pick up both.

## RECORD, DO NOT PROPOSE — and specifically, do not just regenerate

The obvious fix is `php artisan rbac:derive-access`, commit the 54. **That is the trap.**

Regenerating wholesale **baptises 54 unreviewed access sets into the oracle in one commit**. The
file's entire value is that it is the reviewed answer; a bulk regeneration converts "nobody has
looked at these" into "these are correct, asserted by a fixture" without anybody having looked. The
test would then be green **about** the 54 while remaining blind to the next one.

This is the same trap that stopped the component move on 2026-09-05: a mechanically correct
regeneration that sweeps a large body of unrelated, unreviewed work into a commit whose subject is
something else — recorded in
`docs/handoff/tickets/hand-written-components-are-exempt-from-the-frontend-gates.md`. The
returned-bills commit therefore added **only its own two** entries and left the other 54, which is
why they are still here to be counted.

Whoever picks this up needs that stated **before** they reach for the obvious fix. What the ticket
does not do is choose between the shapes available — reviewing the 54 in batches by surface, adding
a second arm that reports added-but-absent routes as a **count** to be ratcheted down, or deciding
the asymmetry is correct and the oracle simply covers a subset by design. Each of those is a
different claim about what this fixture is for, and that is a ruling rather than a task.
