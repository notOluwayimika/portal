# Dark mode is unreachable for every user

**Raised by:** the drive on `feat/ui-bank-accounts-fee-schedules-redesign` (2026-08-15), which needed
to look at two redesigned screens in dark mode and found it could not get there through the
application.

**Not fixed there, deliberately.** Appearance handling is app-wide; verifying a fix needs its own
drive across considerably more than two screens, and folding it into a UI redesign is exactly how a
visual change acquires a functional one nobody asked it to carry.

> **Corrected 2026-08-15 after cold review.** The first version of this ticket said `applyTheme` was
> the only writer of the `dark` class and described the cause as JavaScript-only. Both were wrong:
> there are **three** writers, two of them in Blade, and the thing that disables all of them is a
> **PHP** line this ticket did not mention. It also called the JS a stub; it is the deliberate
> removal of a shipped feature. What follows is the corrected account — the conclusion (no user can
> reach dark mode) is unchanged, the mechanism and therefore the fix are not.

## What is true today

This was **removed on purpose, in one commit, on both sides at once.**

`git log -- app/Http/Middleware/HandleAppearance.php` reaches `83447b3` — _"feat: remove dark mode"_,
2026-05-25 — which changed seven files and, in the two that matter, made exactly these edits:

```diff
--- a/app/Http/Middleware/HandleAppearance.php
-        View::share('appearance', $request->cookie('appearance') ?? 'system');
+        View::share('appearance', 'light');

--- a/resources/js/hooks/use-appearance.tsx
-    return appearance === 'dark' || (appearance === 'system' && prefersDark());
+    return false;
```

The PHP cookie read and the JS predicate were deleted **together**. This is not an unfinished stub
and not a bug someone left behind; it is a product decision, and that changes what "fixing" it means
— it is a decision to revisit, not a defect to repair. Whoever picks this up should find out why it
was removed before restoring it.

### There are THREE writers of the `dark` class, not one

**Writer 1 — Blade, server-side.** `resources/views/app.blade.php:2`:

```blade
<html lang="…" @class(['dark'=> ($appearance ?? 'system') == 'dark'])>
```

**Writer 2 — an inline script, before React boots.** `resources/views/app.blade.php:9-21`:

```blade
const appearance = '{{ $appearance ?? "system" }}';
if (appearance === 'system') {
  const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
  if (prefersDark) { document.documentElement.classList.add('dark'); }
}
```

**Writer 3 — `applyTheme()`, at runtime.** `use-appearance.tsx:49-52`, reached from all three
theme-changing paths: `initializeTheme()` at `:84` (called from `app.tsx:42` on boot),
`updateAppearance()` at `:110` (the tab click in `appearance-tabs.tsx:31`), and
`handleSystemThemeChange()` at `:71` (the OS media-query listener subscribed at `:87`).

### What disables all three — and it is one PHP line

`app/Http/Middleware/HandleAppearance.php:19`:

```php
View::share('appearance', 'light');
```

**Hard-coded. The cookie is never read.** So `$appearance` is `'light'` on every render, and:

- Writer 1's `@class` tests `'light' == 'dark'` → false. The server never emits the class.
- Writer 2's guard tests `'light' === 'system'` → false. The inline script's body never runs, so the
  OS preference is never consulted at first paint either.
- Writer 3 is disabled separately, by the JS half of `83447b3`: `isDarkMode` **ignores its parameter
  and returns a constant `false`** (`use-appearance.tsx:40-42`), so `applyTheme` runs
  `classList.toggle('dark', false)` on load, on every user selection, and on every OS theme change.

Tailwind's variant is `@custom-variant dark (&:is(.dark *))`, so with no writer able to add the
class, nothing under `dark:` can ever match.

Note `bootstrap/app.php:48` — `$middleware->encryptCookies(except: ['appearance', 'sidebar_state'])`.
The appearance cookie is still exempt from encryption, i.e. still **readable** by the middleware that
no longer reads it. The plumbing for the removed feature is intact on both sides; only the two
predicates were cut.

### The second call site

**`:97`, inside the `useAppearance()` hook.**

```ts
const resolvedAppearance: ResolvedAppearance = isDarkMode(appearance)
    ? 'dark'
    : 'light'; // :97-99
```

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
mode and captured nine light/dark screenshot pairs. **Those screenshots were produced by setting
`document.documentElement.classList.add('dark')` directly from the drive script** — not by using the
application's appearance control, which was tried and does nothing.

Read them accordingly: they are evidence that **the branch's `dark:` pairs are correct and will
render properly once the toggle works**. They are _not_ evidence that a user can see any of it. Any
earlier drive in `docs/handoff/drives/` that reports on dark mode is under the same caveat, and any
future one must state which of the two it did.

## What a fix has to cover

**It is a PHP change AND a JS change, and the first version of this ticket named only the JS.**
Restoring `isDarkMode` alone leaves `HandleAppearance` sharing `'light'`, so:

- the **server-rendered first paint is always light**, on every page load, for every user. The class
  is then added by `applyTheme` once React boots, so `html.dark`'s background
  (`app.blade.php:30-32`, `background-color: oklch(0.145 0 0)`) does eventually apply — but only
  after the light document has already painted. A dark-mode user gets a white flash on every
  navigation, which is the single most visible symptom of getting this half-right;
- **`system` never resolves at first paint at all.** Writer 2's guard needs `$appearance === 'system'`
  to reach the media query, and it cannot while the middleware hard-codes `'light'`.

So:

- **`HandleAppearance.php:19` must read the cookie again** — `$request->cookie('appearance') ?? 'system'`
  is what `83447b3` removed. `bootstrap/app.php:48` already leaves that cookie unencrypted, so
  nothing else is needed to make it readable.
- Implement `isDarkMode` for all three `Appearance` values, using `prefersDark()` for `'system'`
  (that is what it exists for), and delete it if the fix takes another route rather than leaving it
  dead.
- Check that the cookie and `localStorage` cannot disagree. `updateAppearance` writes both
  (`:105-108`), but Blade reads the cookie and `getStoredAppearance` reads `localStorage`; a user who
  clears one and not the other gets a first paint that disagrees with the runtime theme.
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
