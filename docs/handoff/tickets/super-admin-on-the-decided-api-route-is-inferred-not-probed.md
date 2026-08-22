# `super_admin` on the decided API route is inferred, not probed

**Raised by:** cold review of `feat/u13-u14-decided-approvals` at `ffbae04` (2026-08-22). The branch's
own report names the `super_admin` posture as a consequence worth a reviewer's eye and asserts it at
the **web page** layer. It does not assert it at the **API** layer, and the difference is not
cosmetic — the page is a shell that fetches.

## What is proven today

**The page seat matrix.** `tests/Feature/Finance/ApprovalsPageGateTest.php:84-100` acts as a
`super_admin` with `auth.gate_before_superadmin` on and asserts **200 on `/finance/decisions`** and
**403 on `/finance/approvals`**, both on one user.

**The API route's middleware list.** `tests/Feature/Finance/DecidedApprovalsFeedTest.php`'s first arm
asserts a `finance.access`-only reader gets **200 on both decided feeds** and **403 on both pending
feeds**. That arm is bite-proved: adding `permission:finance.credit-note.approve` to the decided route
reds it with `Expected response status code [200] but received 403`. So the claim "the decided routes
carry no middleware beyond the group" rests on a mutation, not on reading the route file.

## What is NOT proven

**No test asserts `super_admin` against `/api/v1/finance/credit-notes/decided` or
`…/void-requests/decided`.** Re-derive:

```bash
grep -rn "super_admin" tests/Feature/Finance/DecidedApprovalsFeedTest.php   # no matches
```

The posture is an **inference**: the decided routes carry only the group's `finance.access` (proven) →
`finance.access` is not a checker ability, so ADR 0040's bypass exclusion does not apply to it →
`Gate::before` grants it to `super_admin` → 200. Each link is sound and the chain is consistent with
the page-layer result. It is still a chain nobody has executed.

## Why the page result does not carry the API result

`/finance/decisions` is `Inertia::render('admin/finance/decisions')` (`routes/web.php`) — a shell. The
table is filled by two client-side `axios.get` calls to the decided feeds
(`resources/js/pages/admin/finance/decisions.tsx`). A 200 on the page therefore says nothing about
whether the feeds answer for that seat.

The failure this leaves unexcluded is a specific and familiar one: **a `super_admin` opens a page that
loads, and the table underneath it is empty or errored.** The page's own error rule fires only when
*every* feed rejects, so a partial refusal would render an empty table — the "malformed 200 renders
the empty state" class, on a screen that looks healthy. Neither the suite nor the drive would show it:
the drive ran `user#2`, `user#3` and `user#6`, and `super@drive.test` was not driven.

## What closes it

One arm in `DecidedApprovalsFeedTest`, mirroring the shape the page test already uses — the seat built
the same way (`config(['auth.gate_before_superadmin' => true])`, `setPermissionsTeamId(null)`,
`assignRole('super_admin')`, flush both caches), asserting on **one user**:

- `GET /api/v1/finance/credit-notes/decided` → **200**
- `GET /api/v1/finance/void-requests/decided` → **200**
- `GET /api/v1/finance/credit-notes/pending` → **403**
- `GET /api/v1/finance/void-requests/pending` → **403**

Both halves on the same seat, for the reason the existing reader arm gives: the 200s alone would pass
just as happily if the decided routes had inherited the checker gate and the bypass were doing the
work instead of the group permission.

**Bite-proof it the same way the reader arm is bite-proved** — add the approve middleware to a decided
route and confirm the new arm reds — or it proves the bypass exists rather than that the gate is where
this branch says it is.

## The open question underneath, which the arm does not settle

Whether a `super_admin` **should** read decided approvals is a decision, not a derivation. Today they
read the settled corrections of every school they can reach and can sign none of them, which is
defensible — ADR 0040 excludes them from *deciding*, and ADR 0036 is explicit that the bypass is
authorization and never isolation, so this is not a cross-school hole. But it was arrived at by
following `finance.access`, not by anyone choosing it. The test records the behaviour; the choice is
the project lead's.
