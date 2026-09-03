# Two toast libraries ship in the same bundle

**Raised:** 2026-09-03 · **From:** the Internal Audit queue restyle · **Severity:** ticket

## What

`resources/js/pages/admin/finance/` alone imports **both**:

- `sonner` — 3 files, including `bank-accounts.tsx` (the newer of the two, landed `83c0247b`)
- `react-toastify` — 2 files, including `approvals.tsx` (landed `76fe7535`)

Both are real dependencies, both ship, and a user moving between two finance screens gets two
different toast presentations for the same class of event.

## Why it is a ticket and not a fix here

Settling it means picking one, rewriting every call site, removing a dependency and checking that
the removed library's provider is not mounted anywhere — which is a change about toasts, reviewed as
such. The IA queue used `sonner` because it is the newer of the two and what the most recently
landed finance screen uses; that choice is the smallest one available and it is deliberately not an
argument that `sonner` should win.

## What closes it

Pick one, migrate the call sites, drop the other from `package.json`, and remove its provider from
the layout. Then the next screen inherits one convention instead of choosing.

## The general finding underneath

The same shape as `two-index-endpoints-paginate-on-unclamped-user-input.md`: a platform that has not
decided once, in one place, so each new screen adopts whichever convention it copied from. Neither
library is wrong; having both is.
