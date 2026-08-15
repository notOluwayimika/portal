# TICKET — `GET /v1/finance/discount-policies` returns every School's policies to a `super_admin` holding no School context

**Status:** open, not implemented. Raised by the cold review of
`fix/u8-reduction-guard-field-errors` (U8 commit 3) and measured while closing that branch's own
findings. Not fixed there: this is a read-isolation change with a platform-wide blast radius, and that
branch is a validation pre-check plus its tests.

**This is the READ half of an already-ticketed root cause.** The mechanism — `DiscountPolicy` is not on
`config/rbac.php`'s `fail_closed_models`, so `SchoolScope` adds no predicate when there is no active
School — is documented in
[`fee-item-and-discount-policy-not-fail-closed.md`](fee-item-and-discount-policy-not-fail-closed.md),
which raised it for the WRITE path (uuid → id resolution at `POST /v1/finance/invoices`). That ticket's
analysis is not restated here. What is new is that the same gap is reachable on a plain GET that
returns rows, where the consequence is disclosure rather than a resolution that is refused a layer
later. See also [`fail-closed-catalog-batch.md`](fail-closed-catalog-batch.md).

## Measured

Seeded two Schools — `school#1` with two policies, `school#2` with one. A `super_admin` with **no**
`school_id` in session (no `withSession(['school_id' => …])`, which is the condition under test):

```
GET /api/v1/finance/discount-policies
STATUS: 200
ROWS RETURNED: 3
SCHOOLS SEEDED: school#1 (2 policies), school#2 (1 policy)
FIELDS ON ROW 0: id,name,description,basis,value_minor,value_currency,percent,requires_approval,status
```

Three rows for a principal in no School — every policy in the table, across both. Each row carries the
policy's **name**, **description**, **value/percent**, **status** and **requires_approval**.

## Why this principal reaches it

- `routes/api.php:237` wraps the finance endpoints in `permission:finance.access`, and
  `routes/endpoints/finance.php:134` adds **no** further permission to this route — `finance.access`
  alone reads the whole catalog.
- `SetSchoolContext` redirects a principal with no context **only if** they are not a `super_admin`, so
  a contextless `super_admin` is the one principal who can reach a finance read with
  `ActiveSchool::id() === null`.
- `SchoolScope`'s null-context branch throws only for models on `fail_closed_models`.
  `config/rbac.php:156-170` lists ten, all transactional; `DiscountPolicy` is not among them. So the
  query runs unscoped and returns the table.

This does not breach ADR 0036 as written — `super_admin` bypasses *authorization*, never *isolation* —
but isolation here is doing nothing to bypass: there is no School to scope to, and the scope silently
degrades to no predicate rather than refusing.

## `fail_closed_models` is deployment configuration, not code

`config/rbac.php:156-170` reads the list from the environment:

```php
'fail_closed_models' => array_values(array_filter(array_map(
    'trim',
    explode(',', trim((string) env('RBAC_FAIL_CLOSED_MODELS', '')) ?: implode(',', [
        LedgerTransaction::class, Payment::class, /* … eight more … */
    ])),
))),
```

Two consequences worth stating before anyone reasons from the source list:

1. **Whether `DiscountPolicy` fails closed is a deployment fact, not a repository fact.** A reader
   checking the array literal is reading the *default*, not necessarily what any given environment
   runs. Any assertion about fail-closed behaviour has to name the environment it was measured in —
   this one was measured with the variable unset.
2. **The env value REPLACES the default list; it does not extend it.** `env(…) ?: implode(…, [defaults])`
   evaluates the defaults only when the variable is empty. So setting `RBAC_FAIL_CLOSED_MODELS` to add
   `DiscountPolicy` silently drops all ten transactional models unless every one is re-listed. That is
   a foot-gun for whoever fixes this by configuration rather than by code, and it is the reason a
   config-only fix should not be the recommendation.

## What a fix has to decide

Not decided here, because the choice has consequences past this route:

- Add `DiscountPolicy` (and `FeeItem` — see the cross-referenced ticket) to the default list, making a
  contextless read **throw** rather than return rows. Platform-wide read-behaviour change; that is
  exactly the blast radius the other ticket declined to take on inside a wire-format commit.
- Or scope this controller explicitly, which reintroduces the hand-rolled `where('school_id', …)` that
  Constitution §5 exists to avoid, and fixes one route out of however many have the same shape.

The second option is why this is filed as its own ticket rather than a one-line patch: the route is an
instance, `fail_closed_models` is the cause, and fixing the instance hides the cause.

## Not verified

- Only the `super_admin`-with-no-context path was measured. Whether any other principal can reach a
  finance read with `ActiveSchool::id() === null` was not established.
- The other finance catalog reads (`fee-schedules`, `fee-items`, `bank-accounts`) were not measured;
  they plausibly share the shape, and `fail-closed-catalog-batch.md` is where that sweep belongs.
- Measured on the local test database with `RBAC_FAIL_CLOSED_MODELS` unset. No production environment
  was inspected.
