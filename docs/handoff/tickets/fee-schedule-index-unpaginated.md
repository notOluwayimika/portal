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
pagination. Two screens and one modal now hold a correctness argument that rests on the whole
matching set
arriving in one response, and the day that stops being true they do not error — **they lie
quietly**, which is the reason this section exists at all.

The same argument is made twice, because the sibling bank-accounts endpoint
(`BankAccountController::index()`) is unpaginated for the same reason and its screen depends on it
identically. Whoever paginates either one is the audience here.

### The five dependents, and the exact lines

> **Line citations re-derived 2026-08-15 after cold review**, against the tree at that date. Four of
> the six original entries had gone stale — both counters and both card-render sets, which are
> exactly the regions a later commit edited after this table was first written. That is the **third
> recorded occurrence** of this class in the repository; see
> [`stale-path-line-citations.md`](stale-path-line-citations.md) for the pattern and why line numbers
> in prose rot faster than anyone expects. Re-derive before you trust these, too — the symbol names
> in the parentheses are the durable part, the numbers are not.

| Screen                                                     | What depends on it                                             | Lines                                                                    |
| ---------------------------------------------------------- | -------------------------------------------------------------- | ------------------------------------------------------------------------ |
| `resources/js/pages/admin/finance/fee-schedules.tsx`       | Client-side search over label / term label / class-level label | 529–542 (`const term` / `const visible`), state at 235, comment 229–234  |
|                                                            | "Showing X of Y" — **both numbers from one array**             | 722–733 (`{visible.length}` / `{schedules.length}`)                      |
|                                                            | The three KPI stat cards, counted over the loaded set          | 547–548 (`countOf`), rendered 648, 656–661, 668                          |
|                                                            | **`accountOptions()` — the "Paid into" select** (see below)    | 327–345 (`activeAccounts` / `accountOptions`), fed by `loadAccounts` 296 |
| `resources/js/pages/admin/finance/bank-accounts.tsx`       | Client-side search AND the active/deactivated status filter    | 193–211 (`const term` / `const rows`), state at 87–88, comment 81–86     |
|                                                            | "Showing X of Y" — **both numbers from one array**             | 355–366 (`{rows.length}` / `{accounts.length}`)                          |
|                                                            | The three KPI stat cards                                       | 190–191 (`activeCount` / `deactivatedCount`), rendered 294, 302, 310     |
| `resources/js/components/finance/record-payment-modal.tsx` | **Auto-selection of the only active account** (see below)      | 118–131 (`active.length === 1` → `setBankAccountId`)                     |

### The two dependents the first version of this section missed

Both are worse than the counter, because both silently write a **destination for money** rather than
merely misreporting a count.

**1. `accountOptions()`'s preservation branch blanks an existing destination.**
`fee-schedules.tsx:327-345` offers only active accounts, except that it deliberately keeps a
**deactivated** account in the list when the draft being edited already points at it:

```ts
const offered = activeAccounts.some((account) => account.id === current)
    ? activeAccounts
    : accounts.filter((account) => account.is_active || account.id === current);
```

That branch scans `accounts` — the whole list, today. Paginated, `accounts` is one page, so a draft
whose destination is a deactivated account **not on page 1** finds no match, the option is not
offered, and the select renders with the operator's existing choice silently absent. The comment
above it states the intent this breaks: _"hiding it would silently blank the operator's existing
destination."_ Re-submitting the form from that state posts a different `bank_account_id`, or none.

**2. `record-payment-modal.tsx` auto-selects a destination nobody chose.** At `:118-131`:

```ts
const active = (data.bank_accounts ?? []).filter((a) => a.is_active);
setAccounts(active);
setBankAccountId(active.length === 1 ? active[0].id : '');
```

Its own comment says why that is only safe unpaginated: _"PRE-SELECT ONLY WHEN THERE IS EXACTLY ONE
… With two or more accounts there is a real choice and guessing would assert a destination nobody
picked — on a row that is append-only."_ Paginated, `active.length === 1` is satisfied by **a page
containing one active account**, not by a school having one. A school with ten accounts, one of them
active on page 1, would auto-fill a payment destination the bursar never chose — on
`finance_payments`, which is append-only and cannot be corrected by editing.

Each site carries a comment saying it is sound only because there is no pagination —
`fee-schedules.tsx:229-234` and `bank-accounts.tsx:81-86`. Those comments are the other half of
this section; neither is enough alone, because a comment in a file is only found by someone already
editing that file, and the person who paginates the endpoint is editing a controller.

Note the asymmetry on fee-schedules and do not let it mislead you: `term_id` and `status` are
applied **server-side** (`->when()` in `index()`), and only the search box is client-side. The
counter and the KPI cards are downstream of the merged result either way, so all three break
together.

### What goes wrong the day pagination lands

**Which of the two failure modes you get depends on the shape you choose, and the quiet one is the
dangerous one.**

- **If the paginated response keeps the rows at the same key** (an array where an array was, e.g.
  `?page=` narrowing the same top-level list), **nothing throws.** The response is still JSON, the
  screen still renders, every test still passes — the acceptance suite is structurally blind to this,
  because a 200 carrying page 1 and a 200 carrying everything are the same assertion. Everything
  below then happens silently.
- **If it grows an envelope** (`{data: [...], meta: {...}}`, the shape `finance/index.tsx` already
  consumes), the screens **crash before anything paints**. `setSchedules(data ?? [])` and
  `setAccounts(data.bank_accounts ?? [])` do not validate shape, so `schedules` becomes an object,
  and `visible.filter` / `accounts.filter` throw `TypeError: … is not a function` during render.
  Loud, immediate, and far easier to notice — which is the one mercy in the envelope shape.

The list below describes the **quiet** case. Do not read "nothing throws" as reassurance that the
envelope shape is safe; it is a different failure, not a smaller one.

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
- **The two money-destination dependents are not optional and are not UI polish.** The bank-accounts
  endpoint feeds both, so paginating _it_ is what breaks them:
    - `accountOptions()`'s preservation branch must look up the draft's current account by id
      (a targeted fetch, or the API returning it alongside), not by scanning whatever page arrived.
    - `record-payment-modal.tsx`'s `active.length === 1` auto-select must key off a **school-wide**
      count the API states, or be removed. A pre-selected destination derived from a page is worse than
      no pre-selection, and the row it writes is append-only.
- Delete the two comments cited above and this section, so the next reader is not warned about a
  constraint that no longer holds.
- **Decide the response shape deliberately, and prefer the envelope.** Per the two failure modes
  above, an envelope crashes loudly on the unmigrated consumers while a same-key array fails
  silently. Loud is what you want here — it is a list of consumers you must find anyway.

Related and adjacent, filed separately:
[opening-balance-index-hydrates-every-row.md](opening-balance-index-hydrates-every-row.md).
