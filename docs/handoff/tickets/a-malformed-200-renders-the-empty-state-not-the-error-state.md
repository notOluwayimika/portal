# A malformed 200 renders the EMPTY state, not the error state

**Raised by:** cold review of `feat/ui-bank-accounts-fee-schedules-redesign` (2026-08-15). That
branch exists in large part to stop "no data" and "the request failed" looking like the same screen.
It succeeds for a request that **fails**. It does not cover a request that **succeeds with the wrong
body**, and that is the one path an aborted-request drive structurally cannot reach.

## What is true today

Every Finance list screen unwraps its response with `??` and no shape check:

| File                                                     | Line  | Code                                     |
| -------------------------------------------------------- | ----- | ---------------------------------------- |
| `resources/js/pages/admin/finance/fee-schedules.tsx`     | `288` | `setSchedules(data ?? []);`              |
| `resources/js/pages/admin/finance/fee-schedules.tsx`     | `299` | `setAccounts(data.bank_accounts ?? []);` |
| `resources/js/pages/admin/finance/bank-accounts.tsx`     | `102` | `setAccounts(data.bank_accounts ?? []);` |
| `resources/js/pages/admin/finance/discount-policies.tsx` | `172` | `setPolicies(data ?? []);`               |

`??` only guards `null` / `undefined`. A **200 whose body is well-formed JSON but the wrong shape** —
`{}`, `{"data": [...]}` after someone adds an envelope, `{"bank_account": …}` after a typo in a
resource, an HTML error page a proxy returned with a 200 — produces `undefined` at that key, the `??`
substitutes `[]`, and:

- `error` stays `false`, because nothing threw;
- `loading` goes `false`;
- the table renders **"No fee schedules to show"** / **"No bank accounts to show"**;
- the KPI cards render real zeros, and the counter renders "Showing 0 of 0" — both of which this
  branch deliberately suppresses on the `error` path, and neither of which is suppressed here,
  because as far as the screen is concerned the request succeeded and the school is empty.

**So the screen states, with its whole layout, that the school has no bank accounts.** That is the
exact confusion the error state was added to remove, arriving through the one door it does not
cover.

## Why no drive has caught it

The drive forced failures by **aborting the request at the network layer**, which makes axios reject
and lands cleanly in `catch`. That proves the `error` path and cannot touch this one: a malformed 200
never rejects. Reproducing it needs response **interception and rewriting**, not blocking.

The suite cannot see it either — there is no JS test runner
([`no-javascript-test-runner.md`](no-javascript-test-runner.md)), and the PHP feature tests assert
the API's own shape, which is correct today. The failure is entirely in what the client does when
that contract is one day broken.

## Why this is not hypothetical

[`fee-schedule-index-unpaginated.md`](fee-schedule-index-unpaginated.md) describes a change that is
expected and already scoped: paginating these endpoints. If it lands as an **envelope**
(`{data: [...], meta: {...}}` — the shape `finance/index.tsx` already consumes), then on the day it
ships `data ?? []` yields an object, and `visible.filter` / `accounts.filter` throw during render.
If it lands as a same-key array, everything degrades quietly instead. Either way, the unwrapping
above is the code that decides which — and it currently makes that decision by accident.

## What the fix looks like

- **Validate the shape at the boundary, once.** `Array.isArray(data)` for the array endpoints,
  `Array.isArray(data?.bank_accounts)` for the wrapped one. Anything else sets `error` and renders
  the error state, which already exists and already says the right thing.
- A distinct message is worth considering — "the server returned something this page could not
  read" is a different operator action from "the request failed" (the first is a bug report, the
  second is a retry) — but sharing the error state is far better than the status quo and is the
  minimum.
- Apply it to all four sites in the table above, including `discount-policies.tsx`, which is not a
  screen this branch redesigned but carries the identical unwrap.
- If a validation helper appears, it belongs beside the other shared Finance frontend utilities
  rather than being written four times.

## Related

- [`students-index-403s-render-two-placeholder-only-selects.md`](students-index-403s-render-two-placeholder-only-selects.md)
  — the same family: a control rendering empty because the data behind it never arrived, on a page
  that looks healthy.
- [`fee-schedule-index-unpaginated.md`](fee-schedule-index-unpaginated.md) — the change most likely
  to trigger this.
