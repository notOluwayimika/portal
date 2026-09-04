# Drive — the shared Modal in dark mode

**Change:** `resources/js/components/ui/Modal.tsx` · **Date:** 2026-09-04 · **Branch:**
`fix/modal-participates-in-dark-mode` · **Instance:** `APP_ENV=drive`, `portal_drive`, `localhost:8001`.

**`.dark` is set DIRECTLY on `<html>`.** No user can reach dark mode
(`dark-mode-is-unreachable-for-every-user.md`), so nothing here is a claim to have seen it as a user
would. Computed styles are read out of the live DOM, not inferred from the class list.

Two dialogs, from two modules, in both themes, in three states of the code: **before** (HEAD),
**after** (the fix), **mutation** (the fix with only `dark:bg-card` removed).

## The panel, measured

| state | theme | panel background | panel border | title |
| --- | --- | --- | --- | --- |
| **before** | light | `rgb(255,255,255)` | `oklch(0.928 …)` gray-200 | `oklch(0.21 …)` gray-900 |
| **before** | **dark** | `rgb(255,255,255)` | `oklch(0.928 …)` | `oklch(0.21 …)` |
| **after** | light | `rgb(255,255,255)` | `oklch(0.968 …)` slate-100 | `oklch(0.208 …)` slate-900 |
| **after** | **dark** | **`oklch(0.22 0.02 260)`** `bg-card` | `oklch(0.279 …)` slate-800 | **`rgb(255,255,255)`** |
| **mutation** | **dark** | **`rgb(255,255,255)`** | `oklch(0.279 …)` | **`rgb(255,255,255)`** |

**BEFORE, LIGHT AND DARK ARE BYTE-IDENTICAL.** Every value in the two `before` rows is the same
number. That is the defect stated as a measurement rather than as a screenshot: the modal did not
participate in dark mode at all, on either dialog.

**AFTER, the panel is a dark surface**, the hairlines are slate-800, the title is white, and the
close button is slate-400.

## V2 — the mutation, and it is worse than the original

Removing **only** `dark:bg-card` from the panel returns the white glare panel — and does something
the pre-fix code did not: the title stays `dark:text-white`, so the reading is

```
panelBg  rgb(255, 255, 255)
title    rgb(255, 255, 255)
```

**White on white — the title disappears entirely.** So the panel class is load-bearing for more than
the panel: the rest of the fix depends on it, and a half-applied version is worse than none. That is
the mutation earning its place — "a rendering fix nobody has watched fail is a screenshot, not a
proof" — and it failed harder than expected.

Screenshots: `mutation-ia-return-dark.png`, `mutation-bank-accounts-dark.png`.

## Light mode — what actually moved

Not "nothing", and the difference is worth naming rather than waving through:

| element | before | after | verdict |
| --- | --- | --- | --- |
| panel background | `rgb(255,255,255)` | `rgb(255,255,255)` | identical |
| overlay scrim | `oklab(0 0 0 / 0.4)` | `oklab(0 0 0 / 0.4)` | identical, untouched |
| title | `oklch(0.21 0.034 264.665)` | `oklch(0.208 0.042 265.755)` | **ΔL 0.002** — indistinguishable |
| close button | `oklch(0.551 0.027 264.364)` | `oklch(0.554 0.046 257.417)` | **ΔL 0.003** — indistinguishable |
| hairlines | `oklch(0.928 0.006 264.531)` | `oklch(0.968 0.007 247.896)` | **ΔL 0.040 — lighter** |

Three of the five are unchanged or imperceptible; `gray-900`→`slate-900` and `gray-500`→`slate-500`
are hue moves at the same lightness. **The one visible light-mode change is the hairline**, because
§2 assigns `border-slate-100` to the Hairline role and `gray-200` was both the wrong family and a
darker step. That is the document being applied, and it is the whole of the light-mode delta.

## The two dialogs

- **`/internal-audit/review-queue` → Return** (internal-audit), as `auditor@drive.test`. A form
  modal, and the one whose helper text is a privacy mitigation.
- **`/finance/bank-accounts` → Add account** (finance), as `maker@drive.test`. Chosen for being a
  different module with a different form inside the same container.

Both read identically at the panel level in every state, which is the point: the fix is in the
container and both inherit it.

## What is still wrong inside the container — reported, not fixed

**The IA dialog's muted text has no dark pair.** Its paragraphs stay `oklch(0.554)` (slate-500) in
dark mode, where §2's Muted-text role wants `slate-400` on a dark surface. The bank-accounts dialog
gets this right — its paragraph reads `oklch(0.704)` (slate-400) in dark. So the same container now
hosts one form that pairs its muted text and one that does not.

**`ConfirmDialog`'s inner controls are still `gray-*`** — its Cancel button, its message and its
confirmation input. Its *panel* is fixed by this change because it renders `<Modal>`; its contents
are a call site and this commit does not touch call sites.

Both are the same defect class one layer in, and both belong to whoever sweeps the forms.

## Console

Clean on both pages across all three runs — vite HMR notices and the React DevTools banner only, no
`pageerror` and no failed request. One exception, on the bank-accounts page and **present in the
`before` run too**, so it is not this change: `[seat2 error] Failed to load resource: … 403
(Forbidden)`. A CSS-only diff cannot cause it; recorded here because it was seen, and left for
whoever owns that screen.
