# Two bulk action bars are pinned to the window, not the content column

**Status:** open. Long-standing on both screens; measured again 31 August while building a third bar
that had to solve the same problem from scratch.

## The two bars

`resources/js/components/students/student-bulk-action-bar.tsx:50`

    <div className="fixed inset-x-0 bottom-0 z-40 …">

`resources/js/components/guardians/bulk-action-bar.tsx:29`

    <div className="fixed bottom-0 left-0 right-0 z-40 …">

Both span the full viewport width, so while a selection is live the bar lies across the sidebar.
That is not only untidy: it covers the bottom navigation entries, so the operator's way out of the
screen is behind the bar that appeared because they ticked something.

## The fix already exists in this repo, and it was not obvious

`resources/js/pages/admin/finance/manual-invoice-runs/index.tsx:218-260` and `:1153-1168`.

The comment there records what was measured rather than assumed: `position: sticky` inside the
column — the obvious fix — does not work, because the shell's `<main data-slot="sidebar-inset">`
computes `overflow: auto` and so becomes the bar's scrollport, while being sized by its content
(`min-h-svh`) so it never scrolls. The document scrolls; the scrollport does not; a sticky element
whose scrollport never scrolls never engages, and the bar sat below the fold at `top: 1430` in a
1400px viewport.

What works is to stay `fixed` and copy the horizontal box from the content column on every geometry
change, via a `ResizeObserver` on the column, with `max-sm:inset-x-4 max-sm:w-auto` for the narrow
case where the column is the window. Nothing in it knows the sidebar's width, its collapsed state or
its breakpoint — it tracks the column, and the column is already correct at every size. Measured at
four widths.

## Why this is worth a ticket now rather than earlier

Two bars with the same defect is a pair. Three is a pattern, and the third one has a proven
implementation the other two do not share only because it was written inline in a page rather than
extracted.

## What closes it

Extract the positioning — the ref, the `ResizeObserver`, the measured box and the narrow-screen
fallback — into one component or hook, and move all three bars onto it. The Finance bar is the
reference implementation; it should not be the only caller.

Low severity and cheap. It is on this list because the cost of NOT doing it is that the next screen
with a selection re-derives it a fourth time, or copies `fixed inset-x-0` from one of the two that
are wrong.
