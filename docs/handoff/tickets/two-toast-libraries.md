# TICKET — this app ships two toast libraries with both containers mounted, and nothing states which one wins

**Status:** open, deliberately not implemented. The project lead has ruled that both stay for now:
*"I will advise you to leave sonner and react-toastify, if it is not an issue."* This ticket records
the measurement behind that ruling so that whoever converges them later starts from numbers rather
than from an impression — and so the next person to notice the split does not re-run this
investigation from zero.

## The measurement

Taken on `staging` at `e484a46`, working tree clean.

```
$ grep -rl "react-toastify" resources/js | wc -l
39

$ grep -rl "from 'sonner'" resources/js | wc -l
17

$ grep -rl "components/ui/sonner" resources/js
resources/js/app.tsx

$ comm -12 <(grep -rln "react-toastify" resources/js | sort) <(grep -rln "from 'sonner'" resources/js | sort)
(no output)
```

39 files reach for `react-toastify`; 18 reach for `sonner` (17 importing it directly, one of which is
the `components/ui/sonner.tsx` wrapper itself, plus `app.tsx` which imports the wrapper). **No file
imports both.** The split is clean at file granularity and there is no mixed file to untangle.

Both are declared dependencies — `package.json:72` `"react-toastify": "^11.1.0"` and `package.json:74`
`"sonner": "^2.0.0"`.

## Both containers are mounted, in different places

```
$ grep -rnE "ToastContainer|<Toaster" resources/js
resources/js/app.tsx:31:                    <Toaster />
resources/js/layouts/app-layout.tsx:1:import { Slide, ToastContainer } from 'react-toastify';
resources/js/layouts/app-layout.tsx:20:            <ToastContainer
```

Nothing else in `resources/js` mounts a `ToastContainer`. The two mounts are **not** at the same
level, and that asymmetry is the only part of this situation with a behavioural consequence:

- Sonner's `<Toaster />` is rendered inside `withApp` in `app.tsx`, which wraps every page.
- react-toastify's `<ToastContainer>` is rendered inside `AppLayout`.

`app.tsx`'s layout resolver (`resources/js/app.tsx:13-24`) assigns `null` for `welcome`, `AuthLayout`
for `auth/*`, `[AppLayout, SettingsLayout]` for `settings/*`, and `AppLayout` otherwise. So on
`welcome` and on the seven screens under `resources/js/pages/auth/`, **no `ToastContainer` exists**. A
`react-toastify` call from one of those screens resolves, returns an id, and renders nothing. No
console error, no thrown exception, no visual difference from a call that was never made.

Today this is latent, not live:

```
$ grep -rn "toast" resources/js/pages/auth resources/js/pages/welcome.tsx
(no output)
```

No auth or welcome screen raises a toast at all. The trap is for the next person who adds one — for
example a "Password reset link sent" on `forgot-password.tsx` — and reaches for `react-toastify`
because 39 files do.

## Where each library is used

`sonner` is the newer surface and carries the server's own messages. `resources/js/hooks/use-flash-toast.ts`
subscribes to Inertia's `flash` event and calls sonner's `toast[data.type]`, so **every server-side
flash toast in the application lands in the sonner container**, regardless of which library the page
itself imports. It is also used by the Excel/CSV import surfaces (`use-excel-import.tsx`, the student,
teacher and guardian import forms), the SweetAlert confirmation hook, the comment screens for four
roles, and three Finance pages.

`react-toastify` is the older and much larger surface — setup tabs, curricula, RBAC, students,
guardians, teachers, notices, parent screens, and seven Finance files.

Finance is not on one side of this. It uses **both**: the four modals under
`resources/js/components/finance/` (`new-invoice-modal`, `record-payment-modal`,
`issue-credit-note-modal`, `request-void-modal`) plus `pages/admin/finance/approvals.tsx`,
`pages/admin/finance/opening-balances/import.tsx` and `pages/admin/finance/receipt.tsx` are
react-toastify; `pages/admin/finance/bank-accounts.tsx`, `discount-policies.tsx` and
`fee-schedules.tsx` are sonner. The three sonner ones are the three redesigned under the design
system; the split tracks when the file was last rewritten, not what module it belongs to.

## What the guide said, and what it says now

`docs/ui-ux-design-system.md` §13 asserted a single rule — "a `react-toastify` toast" — as though the
question were settled. That sentence matched the app-wide majority but not the three most recently
redesigned Finance screens, which are precisely the screens §13 is otherwise the reference for. §26
tells readers to trust the tree over the guide; a guide sentence that is quietly wrong is the failure
mode §26 exists to catch, applied to the document itself.

§13 now describes both libraries, both mount points, the flash-toast path, the file split, and the
auth/welcome trap, and links here for the decision.

## What is not measured here

**Shipped bytes.** Both packages are installed through pnpm, so `du -sh node_modules/react-toastify
node_modules/sonner` reports `0` for both and is not a usable figure. Nobody has measured what either
library contributes to the built bundle. Whoever picks this up should measure it rather than assume
the duplication is expensive:

> UNVERIFIED — I have not run this. `pnpm run build`, then read the per-chunk sizes vite prints, or
> add `rollup-plugin-visualizer` temporarily and compare a build with each library's container
> stubbed out.

## Why converging now was rejected

Not on aesthetic grounds. `no-javascript-test-runner.md` is still open: there is no runner, no test
file, and no gate step that executes a line of application JavaScript. A migration of 39 files (or of
18) is a mechanical sweep with no automated check behind it, and the surface being swept is *the one
that reports failure to the user*. A toast that silently stops rendering after a migration looks
exactly like an operation that succeeded quietly. That is the same shape as the state-collapse defect
recorded five times in `docs/ui-ux-design-system.md` §26 — a UI that reports the wrong thing rather
than crashing — and it would be shipped without a single assertion against it, days before cutover.

## Not proposed here

Which library wins, whether the loser is removed from `package.json`, and whether the sweep is one
commit or one per module are all open. Two things are worth carrying into that decision when it is
made: `use-flash-toast.ts` means sonner is already load-bearing for server-driven messages, so
"remove sonner" is not the smaller change it looks like from the 39-vs-18 count; and the auth/welcome
gap above is a real defect surface that disappears on its own if sonner wins and needs an explicit
second mount if react-toastify does.

A sensible trigger for revisiting this is the same one attached to `no-javascript-test-runner.md` —
once a runner exists, a sweep of this shape becomes checkable, and the argument above expires.
