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

## What now DEPENDS on this endpoint staying unpaginated

**Added 2026-08-15 by `feat/ui-bank-accounts-fee-schedules-redesign`.** Read this before you add
pagination. Two screens now hold a correctness argument that rests on the whole matching set
arriving in one response, and the day that stops being true they do not error — **they lie
quietly**, which is the reason this section exists at all.

The same argument is made twice, because the sibling bank-accounts endpoint
(`BankAccountController::index()`) is unpaginated for the same reason and its screen depends on it
identically. Whoever paginates either one is the audience here.

### The three dependents, and the exact lines

| Screen                                               | What depends on it                                             | Lines                                                                |
| ---------------------------------------------------- | -------------------------------------------------------------- | -------------------------------------------------------------------- |
| `resources/js/pages/admin/finance/fee-schedules.tsx` | Client-side search over label / term label / class-level label | 529–545 (`const term` / `const visible`), state at 235               |
|                                                      | "Showing X of Y" — **both numbers from one array**             | 706–713 (`{visible.length}` / `{schedules.length}`)                  |
|                                                      | The three KPI stat cards, counted over the loaded set          | 547–548 (`countOf`), rendered 639, 647, 655                          |
| `resources/js/pages/admin/finance/bank-accounts.tsx` | Client-side search AND the active/deactivated status filter    | 193–211 (`const term` / `const rows`), state at 87–88                |
|                                                      | "Showing X of Y" — **both numbers from one array**             | 340–347 (`{rows.length}` / `{accounts.length}`)                      |
|                                                      | The three KPI stat cards                                       | 190–191 (`activeCount` / `deactivatedCount`), rendered 284, 292, 300 |

Each site carries a comment saying it is sound only because there is no pagination —
`fee-schedules.tsx:229-234` and `bank-accounts.tsx:81-86`. Those comments are the other half of
this section; neither is enough alone, because a comment in a file is only found by someone already
editing that file, and the person who paginates the endpoint is editing a controller.

Note the asymmetry on fee-schedules and do not let it mislead you: `term_id` and `status` are
applied **server-side** (`->when()` in `index()`), and only the search box is client-side. The
counter and the KPI cards are downstream of the merged result either way, so all three break
together.

### What goes wrong the day pagination lands

Nothing throws. The response is still JSON, the screen still renders, every test still passes —
the acceptance suite is structurally blind to this, because a 200 carrying page 1 and a 200
carrying everything are the same assertion.

1. **The counter becomes false.** `Showing {visible.length} of {schedules.length}` reads its second
   number from the array it was handed. Paginated, that array **is** the page, so a school with 140
   schedules on a page size of 20 renders "Showing 20 of 20" — which is not merely imprecise, it is
   the specific sentence that tells the operator there is nothing more to look for. The correct
   second number would have to come from the pagination envelope's `total`, and nothing on either
   screen reads one today.
2. **Search narrows to a page while claiming not to.** The box is presented as a search of the
   school's schedules; it is a filter over whatever arrived. A bursar typing "JSS 3" gets the JSS 3
   schedules **on page 1**, an empty result if they are on page 4, and in both cases the empty-state
   copy says "No schedules match this view" — an assertion about the school, made from a page. This
   is the failure the design system bans in § 7 ("never a client-side filter that silently disagrees
   with server pagination"); it is permitted here **only** by the absence of the thing you are about
   to add.
3. **The KPI cards under-count.** "Drafts / With the ED / Active" count rows in hand. They already
   say "In this view" to stay honest against the server-side status filter, but paginated they would
   describe a page while sitting in the position the design system reserves for headline metrics.
4. **bank-accounts loses a filter outright.** Its status filter is the _only_ way to see deactivated
   accounts; paginated, "Deactivated" shows the deactivated accounts of page 1.

### What the fix looks like, so it is not rediscovered

Paginating either endpoint is not done until the same commit does this:

- Move `search` into the query string on both screens and give the API a `search` parameter —
  or drop the box. Leaving it client-side is the defect above, not a smaller version of it.
- Move bank-accounts' `status` filter into the query string.
- Read the counter's second number from the envelope's `total`, never from the row array.
- Add `<Pagination meta={…} setPage={…} setLimit={…} />` (`@/components/pagination`) to both table
  cards — both currently omit it deliberately, precisely because there is nothing to page.
- Decide what the KPI cards mean. School-wide totals from the API is the honest answer; counting a
  page is not, and neither is quietly keeping "In this view".
- Delete the two comments cited above and this section, so the next reader is not warned about a
  constraint that no longer holds.

Related and adjacent, filed separately:
[opening-balance-index-hydrates-every-row.md](opening-balance-index-hydrates-every-row.md).
