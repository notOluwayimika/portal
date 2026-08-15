# Dark mode is unreachable for every user

**Raised by:** the drive on `feat/ui-bank-accounts-fee-schedules-redesign` (2026-08-15), which needed
to look at two redesigned screens in dark mode and found it could not get there through the
application.

**Not fixed there, deliberately.** Appearance handling is app-wide; verifying a fix needs its own
drive across considerably more than two screens, and folding it into a UI redesign is exactly how a
visual change acquires a functional one nobody asked it to carry.

## What is true today

`resources/js/hooks/use-appearance.tsx:40-42`:

```ts
const isDarkMode = (appearance: Appearance): boolean => {
    return false;
};
```

The function **ignores its parameter and returns a constant `false`**. It is not a stub awaiting a
branch — it takes the one argument that would decide the answer and discards it.

### Both call sites, and what each one drives

There are exactly two, and both are therefore constant.

**Call site 1 — `:49`, inside `applyTheme()`.** This is the one that matters.

```ts
const isDark = isDarkMode(appearance); // :49  — always false

document.documentElement.classList.toggle('dark', isDark); // :51
document.documentElement.style.colorScheme = isDark ? 'dark' : 'light'; // :52
```

`applyTheme` is the **only** writer of the `dark` class on `<html>`, and it is reached from all three
paths that could ever change the theme:

- `initializeTheme()` at `:84`, on every page load;
- `updateAppearance()` at `:110`, when the user clicks a tab in
  `resources/js/components/appearance-tabs.tsx:31`;
- `handleSystemThemeChange()` at `:71`, when the OS `prefers-color-scheme` media query fires
  (subscribed at `:87`).

So `classList.toggle('dark', false)` runs on load, on every user selection, and on every OS theme
change. **The `dark` class is never added, by any path.** Tailwind's variant is
`@custom-variant dark (&:is(.dark *))`, so nothing under `dark:` can ever match.

**Call site 2 — `:97`, inside the `useAppearance()` hook.**

```ts
const resolvedAppearance: ResolvedAppearance = isDarkMode(appearance)
    ? 'dark'
    : 'light'; // :97-99
```

`resolvedAppearance` is therefore the literal string `'light'` forever. One consumer reads it:
`resources/js/components/two-factor-setup-modal.tsx:65,86` uses it to decide whether to apply
`filter: invert(1) brightness(1.5)` to the 2FA QR code. That inversion consequently never applies —
currently harmless, because the surface behind it never goes dark either, and it would start working
on its own once `isDarkMode` is fixed.

### The preference is stored, and honoured by exactly one component

`updateAppearance()` still writes `localStorage.appearance` and the `appearance` cookie (`:105-108`)
before calling the no-op `applyTheme`. So the user's choice is durably recorded and then ignored.

One component reads the **raw** `appearance` rather than `resolvedAppearance` and so escapes the
bug: `resources/js/components/ui/sonner.tsx:6,12` passes `theme={appearance}` straight to Sonner,
which resolves `'system'` itself. **A user who selects "Dark" therefore gets dark toast
notifications on a light application** — which is worse than a toggle that does nothing, because it
looks like the theme is partly working and invites a hunt for the pages that "haven't been done yet".

`prefersDark()` (`:15-21`) is now dead: nothing calls it. It is the shape of the check `isDarkMode`
was evidently meant to perform for the `'system'` case.

## Why this matters more than a missing feature

**Every `dark:` utility in this codebase is unverified by use.** There are thousands of them, they
are required by [`docs/ui-ux-design-system.md`](../../ui-ux-design-system.md) § 20 ("Every colour
utility needs a `dark:` counterpart … Test both themes before shipping"), and they are reviewed
against that rule on every change — but **§ 20 has never been exercised by a user**, on any page, at
any point that this behaviour has been present. The guide's own warning that "a missing `dark:` shows
as white-on-white / invisible text" describes a failure mode that no user can currently encounter and
no reviewer can currently see.

The cost is not the missing theme. It is that the moment `isDarkMode` is fixed, **every dark-mode
defect accumulated across the whole application ships at once**, in a single release, with no history
of anyone having looked at any of it.

### This applies to the drive that found it

The drive on `feat/ui-bank-accounts-fee-schedules-redesign` reported both redesigned screens in dark
mode and captured twelve light/dark screenshot pairs. **Those screenshots were produced by setting
`document.documentElement.classList.add('dark')` directly from the drive script** — not by using the
application's appearance control, which was tried and does nothing.

Read them accordingly: they are evidence that **the branch's `dark:` pairs are correct and will
render properly once the toggle works**. They are _not_ evidence that a user can see any of it. Any
earlier drive in `docs/handoff/drives/` that reports on dark mode is under the same caveat, and any
future one must state which of the two it did.

## What a fix has to cover

Not just the three-line function:

- Implement `isDarkMode` for all three `Appearance` values, using `prefersDark()` for `'system'`
  (that is what it exists for), and delete it if the fix takes another route rather than leaving it
  dead.
- `handleSystemThemeChange` (`:71`) must then actually re-resolve — it already re-calls `applyTheme`,
  so it should start working, but that needs proving rather than assuming.
- **Then drive it.** Well beyond the two screens this ticket came from: at minimum the sidebar and
  top bar, a list page, a detail page, a modal, a dropdown panel (which portals to `<body>`, outside
  the page's own subtree), the toast, and the auth screens. Expect real findings — this is the first
  time any of it will have been seen.
- The `resolvedAppearance` consumer at `two-factor-setup-modal.tsx:86` starts applying an inversion
  filter it has never applied before; look at the QR code in both themes and confirm it still scans.

## Cross-references

- Same class, found by the same drive, filed separately:
  [`students-index-403s-render-two-placeholder-only-selects.md`](students-index-403s-render-two-placeholder-only-selects.md)
  — a screen that returns 200 while rendering a control with nothing in it.
- [`no-javascript-test-runner.md`](no-javascript-test-runner.md) — why this could not have been
  caught by the suite. There is no test that renders a component and asserts a computed style, so a
  constant-`false` theme resolver is invisible to every gate the project has; only a human or a drive
  script looking at a page can see it.
