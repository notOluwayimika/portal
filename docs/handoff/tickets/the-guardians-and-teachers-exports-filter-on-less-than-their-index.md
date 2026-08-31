# The guardians and teachers exports filter on less than their index does

**Status:** open. Found 31 August while looking for prior art on server-side "all matching" scopes.
This is the defect `StudentIndexFilters` was extracted to close, still live on two other entities.

## The drift

`GuardianService::paginate` filters on search (including `whatsapp_number`), status, login access,
children count, and a date range. `GuardiansExport` filters on search (first name, last name, phone,
email) and status — and nothing else.

So an operator who narrows the guardians index to "has login access, four or more children" and
presses Export downloads a **superset** of what they are looking at, silently. `TeachersExport` is
the same hand-rolled shape.

## Why this is the same defect, not a similar one

`StudentIndexFilters`'s own docblock records the students version verbatim: the index filtered on
search + class level + arm, the export on search alone, so "an operator who narrowed to Year 9 B and
pressed Export silently downloaded the whole school". That was closed by **extraction** rather than
by copying the missing `when()` blocks across, on the reasoning that a second correct copy is a
third
drift waiting for the next filter to be added.

Two entities still have the uncorrected shape, and one of them sits directly beside the
`guardians/bulk-action-bar.tsx` "select all N matching" defect the bulk-invoicing brief already
names.

## Why it matters beyond a wrong spreadsheet

The bulk manual invoicing brief (§1) rules that "invoice all N matching" must be resolved
server-side, and gives the students index as the thing to borrow. That instruction now has **two
live
counterexamples** in the same repository rather than one. Whoever implements a filter-scoped write
and reaches for the nearest example has a two-in-three chance of copying a broken one.

## What closes it

Extract a `GuardianIndexFilters` (and a teachers equivalent) the way `StudentIndexFilters` was
extracted, and have the paginator and the exporter share it. Do not close it by adding the missing
`when()` blocks to the exporters — that produces a second correct copy, which is the state the
students pair was in immediately before it drifted.
