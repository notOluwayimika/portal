# `rbac:derive-access` emits `roles: []` for a permission with no database row

**Status:** open · **Opened:** 2026-09-05 · **Found by:** `fix/return-route-in-both-route-oracles`,
while generating one access-map row · **Severity:** ticket — the committed oracle catches the bad
row today (proved below), so nothing is currently wrong in the tree. What is wrong is the
instrument: it answers a question it has no data to answer, and its failure message sends the
reader back to it.

## The reproduction

`php artisan rbac:derive-access` derives each route's admitted-role set by intersecting the
holders of every ability its `permission:` middleware names. `App\Support\RouteAccessMap::derive()`
reads those holders from the **connected** database (`RouteAccessMap.php:107 (holders)`), and an
ability with no row there resolves through `$holders[$p] ?? []` — an empty holder set, which
intersects to nothing.

On this machine, `finance.invoice.reject` has no permission row at all. Not granted to nobody —
never created, because `rbac:sync` has not run since the ability was declared. Read-only probe,
under the privacy rule:

| permission | row exists | global web-guard holders |
| --- | --- | --- |
| `finance.invoice.approve` | yes | 1 |
| `finance.invoice.reject` | **no** | 0 |
| `activity_log.view_all` | yes | 3 |

Control: 15 global web-guard roles total, so the query reached a populated table. No migration
creates `finance.invoice.reject` — grep over `database/migrations/` returns 0 files, against a
positive control of 2 files for `activity_log.view_all` and 181 migration files visible to the
same instrument. It arrives only via `RbacSeeder.php:581 (FINANCE_INVOICE_REJECT)`.

`POST /api/internal-audit/invoices/{uuid}/return` is gated on the group's
`finance.invoice.approve` plus its own `finance.invoice.reject`
(`routes/endpoints/internal-audit.php:110`). The generator emitted:

```json
"POST /api/internal-audit/invoices/{uuid}/return": {
    "auth": true,
    "roles": []
}
```

A syntactically perfect entry. Nothing on stdout, no warning, no non-zero exit. The command
printed `route-access-map.json written (437 routes).` and returned 0.

## The denominator — how many committed rows are like this today

Measured on `tests/fixtures/route-access-map.json` at the commit that adds this ticket:

```
examined            : 384
roles === []        : 0
no 'roles' key      : 0    <- unrecognised
'roles' not an array: 0    <- unrecognised
```

Three numbers, not two: the last two are constructs the matcher could not classify, asserted at
zero so a new shape reds rather than vanishing into a "skipped" bucket. Controls: injecting one
synthetic `roles: []` row makes the matcher report 1; counting rows equal to a sentinel value that
cannot occur reports 0.

**So: none.** No committed row carries an empty or malformed role set. The `[]` above was caught
before it was committed, by the brief's instruction to state whether the row resolved to
`internal_auditor` **only** — `[]` is not that.

## Why the existing gates DO catch it — measured, and it corrects the report that raised this

The obvious severity argument is that `RouteAccessParityTest` compares fixture to live and, against
the same unsynced database, both sides agree on `[]`, making the defect self-consistent and
invisible. **That argument is wrong, and it was checked rather than assumed.**

The two sides do not read the same database. `rbac:derive-access` runs against the connected
development database. `RouteAccessParityTest` uses `RefreshDatabase` and seeds `DatabaseSeeder`
(`tests/Feature/Rbac/RouteAccessParityTest.php:35`) into `portal_testing`, and
`DatabaseSeeder` → `ArmsDatabaseSeeder` (`database/seeders/DatabaseSeeder.php:25`) →
`RbacSeeder` (`database/seeders/ArmsDatabaseSeeder.php:18`), which grants the ability. So the test
derives from a database where the permission exists.

Planting the generator's own output and running the oracle:

