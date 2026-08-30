# TICKET — half the boundary-lint baseline has no expiry condition, in a file that says TEMPORARY

**Status:** open. Low, and the fix is a paragraph rather than code.

## The fact

`boundary-lint-baseline.txt` opens, generated from `bin/ci-boundary-lint.php:327`:

```
# boundary-lint baseline — intentional, TEMPORARY exceptions. May only shrink.
```

The header then names an expiry for two of the four rules it carries:

- `school-id-fallback-context` (3 entries) — expire when `users.school_id` is dropped, with the
  SuperAdmin/AdminController entry called out as a maintenance write that goes with the column.
- `finance-table-outside-finance` (1 entry) — expires when Ph2's `FinanceModuleStatus` contract
  replaces `ModuleClassificationService`'s direct `finance_*` reads.

It then lists four rules that have **zero** entries and says so, which is exactly the right thing to
record.

**It says nothing at all about `finance-escape-hatches`**, which holds the remaining four entries —
every one of them in `app/Academics/BillableEnrollmentAdapter.php`, and every one of them a
deliberate `withoutGlobalScope(SchoolScope)` in the ACL adapter that is the sanctioned crossing point
between Academics and Finance.

## Why that matters more than it looks

Those four are not temporary. They are the architecture: the adapter exists to cross the boundary,
and the lint flags the crossing because the rule cannot tell a sanctioned port from a leak. Four of
eight entries in a file headed TEMPORARY are permanent by design.

The cost is not today. It is the first person told to burn the baseline down. They will find four
entries with no stated expiry in a file promising all of them are temporary, and they have two ways
to be wrong: delete the adapter's escape hatches and break the port, or leave them and conclude the
baseline cannot be emptied, which makes "may only shrink" untrue and quietly retires the discipline.

A baseline whose remaining entries can never be removed reads, to the next reader, as a baseline
nobody is working on.

## What closes it

A paragraph in the header, beside the two that already have one — that `finance-escape-hatches`
entries are the ACL port and are **permanent**, that the rule flags them because it cannot
distinguish a port from a leak, and that a fifth entry appearing in a file other than
`BillableEnrollmentAdapter.php` is the thing to look at.

That last clause is the part with teeth: it converts "four permanent entries" into a rule with an
edge, so growth in that class is still visible even though the four never shrink.

Consider also whether the file's own headline should say "intentional exceptions, most of them
temporary" — the current wording is a claim the contents do not support, and the whole point of this
baseline is that its contents are the record.

Related, separate: `docs/handoff/tickets/boundary-lint-baseline-keys-on-line-text.md`, which is about
how entries are keyed rather than what they mean.
