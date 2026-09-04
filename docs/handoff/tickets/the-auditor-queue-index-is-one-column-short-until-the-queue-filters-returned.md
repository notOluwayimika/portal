# The auditor-queue index is one column short — until the queue filters `returned`

**Status:** open · **Opened:** 2026-09-04 · **Owed by:** the commit that adds
`whereNull('returned_at')` to `InvoiceReviewController::pending()`

`2026_09_04_100000` shipped `finance_invoices_school_reviewed_returned_index` on
`(school_id, reviewed_at, returned_at)`. The measurement below says a **fourth** column,
`created_at`, removes the auditor queue's filesort. It is deliberately **not** in the tree, and this
ticket records why, what it is worth, and what the commit that adds it owes.

## What was measured

Throwaway database migrated from zero, **60,000 planted invoices** across four schools. School 2:
**27,384** in the book, **2,700** pending, **300** returned. Reproduced from the commit-2 report —
not re-measured for this ticket.

| | type | key | rows | Extra |
| --- | --- | --- | --- | --- |
| **PRE** — auditor, staging today | `ref` | `school_student_reviewed` | **27,384** | filesort |
| 3-col — auditor | `ref` | `school_reviewed_returned` | **2,700** | filesort |
| 3-col — Finance | `range` | `school_reviewed_returned` | **300** | filesort |
| 4-col — auditor | `ref` | *(4-column variant)* | **2,700** | **no filesort** |
| 4-col — Finance | `range` | *(4-column variant)* | **300** | filesort (unchanged) |

The PRE row is the one worth keeping: **the auditor queue has been scanning the school's entire book
since the review endpoints shipped.** `finance_invoices_school_student_reviewed_index` is
`(school_id, student_id, reviewed_at)` and the queue is school-wide, so `student_id` is
unconstrained, the prefix breaks after `school_id`, and `reviewed_at` becomes a per-row filter.
There is no skip-scan rescue: MySQL added it in 8.0.13 and **production is 5.7**.

## Why the fourth column is not in the tree

**It is for ORDERING, not filtering.** No predicate reads `created_at`; it exists so the queue's
`ORDER BY created_at, id` is served by the index instead of by a filesort.

And that is exactly why it would pay **nothing** today. `InvoiceReviewController::pending()`
currently filters only `whereNull(reviewed_at)`. With `returned_at` unconstrained the key prefix
breaks at the **third** column, so `created_at` is not usable for ordering and today's query
filesorts whether the column exists or not. Adding it now would be write cost on every invoice for a
query that does not exist, and its EXPLAIN would have been measured against that non-existent query.

**The `, id` tiebreak is served, and this is not an oversight.** The queue orders by `created_at`
*then* `id`, and only `created_at` would be in the index — but InnoDB appends the clustered key to
every secondary index, so the physical order is
`(school_id, reviewed_at, returned_at, created_at, id)` and both `ORDER BY` terms are covered.
Recorded because a four-column index serving a two-term sort otherwise reads as a sloppy
measurement.

## What the queue commit owes

- [ ] Add `whereNull('returned_at')` to `InvoiceReviewController::pending()`.
- [ ] A migration dropping `finance_invoices_school_reviewed_returned_index` and adding
      `finance_invoices_school_reviewed_returned_created_index`. **Measure the name against MySQL's
      64-character identifier cap before using it** — do not assume it fits.
- [ ] EXPLAIN **before and after**, against the **new** query. The 4-col row above was measured
      against the future query; re-derive it rather than carrying this table forward.
- [ ] **The trap the controller already names.** Its class docblock says that the day a filter is
      added to this query, `pagination.total` silently becomes the filtered subset and the omission
      detector narrows — and it requires either that the filter not affect the count, or that a
      separate unfiltered count arrive **in the same change**. `whereNull(returned_at)` **does**
      affect it: a returned bill is still an unreleased bill. One of the two is owed there.

## And it stops being justified if the sort changes

The fourth column's entire justification is the current `ORDER BY`. Anyone who changes the queue's
sort order has invalidated it and owes a fresh EXPLAIN — a column whose only job is an `ORDER BY`
nobody remembers is a column that has quietly become write cost for nothing.
