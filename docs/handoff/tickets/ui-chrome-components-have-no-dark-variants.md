# Four shared chrome components carry no `dark:` variants at all

**Raised by:** the cold review of `feat/ui-discount-policies-redesign`, 2026-08-16. That branch
reported dark mode as verified on the discount-policies screen; the modal screenshot it took is the
evidence against its own claim.

**Read alongside** [`dark-mode-is-unreachable-for-every-user.md`](dark-mode-is-unreachable-for-every-user.md).
That ticket says no user can reach dark mode. **This is what will be waiting when it is fixed** — the
two are a pair, and fixing that one without this one ships a toggle onto four broken surfaces.

## The facts

Counted 2026-08-16, `grep -c 'dark:'` on each file:

| Component                                      | `dark:` occurrences | File length | Files importing it |
| ---------------------------------------------- | ------------------- | ----------- | ------------------ |
| `resources/js/components/ui/Modal.tsx`         | **0**               | 92          | 32                 |
| `resources/js/components/ui/ConfirmDialog.tsx` | **0**               | 80          | 6                  |
| `resources/js/components/ui/EmptyState.tsx`    | **0**               | 38          | 9                  |
| `resources/js/components/ui/Toast.tsx`         | **0**               | 53          | 2                  |

Re-derive before acting:

```bash
for f in Modal ConfirmDialog EmptyState Toast; do
  echo "$f: $(grep -c 'dark:' resources/js/components/ui/$f.tsx)"
done
grep -rln 'ui/Modal' resources/js | wc -l
```

`Modal.tsx:68-86` is the whole of it: `bg-white`, `border-gray-200`, `text-gray-900`,
`text-gray-500`, `hover:bg-gray-100`, and a `border-t border-gray-200` footer — every one of them a
light-only literal, none paired. That is § 20's named "most common bug", and it is in the chrome
rather than in a page, so it is inherited by every form in the application at once. **Every
create/edit flow in this product is authored inside it.**

## The evidence

`docs/handoff/drives/2026-08-16-discount-policies/maker-05-modal-amount-dark.png` — the
discount-policy proposal modal, `.dark` set on `<html>`, captured during that branch's drive. The
screenshot was taken, filed, and read as a pass. It is not.

**Measured** rather than eyeballed, `getComputedStyle` in the running app with `.dark` on
`<html>` (`fix-07-dark-modal-measured.png` in the same directory):

```json
{
  "htmlHasDark": true,
  "modalPanelBg": "rgb(255, 255, 255)",
  "modalTitleColor": "oklch(0.21 0.034 264.665)",
  "fieldLabelColor": "oklch(0.95 0.01 260)",
  "legendColor": "oklch(0.929 0.013 255.508)"
}
```

The panel is **pure white in dark mode** — `Modal.tsx`'s unpaired `bg-white`. Its own title stays
`gray-900` and is readable, because it is unpaired in the same direction. The **field labels are
`oklch(0.95 …)`** and the **fieldset legend `oklch(0.929 …)`** — near-white, because those come from
the screen's correctly-`dark:`-paired classes, which flipped as designed. Near-white on white is a
contrast ratio of roughly **1:1**. Every field label in that form is invisible.

That is the shape of the failure and the reason it is worth a ticket rather than a line: the page
authors did their half right, and the shared chrome not doing its half turns correct code into
unreadable output. A screen that omitted its `dark:` variants would have looked *better* here.

That is the second-order finding here and the more useful one: **a dark-mode screenshot proves
nothing unless someone reads the contrast in it.** The branch captured both themes on every region
and still reported "every region reads correctly at that setting", because the check was "did I take
the screenshot" and not "what does it say".

## Why this is not a one-line fix

1. **No linter will tell you when you are done.** `resources/js/components/ui/*` is in **both**
   `.prettierignore` and the ESLint ignore list, so the lint step skips these files entirely
   (§ 26, "Shared components have a blast radius"). `tsc` and the build catch a type or syntax error
   and nothing else. A missed pairing is invisible until someone looks at a rendered page.
2. **The blast radius is 32 files for `Modal` alone**, and they are not uniform: some pass their own
   surface classes into the modal body, some rely on the modal's. Adding `dark:bg-card` to the panel
   changes what every one of those bodies sits on.
3. **It cannot be verified by a user today**, per the paired ticket, so verification means setting
   the class directly and reading the contrast — for each of the four components, in at least one
   consumer each, on purpose.

## What the fix looks like

- Pair every colour literal in the four files, preferring the semantic tokens that already flip
  (`bg-card`, `text-foreground`, `text-muted-foreground`, `border-border`) over adding `dark:`
  variants of `gray-*`. `Modal.tsx`'s panel is the one that matters most.
- Then open **one consumer of each** with `.dark` set and read the contrast, rather than confirming
  the screenshot exists. `Modal`: any Finance authoring screen. `ConfirmDialog`: a delete flow.
  `EmptyState`: a page-level empty. `Toast`: any mutation.
- Sequence this **before or with** `dark-mode-is-unreachable-for-every-user.md`. Shipping the toggle
  first makes four broken surfaces reachable by every user simultaneously, which is a worse day than
  the one we are having now.
