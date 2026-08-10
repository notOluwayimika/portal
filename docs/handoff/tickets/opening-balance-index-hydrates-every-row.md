# `index()` hydrates every staged row of ten batches to compute summaries nothing renders

**Raised by:** cold review of PR #235 (finding 4.4). **Severity:** ticket — real, bounded, not
ship-blocking. **Not implemented in #235**, deliberately: it is a performance change to a read path,
and #235's subject is the file format and the sign-convention control.

## What happens

`OpeningBalanceBatchController::index()` returns the maker's ten most recent batches and maps each
through `serialize()`. `serialize()` computes the interpretation on every call, and
`OpeningBalanceInterpretation::for()` hydrates **every `Ok` row of that batch** into Eloquent models
to net them per student.

So opening the operator screen — which calls `index()` on mount and after every upload — hydrates the
staged rows of up to ten batches. A real cutover extract is a few thousand lines. The recent-uploads
list renders `batch_reference`, `status` and `row_count`; **it renders no part of the interpretation
at all.**

`show()` is the endpoint whose page actually renders the summary, and it is the one that carries
`withRows: true`.

## Why it is not urgent

A school gets ONE posted batch (G1), so the ten-batch list is mostly failed and superseded attempts,
and this is a maker-only screen used during a cutover window rather than a hot path. The cost is
wasted work and memory on a page that is already doing a file upload, not a correctness problem and
not a user-visible one at Brookstone's size.

## The fix, when it is worth doing

Two options, in preference order:

1. **Gate on `withRows`** — compute the interpretation only where it is rendered, i.e. only in
   `show()`. One-line change, and it makes the payload honest: `index()` stops claiming a summary its
   consumer ignores. The cost is that `OpeningBalanceBatchRecord` and `OpeningBalanceBatchDetail`
   stop sharing the field, so the TS types split.
2. **Compute in SQL** — a grouped aggregate over `finance_opening_balance_rows` returning the net per
   student, then classify in PHP. Keeps the field on both payloads and removes the hydration
   entirely. More code, and the per-student netting plus the "does any row move?" flag both have to
   be expressed as SQL, inside `app/Finance` where `DB::table` is banned by the boundary lint — so it
   would go through the model's query builder.

(1) is almost certainly right: the field is unused on that payload, and the cheapest way to make a
computation fast is not to do it.

## Watch out for

`serialize()` is also used by `store()`'s 201 response. Since #235's remediation the interpretation
is **null while the batch is `draft`**, so that path no longer computes anything — a gate on
`withRows` must not accidentally re-introduce a summary there.
