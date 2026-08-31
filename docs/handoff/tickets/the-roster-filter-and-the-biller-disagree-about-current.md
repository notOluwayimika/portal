# Three readers disagree about which class a child is in, and one of them raises the charge

**Status:** open, latent. Measured 31 August against `7a7848c`. Zero students in the dev copy have
more than one active episode today, which is exactly why this would ship green and wait.

## Three readers, three answers, one row

Measured by planting one student with two active episodes — a shape
`ManualInvoiceRunScreenTest.php:857` already establishes is schema-legal:

- `StudentIndexFilters` filters through `whereHas('currentCurriculum…')`, and `whereHas` matches
  **any** active episode. Filtering on LEVEL-ONE returned that student; so did filtering on
  LEVEL-TWO. Per-level totals therefore do not partition the school — the sum exceeds the number of
  distinct students.
- `Student::currentCurriculum` is a `hasOne(...)->where('status', ACTIVE)` with **no tie-break**
  (`Student.php:166-169`), so the roster's `class_label` renders the **lowest-id** episode.
- `BillableEnrollmentAdapter::billableEpisodes()` resolves **MAX(id)**, which is what the charge is
  raised against.

So the roster can show a child under LEVEL-ONE and bill them against their LEVEL-TWO episode.

## Why this is worse under a filter-selected run than under ticking

On the ticking path the operator sees the row, so the cost is a wrong class label beside a name they
recognise. Under a server-side "invoice everyone matching this filter" scope the operator never sees
the row at all — and running LEVEL-ONE and then LEVEL-TWO **bills the same child twice**, into a
feature the bulk brief (§4) already records as having no duplicate backstop.

## The system has already solved this once

Bulk reassign takes **episode** uuids rather than student uuids
(`BulkReassignStudentsRequest.php`), for the stated reason that a pupil moved by someone else
between page load and click must not have the wrong episode moved. The same reasoning applies here
and the manual run discards it: `finance_manual_invoice_run_targets` is keyed on the student, and
the episode is resolved later, by a different rule than the one the operator filtered on.

## What closes it

The three readers must agree, and agreement is the fix rather than any one of the three:

- Give `currentCurriculum` the same tie-break `billableEpisodes()` uses, so the label names the
  episode that will be billed. Cheapest, and it closes the label half only.
- Key the filter scope on the EPISODE, as bulk reassign does — which also makes "billed twice across
  two filters" unrepresentable rather than merely unlikely.
- Or forbid two active episodes at the schema level, which is the root and is tracked separately in
  `two-active-terms-in-one-session-has-no-constraint.md`.

**Do not close it by asserting that two active episodes cannot happen.** The test suite already
establishes that they can, and the dev copy having none today is a fact about today.
