# Server-side money has no single formatter, and no lint watches it

**Status:** OPEN. Raised by the cold review of `feat/u10-allocation-screen`, 2026-08-22.

## What was seen

U10's allocation screen renders an over-allocation refusal that reads

> That is more than invoice 000011 still owes (**NGN 1500.00**).

fifteen pixels from a table whose own column renders the same quantity as **₦1,500.00**. The message
is produced server-side by `AllocationRefused`; the column by `formatNaira` in the browser.

## Why this is not a one-line fix, and must not be patched at the call site

**`Money::toNaira()` emits no thousands separator** (`app/Support/Money.php:91-97` — the body is
`$major.'.'.str_pad($minor, …)`, no grouping anywhere). So on a real term bill the same message reads
**`NGN 125000.00`**, which is worse than the drive's toy figure suggests: six unbroken digits in a
sentence about money an operator is about to commit irreversibly.

**And `toNaira()` plus a currency code is the repository's ONLY server-side money rendering.**
`app/Finance/Actions/SubmitCreditNote.php:92` builds its activity-log summary the same way —
`$amount->currency.' '.$amount->toNaira()`. Changing the allocation message alone would therefore
create a **third** notation rather than removing the second: `₦1,500.00` in the UI, `NGN 1500.00` in
the credit-note trail, and whatever the patched message became. That is why the fix is not "format
this string better".

## Why nothing caught it

`bin/ci-money-lint.php` walks **`resources/js` only** (`:78`, `scriptLines($root.'/resources/js')`),
and its two rules are scoped to `resources/js/pages/admin/finance/` and
`resources/js/components/finance/` (`:42-43`). It has **no server-side arm at all**.

So this is not a lint that was bypassed and it is not a baselined exception — **no baseline hides
it**, because the lint never looked. The rule "all money is displayed through one formatter" is
enforced in the browser and is a convention with nothing behind it on the server. By the wallpaper
principle that is the more interesting half of this ticket: the UI rule looks stronger than it is,
because half the surfaces that render money are outside the only thing checking.

## The fix, stated as one change

One shared server-side formatter — grouped, symbol-aware, the exact counterpart of `formatNaira` —
plus a new lint rule that fails when a `Money` reaches a user-facing string by any other route, and
then **every existing site moved at once**. Applying it to one call site at a time is what produces
the third notation.

Not attempted on `feat/u10-allocation-screen`: it is a shared-kernel change with its own gate, and
that branch's message was left as it stands deliberately so this ticket describes a consistent state
rather than a half-migrated one.

## Sites known today

| Site | Renders |
| --- | --- |
| `app/Finance/Actions/AllocatePayment.php` (invoice-outstanding and payment-headroom refusals) | `NGN 1500.00` |
| `app/Finance/Actions/SubmitCreditNote.php:92` (activity-log summary) | `NGN 1500.00` |
| `resources/js/lib/format.ts` `formatNaira` — the UI's single renderer | `₦1,500.00` |

The list is what was found while writing this, not the output of a scan — there is no scan, which is
the ticket.
