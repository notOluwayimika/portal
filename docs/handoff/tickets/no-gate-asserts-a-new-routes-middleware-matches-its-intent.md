# Nothing asserts that a NEW route carries the gate its author intended

**Status:** open · **Opened:** 2026-09-04 · **Kind:** known bound, not a discovery

**This records a bound that is already documented in the instruments themselves.** Every mechanism
below behaves exactly as its own docblock says it does. Nothing here is a bug; the point is that the
UNION of them leaves a specific question unanswered, and the union is not written down anywhere.

## The question nobody's gate answers

> A route is added with `->middleware('permission:X')`. Does anything fail if the author writes the
> wrong `X`, or omits the middleware and inherits the enclosing group's?

No. Three instruments come close and each stops short for a stated reason.

## 1. `RouteAccessParityTest` checks only routes already in the fixture — **by design**

Its own docblock:

> `tests/fixtures/route-access-map.json` was derived and committed BEFORE the swap (from the
> `role:` groups); this test re-derives the same map from the live `permission:`-based routes plus
> the … fixture is regenerated (`php artisan rbac:derive-access` against a synced …

and the comparison is `foreach ($fixture as $key => $expected)`. **A route the application has and
the fixture does not is invisible to it.** Measured on 2026-09-04: the file is **17/17 green** with
a newly added route absent from the map.

**That is correct behaviour, not a defect.** The fixture is a **pre-swap ORACLE** — its value is
that it was written down before the change it guards. Regenerating it wholesale to admit one new
route folds in every other route the fixture is currently missing (**~59** at the time of writing,
zero deletions — stale by omission, not wrong) and converts a drift detector into a mirror of
current state. A mirror cannot detect drift; it *is* the drift.

That is why the return-endpoint commit left the map untouched and said so in its message.

## 2. `bin/ci-authz-lint.php` never opens `routes/`

It matches **commented-out guards** — an authorization check that was disabled rather than removed.
Route middleware is not in its scope and it reads no route file. Different defect.

## 3. `nothing-detects-a-check-that-belongs-to-another-route.md` is about a different layer

That ticket covers `Authz::abilityCheck` sites whose ability belongs to a neighbouring route — an
in-controller concern. **Cross-referenced here because the two read as the same gap and are not:**
one is about a check inside a handler, this one is about the declaration in front of it. Closing
either leaves the other open.

## What the return endpoint did instead, and it is the pattern to repeat

`routes/endpoints/internal-audit.php` is required inside a group gated on
`permission:finance.invoice.approve`. A return route added there with no middleware of its own would
have been gated on **the permission for the other verb** — and it would have passed every test,
because `internal_auditor` holds both.

That was closed **behaviourally**, not by a fixture entry:

- a live arm posting as a seat holding `approve` and **not** `reject` — **403** on the return route
  and **200** on the feed, both halves, because 403 alone would also be produced by the group
  refusing that seat outright; and
- a **mutation** behind it: dropping `->middleware('permission:finance.invoice.reject')` reds that
  arm with `Expected response status code [403] but received 422`.

**Behaviour is stronger than a fixture entry.** A map row asserts a derived role set; the arm
asserts the server actually refuses, and the mutation proves the arm can fail. **Until a gate
exists, every new gated route owes those two things** — an arm that exercises the seat the gate is
meant to exclude, and a mutation showing the arm reds when the middleware is removed.

The seat may not exist in the grants map, and that is not a reason to skip the arm: for the return
route no seeded role holds `approve` without `reject`, so the arm constructs one. That is a
statement about the map being correct, not about the fixture being loose.

## What a real gate would have to do

Not designed here, and deliberately not sketched as though it were: the hard part is not reading the
middleware off a route, it is knowing what the author INTENDED, and every cheap answer to that
(a naming convention, the controller's namespace, the file the route lives in) is a heuristic that
would need its own exemption list on day one. Any proposal must say what it does with a route whose
correct gate genuinely differs from its neighbours' — which is exactly the case that motivated this
ticket.
