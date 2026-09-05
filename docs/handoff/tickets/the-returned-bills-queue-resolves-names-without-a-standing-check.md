# The returned-bills queue resolves names without a standing check

**Found:** cold review of `fix/refusals-name-the-bill-and-the-person`, 2026-09-05. Graded `fix` in
that branch's report, then **regraded to `ticket` on a measurement** — which is why this file
exists. A report is read once, by the person who asked for it. A ticket is what the next person
finds.

## What is true

`ReturnedInvoiceQueueController::returnerNames()`
(`app/Finance/Http/Controllers/ReturnedInvoiceQueueController.php:225`) resolves the page's
returner names like this:

```php
return User::query()
    ->whereIn('id', $ids)
    ->get(['id', 'first_name', 'last_name'])
    ->mapWithKeys(fn (User $user): array => [$user->id => trim($user->first_name.' '.$user->last_name)])
    ->all();
```

Two things about it, and only the first is a hazard.

**1. There is no standing check.** `SchoolScope` does not apply to `User` at all —
`SchoolScope::apply() (app/Models/Scopes/SchoolScope.php:24)` returns early on a `User` instance —
so this `whereIn` resolves an id belonging to **any** School and renders that person's name into
this School's screen. That is the same name-disclosure oracle
`App\Finance\Services\ActorName` was written to close for the refusal sentences, on the screen
whose own docblock argued for naming people in the first place.

**2. It hand-concatenates rather than using the accessor**, where
`ActorName::forSchool() (app/Finance/Services/ActorName.php:82)` reads
`User::getFullNameAttribute() (app/Models/User.php:490)`. Measured, these produce the **same
string**: the accessor is `first_name.' '.$last_name` with no trim, `ActorName` trims its result,
and this method trims its own concatenation. So this half is a consistency wart, not a defect —
and if anything the queue's trim is the safer of the two. It is recorded so that whoever fixes (1)
does not also have to re-derive whether (2) matters. It does not.

## Why it was NOT fixed in that branch, with the number that decided it

The review estimated two lines. Measured on one page of ten returned bills, counting identity
reads (`users`, `school_user`, `guardians`, `model_has_roles`) from `DB::enableQueryLog()`:

| distinct returners on the page (k) | shipped: one `whereIn` | via `ActorName::forSchool()` per id |
| --- | --- | --- |
| 1 | 5 identity / 14 total | 8 identity / 18 total |
| 3 | 5 identity / 13 total | 16 identity / 25 total |
| 10 | 5 identity / 13 total | 44 identity / 53 total |

**The shipped shape is constant in k. The naive `ActorName` loop is `4k + 4`** — it fits all three
rows exactly. This endpoint's own page cap is `MAX_PER_PAGE = 100`
(`app/Finance/Http/Controllers/ReturnedInvoiceQueueController.php:130`), so the worst case is a
page of 100 bills returned by 100 different people: **404 identity queries against a constant 5.**

The existing arms are not the obstacle — under the swapped resolver
`ReturnedInvoiceQueueEndpointTest` still passes 14/14 with 52 assertions, and no arm changes what
it asserts. **The obstacle is only the number above**, which is why the argument here is the
measurement. A reader who disagrees has to beat the number, not the opinion.

Both figures were re-derived for this ticket rather than copied from the branch report; they
reproduced exactly.

## What closes it

**A batch entry point on `ActorName` that takes many ids and applies the standing check in one
query**, so the queue keeps its single round trip and gains the scope.

Named as a shape, deliberately not designed here — the design decision is where the batched
standing predicate lives, and that is a change to `app/Models/User.php`'s access resolution rather
than to Finance.

### The trap for whoever picks this up

**`ActorName`'s per-user memo is the reason the naive loop is expensive, so the batch entry point
and the memo have to agree.**

`ActorName::$memo` (`app/Finance/Services/ActorName.php:73`) is keyed `"<schoolId>:<userId>"`, and
`forSchool()` is currently its only reader and its only writer. The cost above is not the `users`
read — that would be one query per id — it is that
`User::hasStandingInSchool() (app/Models/User.php:404)` is **per-user by construction**: it is
`isSuperAdmin() || legacyAccessibleSchoolIds() || schoolIdsFromRoles()`, three access sources
short-circuited with `||`, and the memo is what stops it running twice for the same person rather
than what makes it cheap the first time.

So a batch entry point must do two things, and doing only the first is the trap:

1. batch the **standing** check, not just the `users` read — batching the read alone leaves `3k`
   queries behind and looks like it worked;
2. **populate the same `"<schoolId>:<userId>"` memo it would have written per-user**, and read it
   before querying. If it does not, a batch call followed by a `forSchool()` call for one of the
   same ids re-queries; and a batch that writes a *different* cache lets a stale per-user entry win
   for one id and the batch result win for another, inside a single request. `flushMemo()`
   (`app/Finance/Services/ActorName.php:146`) is the reset both paths must share.

There is no batch entry point today — grepped, no `forSchoolMany`, `forSchoolBatch` or
`namesForSchool` exists anywhere under `app/` or `tests/`.

## Residual risk

**This is a scoping inconsistency, not a live leak: the two paths render the same string today,
and no reachable path puts a foreign id in `returned_by_user_id`** — the queue reads it off
invoices already scoped to the active School, and the only writer is
`App\Finance\Actions\ReturnInvoice`, which asserts `SchoolContext::assertOwns()` and then
`$actor->can('finance.invoice.reject')` under that School's permissions team before it writes.

**What would make it live:** a second writer of that column that does not carry both assertions —
a backfill migration, an off-request job, or the resubmission path Phase B has yet to build — or
any bug upstream that lets a foreign id reach the column. `returned_by_user_id` is a **LOOKUP with
no foreign key** (`database/migrations/2026_09_04_100000_finance_invoices_return_to_finance.php:178`),
so nothing at the database level would refuse such an id. The day any of those lands, this screen
starts reading another School's staff names one row at a time, and nothing fails.

## Related

- `docs/handoff/reports/fix-refusals-name-the-bill-and-the-person.md` § "The cold review", finding 3
- `docs/handoff/tickets/the-fold-refusal-names-ids-where-the-gate-names-the-class.md` — the
  precedent the whole line of work descends from
- `docs/handoff/tickets/the-schedule-refusal-is-anonymous-while-the-response-names-it.md` — the
  other finding from the same review that was recorded rather than fixed
