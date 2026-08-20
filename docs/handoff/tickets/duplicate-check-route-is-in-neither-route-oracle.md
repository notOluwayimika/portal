# `GET /api/guardians/duplicate-check` is in neither route oracle

**Raised by:** the second cold review of `fix/guardian-create-duplicates` (finding 5),
after the implementing side declared the gap honestly and did not close it.

## The state

```
$ grep -c duplicate-check tests/fixtures/route-access-map.json tests/fixtures/route-middleware-baseline.json
0
0
```

Both oracles are **deliberately asymmetric** and the route is legitimately absent from
both:

- `RouteAccessParityTest` iterates the *fixture's* keys, so a route the fixture does not
  name is never compared. Its own docblock states this: *"only fixture routes are
  asserted, so NEW routes — Finance additions included — are never blocked here."*
- `RouteMiddlewareBaselineTest`'s second arm only rejects new routes carrying **no**
  auth middleware. This route sits inside the
  `auth:sanctum,tenant,permission:academic_setup.manage` group, so it passes freely.

Nothing is broken today, and nothing was bypassed.

## The consequence, which is the point of the ticket

The oracles exist to make an access change a **reviewed diff**. A route absent from both
gets no such treatment: move `duplicate-check` into a different middleware group, or
change the permission on the group it is in, and **neither oracle notices**. It is
exactly as unguarded, in review terms, as it would be if someone deleted its middleware
line — the difference being that the deletion case is caught and the substitution case
is not.

That matters more here than for a typical new route because this endpoint answers an
account-existence question across schools — see the sibling ticket
`duplicate-check-is-a-platform-wide-account-existence-oracle.md`.

## What closing it looks like

Regenerate both fixtures in the documented order — and **only** in that order, because
the first step is destructive in one direction:

1. `php artisan rbac:sync`
2. `php artisan rbac:derive-access`
3. the baselines

Before running step 1, check the catalog diff: `rbac:sync` is non-destructive for grants
and **destructive for permission rows**, hard-deleting every `permissions` row whose
name is not in the enum and taking both pivots with it via `ON DELETE CASCADE`, inside
`activity()->withoutLogs()` — no audit trace. It is safe when the diff is `missing_rows`
only. Any `extra_rows` and it is a destructive operation; see
`docs/runbooks/rbac-grants-reconciliation.md`.

Then review the fixture diff as a diff: the only new key should be
`GET /api/guardians/duplicate-check`, and nothing else in either file should move.
