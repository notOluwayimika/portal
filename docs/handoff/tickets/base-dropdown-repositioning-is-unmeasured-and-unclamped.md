# `base-dropdown` repositioning: one structural case unmeasured, no viewport clamp, no throttle

**Raised by:** cold review of `feat/ui-bank-accounts-fee-schedules-redesign` (2026-08-15), against
the scroll-tracking fix that branch added to `resources/js/components/ui/base-dropdown.tsx`.

**Not a report that the fix is wrong.** The fix was driven and measured on two untouched consumers
(`/finance`, `/students`) and inside the fee-schedule modal's scrollable body; the panel tracked its
trigger in all three, `deltaTop` pinned at 38px. These are the three things that drive did **not**
measure, in either direction. Fix none of them without a drive.

## Background: what the branch changed

The panel is portalled to `document.body` and positioned `fixed`. Before the branch,
`updateDropdownPosition()` was called **once, from `toggleDropdown()`** — so the coordinates were
correct for the scroll position at open, and the panel detached from its trigger whenever anything
scrolled afterwards. The branch added a `scroll` (capture) + `resize` listener while open, so the
panel now re-measures and follows.

## 1. Horizontal scroll inside a table container — never measured, in either direction

`updateDropdownPosition()` sets **`left` as well as `top`**:

```ts
setDropdownStyle({
    position: 'fixed',
    top: rect.bottom + 4,
    left: rect.left,
    width: rect.width,
    zIndex: 9999,
});
```

So the panel now tracks **horizontal** movement too, not only vertical. Every driven case scrolled
vertically only.

The case that exercises this is a **per-row `Select` inside an `overflow-x-auto` container** —
`resources/js/pages/admin/teachers/index.tsx:382` (a `<Select>` in a `<td>`), inside the scroll
region opened at `:305`. `students/index.tsx` has the same shape. Scrolling that container sideways
with a panel open now drags the panel horizontally across the page.

**Which behaviour is correct here is not obvious**, and that is the point:

- tracking is right if you think of the panel as attached to its trigger;
- but the trigger can scroll **out of its own container** while the panel follows it under the
  table's clipped edge, or out from under the pointer entirely.

Nobody has watched either the old or the new behaviour on that mounting. Measure both before
deciding — the old behaviour is one `git stash` away.

## 2. No viewport clamp — the panel can now follow its trigger off-screen

The style above contains no clamping of any kind: no `max(0, …)`, no flip-above-when-near-the-bottom,
no constraint to the viewport.

Measure-once made this mostly harmless: the panel was placed where the trigger was **at open** and
stayed there, so it remained on screen even as the trigger left. Tracking removes that accident. A
panel whose trigger scrolls above the fold now follows it to a negative `top`, sliding under the
fixed top bar and off the top of the viewport, while still being open and still holding focusable
buttons.

This is a **behaviour the branch introduced**, not a pre-existing one, and it was not measured. The
usual remedies — clamp `top` to the viewport, flip above the trigger when there is no room below,
or close the panel once the trigger scrolls out of view — are all reasonable and all need to be
looked at rather than guessed.

## 3. `reposition` is unthrottled

```ts
const reposition = () => updateDropdownPosition();
document.addEventListener('scroll', reposition, true);
```

Every scroll event calls `setDropdownStyle`, which is a React state update, which re-renders the
component and the portalled panel. No `requestAnimationFrame`, no throttle, no comparison against the
previous rect to skip a no-op update.

Scroll events fire at up to one per frame per scrolling element, and `capture: true` means **every**
scrolling ancestor delivers them. Nothing was observed to stutter on the driven pages, on a desktop,
with panels of two to six options — but "it looked fine on a fast machine" is not a performance
measurement, and the obvious fix (coalesce into a single `requestAnimationFrame`) is cheap enough
that the only reason not to do it now is that it should be measured with the other two.

## What to do

Treat all three as one piece of work on this component, and drive it:

- a per-row select inside `overflow-x-auto`, scrolled **horizontally**, old behaviour and new;
- a trigger scrolled off the top of the viewport with the panel open;
- a scroll profile (DevTools performance) with a panel open on a long list.

Then decide clamping and throttling from what you saw, not from this ticket.

## Related

- [`base-dropdown-is-not-keyboard-operable.md`](base-dropdown-is-not-keyboard-operable.md) — the
  other outstanding work on this component; both should probably be done together, since a keyboard
  rewrite touches the same open/close paths.
- [`no-javascript-test-runner.md`](no-javascript-test-runner.md) — why none of this is gate-checkable.
