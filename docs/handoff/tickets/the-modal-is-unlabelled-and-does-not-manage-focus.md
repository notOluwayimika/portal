# The shared Modal is an unlabelled dialog and manages no focus

**Status:** open · **Opened:** 2026-09-04 · **Component:**
`resources/js/components/ui/Modal.tsx` · **Consumers:** 31 · **Section:** §21 (accessibility)

Two defects, both real, both **deliberately excluded** from
`fix/modal-participates-in-dark-mode` — that commit is a colour change to the same file, and a
one-line ARIA attribute smuggled into a palette diff is a change nobody reviews as a change.

## 1. The dialog has a name on screen and no name in the accessibility tree

`Modal.tsx` carries `role="dialog"` (`:59`) and `aria-modal="true"` (`:60`) and **no
`aria-labelledby`**. The `<h2>` holding the dialog's name is two lines below at `:72` and carries
**no `id`**.

So a screen reader announces *"dialog"* — unnamed — while the name sits in the DOM immediately
inside it. Every one of the **31** consumers inherits this, including the IA return dialog, whose
helper text is a privacy mitigation an auditor is meant to read before typing.

**The fix is two attributes**: an `id` on the `<h2>` and `aria-labelledby` pointing at it. The id
must be generated (`useId()`) rather than a constant, because two modals can be mounted at once and
a duplicate id makes the reference ambiguous — which is the whole reason this is being ticketed
rather than typed.

## 2. Nothing moves, traps or restores focus

The single `useEffect` (`:34`) binds Escape and nothing else. There is no ref, no `focus()` call and
no focus-restore anywhere in the file — read, not assumed: `grep` for `focus` and `ref` in
`Modal.tsx` returns nothing.

Three consequences, in the order a keyboard user meets them:

- **On open**, focus stays on the trigger behind the overlay. A keyboard user must tab forward
  through the page to reach a dialog that is already covering it.
- **While open**, focus is not trapped, so Tab walks out of the dialog and into the inert page
  underneath — which `aria-modal="true"` has just told assistive technology is *not there*. The
  attribute and the behaviour disagree, and the attribute is the one being believed.
- **On close**, focus is not restored, so it lands at the top of the document.

`aria-modal="true"` **without a trap is a promise the component does not keep** — §26's own recorded
pattern: *"Do not promise semantics you have not implemented … a listbox that cannot be operated by
keyboard is a broken promise to the users who most depend on it."* This is that entry, in a
different component.

## Why they are one ticket and not two

They share a file, a section and a consumer set, and the second is the reason the first is not just
typed in passing: **labelling is one attribute, focus management is behaviour with edge cases** —
nested modals, a dialog whose trigger unmounts while it is open, the close-by-backdrop path, and
`inert` versus a manual trap. That deserves its own proof (a driven keyboard walk, not a
screenshot), and doing the cheap half alone would leave `aria-modal` still lying while making the
component look attended to.

## What to check when it is done

- The `id` is generated per instance, and two simultaneously-mounted modals do not collide.
- Focus lands **inside** the dialog on open — on the first focusable element, or on the panel
  itself when the dialog has no control.
- Tab and Shift+Tab both cycle **within** the dialog.
- Focus returns to the element that opened it, including when the dialog was dismissed by Escape
  and by backdrop click, which are different code paths.
- Driven, not asserted: this is behaviour no test in this repository can currently see — vitest runs
  in `node` and nothing renders the component.