```
  before: ["internal_auditor"]
  after : []  <- the generator output, verbatim

{"tool":"pest","result":"failed","tests":17,"passed":16,"failed":1,
 "message":"ACCESS CHANGED: POST /api/internal-audit/invoices/{uuid}/return
    expected: [] auth=true
    live:     [internal_auditor] auth=true"}
```

**It reds immediately** — on the next suite run, not on some later day when someone syncs. That
bounds the blast radius and is why this is a ticket and not a fix.

## What is actually wrong, then

**The failure message's remedy re-creates the defect.** The red reads:

> Per-role route access drifted from the pre-swap oracle — if intended, regenerate via
> `php artisan rbac:derive-access` and review the diff

An operator on an unsynced database who follows that instruction literally regenerates `[]`,
commits it, and reds again. The loop terminates only when someone notices that the *generator*,
not the fixture, is the thing disagreeing with reality — and nothing in either output points
there. The fixture is blamed by name; the generator is offered as the cure.

**And the row is a plausible wrong answer a human will reason from.** `roles: []` reads as "no
role may reach this route", which for a checker-gated route is a *believable* claim — ADR 0040
excludes checker abilities from the `Gate::before` bypass, so a reader already expects these rows
to be unusually narrow. It is the falsely-restrictive direction, which is the direction that reads
as safe.

**The case where it does go silent** is worth naming so nobody concludes the oracle always saves
you: if the ability is missing from the **seeded** database too, both sides derive `[]` and agree.
That is precisely the state of an ability declared ahead of the code that grants it — the
`pending_emitters` mistake `app/Enums/Permission.php` warns about. The oracle's protection here is
a consequence of `RbacSeeder` being ahead of the dev copy, not a property of the design.

## The class

An instrument that reports **something** rather than **nothing** when a precondition for answering
is unmet. `$holders[$p] ?? []` converts *"I have no data about this ability"* into *"this ability
has no holders"*, and those are different claims. The command's only mitigation is prose in its own
`$description` — `app/Console/Commands/RbacDeriveAccessMap.php:14`, *"(run rbac:sync first)"* —
which is a rule with no mechanism behind it.

`docs/handoff/tickets/void-eligibility-docblock-contradicts-its-own-code.md:98` is the sibling: a
citation gate proving **resolvability** and being read as proving **accuracy**. Same shape one
layer over — a check that answers a narrower question than the one its output appears to answer.
The distinction there was between a pointer landing somewhere sane and the sentence around it being
true; here it is between a holder set being empty and the ability being absent.

## What would close it — proposed, not chosen

**Make the generator refuse, or warn loudly, when a route's `permission:` middleware names an
ability with no row in the connected database.** The check is cheap: `derive()` already collects
every listed ability, and `holders()` already loads every permission name; the missing ones are a
set difference, computed once.

- *Refuse* (non-zero exit, nothing written) is the fail-closed option, and it is the one consistent
  with `bin/db-exclusive` refusing rather than reporting. Cost: a generator that cannot run on a
  deliberately-partial database, which may be a legitimate state during a slice that declares an
  ability before its convergence migration.
- *Warn loudly and name the abilities*, still writing the file, keeps that case working. Cost: a
  warning can be scrolled past, which is the failure mode this repo has recorded for the unheeded
  pre-flight `echo`.

Either would have turned this session's silent `[]` into a message naming `finance.invoice.reject`,
which is the whole distance between the defect and the fix. **The same change should also amend the
oracle's failure message** so it does not recommend regeneration without saying that a regeneration
is only meaningful against a synced database.

Not built here: this branch adds four fixture rows and corrects three prose claims, and a change to
an oracle generator is its own reviewed slice.

## Related

- `docs/handoff/tickets/fifty-four-routes-are-missing-from-the-access-map.md` — the backlog a
  regeneration would sweep in, and the reason regeneration is not a one-command fix.
- `docs/handoff/tickets/route-middleware-baseline-is-67-routes-stale.md` — same for the other
  oracle.
- `docs/handoff/reports/fix-return-route-in-both-route-oracles.md` — the session that hit this.
