# `base-dropdown` cannot be operated from the keyboard

**Raised by:** cold review of `feat/ui-bank-accounts-fee-schedules-redesign` (2026-08-15), which
ruled that the branch's ARIA additions had to come back out. They did, in the same commit that filed
this. This ticket is the real fix that the ARIA was pretending to be.

**Six screens consume this component**, so it is not a one-file change:

| #   | Consumer                                                                                                   |
| --- | ---------------------------------------------------------------------------------------------------------- |
| 1   | `resources/js/pages/admin/finance/bank-accounts.tsx`                                                       |
| 2   | `resources/js/pages/admin/finance/fee-schedules.tsx` (filter row **and** the fee-line form inside a modal) |
| 3   | `resources/js/pages/admin/finance/index.tsx`                                                               |
| 4   | `resources/js/pages/admin/students/index.tsx` (including a per-row status select)                          |
| 5   | `resources/js/pages/admin/teachers/index.tsx` (per-row status select, `:382`)                              |
| 6   | `resources/js/pages/admin/teacher-assignments/index.tsx`                                                   |

## What is true today

`resources/js/components/ui/base-dropdown.tsx` is a trigger `<button>` that toggles a panel of
option `<button>`s portalled to `document.body`. **There is no keyboard handling anywhere in the
file:**

- no `onKeyDown` on the trigger or the panel — so no ArrowDown/ArrowUp to move through options, no
  Home/End, no type-ahead;
- **no Escape handler.** The only close paths are `handleClickOutside` (a `click` listener) and
  selecting an option. A keyboard user who opens it cannot close it without clicking;
- no focus management — focus is never moved into the panel on open, never restored to the trigger on
  close, and never trapped;
- no `aria-activedescendant`, and no roving `tabindex`;
- `handleSelect` is wired to `onClick` only.

The option buttons _are_ reachable by Tab, because they are real buttons — but they are portalled to
the end of `<body>`, so tabbing forward from the trigger goes to whatever follows the trigger in the
page, not into the panel. The panel's contents sit at the far end of the tab order, detached from the
control that produced them.

## Why the ARIA was removed rather than kept

The branch briefly added `aria-haspopup="listbox"` and `role="listbox"` / `role="option"` /
`aria-selected`. Those were removed in the same commit that filed this ticket, and the reasoning is
worth keeping because it is the general rule:

**ARIA describes an interaction contract; it does not implement one.** Announcing `listbox` tells a
screen-reader user that arrow keys move a selection, that Enter commits it, and that Escape dismisses
— none of which is true here. That is strictly worse than the plain `<button>` the component
honestly is, because a button at least sets expectations the component can meet. The one attribute
kept, `aria-expanded`, is valid on a disclosure button and is backed by real behaviour.

So: **the ARIA is not the fix, and must not be re-added on its own.** It goes back only as part of
the keyboard implementation below, in the same change.

## What the fix has to cover

Follow the APG combobox/listbox pattern rather than inventing one:

- **Keys on the trigger:** Enter / Space / ArrowDown open (ArrowDown opening onto the first option,
  ArrowUp onto the last).
- **Keys while open:** ArrowDown / ArrowUp move the active option, Home / End jump, printable
  characters type-ahead, Enter selects, Escape closes **and returns focus to the trigger**, Tab
  closes and moves on.
- **Focus management:** either move real focus into the panel (with a roving `tabindex`) or keep
  focus on the trigger and use `aria-activedescendant` — pick one and be consistent. Restore focus to
  the trigger on every close path, including outside-click.
- **Then** re-add `role="listbox"` on the panel, `role="option"` + `aria-selected` on each option,
  `aria-haspopup="listbox"` and `aria-controls` on the trigger, and an id on the panel.
- **Keep every `data-*` attribute.** `data-slot="base-dropdown-trigger"`,
  `data-slot="base-dropdown-panel"` and `data-value` are what drive scripts read to check School
  isolation by id, and the reason they exist is that this control — unlike a native `<select>` —
  exposes no value to the DOM otherwise. They are inert and must survive the rewrite.
- **Consider the alternative honestly first.** `resources/js/components/ui/select.tsx` (Radix) is
  already in the repo and is keyboard-correct out of the box. The documented reason the Finance UI
  hand-rolls instead is that Radix's `SelectItem` cannot take `value=""`, and an unselected
  "choose one, or leave empty" state is a designed path here
  (`new-invoice-modal.tsx:510-517`). Whether that is worth a bespoke keyboard implementation across
  six screens is a real decision and should be made explicitly rather than inherited.

## How to verify it

There is **no JS test runner** in this repo ([`no-javascript-test-runner.md`](no-javascript-test-runner.md)),
so nothing about this is assertable by the suite. It needs a drive, keyboard-only, across at least:
a filter-row select, a select inside the fee-schedule modal's scrollable body, and a **per-row**
select in a table (consumer 5, `teachers/index.tsx:382`) — the three structurally different mountings.
Include a screen reader if one is available; the ARIA is the half that cannot be checked by watching
the screen.

## Related

- [`base-dropdown-repositioning-is-unmeasured-and-unclamped.md`](base-dropdown-repositioning-is-unmeasured-and-unclamped.md)
  — the other outstanding work on this component, from the same review.
- [`no-javascript-test-runner.md`](no-javascript-test-runner.md) — why none of this is gate-checkable.
