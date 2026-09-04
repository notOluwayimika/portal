# The auditor-queue index is one column short — until the queue filters `returned`

**Status:** CLOSED 2026-09-04 · **Opened:** 2026-09-04 · **Closed by:** the single commit on
`feat/finance-auditor-queue-excludes-returned`, which added `whereNull('returned_at')` to
`InvoiceReviewController::pending()` and shipped
`database/migrations/2026_09_04_110000_finance_invoices_auditor_queue_index.php`.

**Named by BRANCH and migration rather than by SHA, and that is not laziness.** A commit cannot
contain its own hash: writing one in required a second pass, and the amend that folded it in changed
the very SHA it had just recorded — measured here, the ticket briefly named `39b7f26e` while the
commit was `99883e77`. A ticket pointing at a commit that does not exist is worse than one pointing
at a branch that does. `git log --oneline staging | grep auditor-queue` finds the merge.

**Kept rather than deleted, on purpose: a ticket deleted on closure takes its reasoning with it.**
The measurements below are why the fourth column exists, and the note at the bottom is the condition
under which it stops being justified.

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

## What the queue commit owed — all four discharged

- [x] `whereNull('returned_at')` added to `InvoiceReviewController::pending()`.
- [x] The migration ships as `2026_09_04_110000_finance_invoices_auditor_queue_index.php`. The name
      `finance_invoices_school_reviewed_returned_created_index` **measures 55 characters** against
      MySQL's 64-character cap — measured with `strlen`, not assumed. Its `down()` **restores the
      three-column index** rather than merely dropping the four-column one: a rollback that left the
      table with neither would drop the queue back onto `(school_id, student_id, reviewed_at)`,
      which is the scan this ticket exists about.
- [x] EXPLAIN re-derived against the **new** query on a fresh throwaway, not carried forward — and
      the three new COUNT reads measured too, because three counts added to a page load that each
      scanned the book would have made this commit a net loss:

      | | type | key | rows | Extra |
      | --- | --- | --- | --- | --- |
      | auditor, new query, **3-col** (the before) | `ref` | `…_returned_index` | 2,700 | filesort |
      | auditor, new query, **4-col** | `ref` | `…_returned_created_index` | 2,700 | **none** |
      | Finance predicate, 4-col | `range` | `…_returned_created_index` | 300 | filesort |
      | `counts.awaiting_review` | `ref` | `…_returned_created_index` | 2,700 | none |
      | `counts.returned_to_finance` | `range` | `…_returned_created_index` | 300 | none |
      | `counts.unreleased_total` | `ref` | `…_returned_created_index` | 3,000 | none |

      The filesort is gone from the auditor arm, the Finance arm is unchanged as predicted, and
      **every count is served by the same index at matched-set size** — none touches the book.

- [x] **The `total` trap: the SECOND branch was taken — the separate unfiltered count arrives in the
      same change**, as `counts.unreleased_total`. The first branch was not available: `paginate()`
      derives `last_page` from the very count it reports, so a `total` describing a different set
      than the rows would make the PAGER lie, which is a worse defect than the one being avoided.
      The controller's docblock section was rewritten from a warning into that answer rather than
      left standing beside the thing it predicted.

## Two things this commit added that the ticket did not ask for, and why

**`counts.returned_to_finance` is load-bearing, not informational.** There is no Finance queue yet —
Phase B builds it. Until then this number is the only place in the system a returned bill is visible
at all: filtered out of the auditor's queue, invisible to the payer because it is still unreleased,
and with nowhere for Finance to find it. Without the count, returning a bill would make it vanish
from every screen.

**The invariant `unreleased_total == awaiting_review + returned_to_finance`** is asserted over a
fixture holding all four review states plus a void bill, and a mutation dropping `excludingVoid()`
from one count reds it (4 against 3). A break means a fourth unreleased state has appeared and
nobody updated the counts.

## A correction to this ticket's own numbers

The table above and the original text say **"27,384 in the book"** for school 2. That figure was the
OPTIMISER'S ROW ESTIMATE from an `EXPLAIN`, not a count. Re-measured on an identically-planted
throwaway, school 2 holds **15,000** invoices; 27,384 was InnoDB's estimate for a `ref` on
`(school_id, …)` and estimates on a secondary index are approximate by design.

**Nothing about the conclusion changes** — the before-row still examined the whole school rather
than the 2,700 it wanted, and that ratio is what the index fixed. But an estimate quoted as a count
is exactly the kind of number that gets re-quoted, so it is corrected here rather than left to be
inherited.

## And it stops being justified if the sort changes

The fourth column's entire justification is the current `ORDER BY`. Anyone who changes the queue's
sort order has invalidated it and owes a fresh EXPLAIN — a column whose only job is an `ORDER BY`
nobody remembers is a column that has quietly become write cost for nothing.
