# `parent/dashboard.tsx` is a design draft AND a live dependency — do not delete it

**Raised:** 2026-08-29, from building `/parent/finance` against the read contract.
**Severity:** ticket — nothing is broken today. It exists so that a future tidy-up does not break it
on the strength of a document that is accurate but incomplete.

## The correction

The boundary document describes `resources/js/pages/parent/dashboard.tsx` as a dead route and a design
draft. **Both are true and together they read as "safe to delete". It is not.**

- **The ROUTE is dead.** `routes/web.php` renders it after an unconditional
  `return redirect()->route('parent.wards')`, so the `Inertia::render('parent/dashboard')` beneath is
  unreachable. That much the document has right.
- **The MODULE is live.** `resources/js/pages/parent/wards.tsx` imports `NoticesCard` and
  `QuickContactCard` **from it**:

  ```ts
  import { NoticesCard, QuickContactCard } from './dashboard';
  ```

  So deleting the file breaks `/parent/wards`, which is a route parents actually reach.

The two facts live in different places — one in `routes/web.php`, one in an import at the top of a
sibling page — and the document only records the first. An agent or a person told "this is a dead
draft" and asked to clean it up would delete it, and the failure would surface as a build error on an
unrelated page.

## What it also contains, which is the other half of why it stays untouched

It is the 59KB visual draft for the parent portal, and it carries:

- `outstanding_balance: 185000` — a bare literal, in minor units, **invented**; the key does not exist
  on any API contract;
- a "Fee Balance" card and a **"Clear Balance" button** wired to nothing;
- money rendered with `.toLocaleString()`, which `bin/ci-money-lint.php` bans everywhere outside
  `resources/js/lib/format.ts`.

None of that is a defect *in a draft*. It becomes one the moment anybody treats its prop shape as an
API — which is the failure `/parent/finance` was deliberately built to avoid, and why
`resources/js/types/parent-finance.ts` says so at the top of the file rather than in a commit message
nobody will read again.

## The disposition

**Leave it exactly as it is** until someone extracts `NoticesCard` and `QuickContactCard` into their
own module. That extraction is the prerequisite for any tidy-up, and it is a small, safe change that
nobody has needed yet.

**If the tidy-up ticket is picked up**, the order is: extract the two components → repoint
`wards.tsx` → then decide about the rest of the file. Deleting first and discovering the import
second is the failure this ticket exists to prevent.

## Related

- `resources/js/types/parent-finance.ts` — why the draft is a picture and not a contract.
- `resources/js/pages/parent/finance.tsx` — the screen built from the endpoint instead.
