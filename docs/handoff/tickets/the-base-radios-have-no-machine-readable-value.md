# TICKET — the discount base radios have no machine-readable value

**Status:** open, not user-facing. Found by the axis-C drive
(`docs/handoff/drives/2026-08-27-discount-base/`), which had to work around it.

## The fact

The two base radios on `/finance/discount-policies` render `value="on"` — the browser's default for a
checked input with no `value` attribute — and `hasValueAttr: false` on both. The only thing in the
DOM that distinguishes "discountable" from "total" is the **prose label**.

## Why that matters, given the labels are the point

The whole axis exists because `50%` is not a term anybody can approve: half the tuition and half the
whole bill are different amounts of money, and the phrase is what carries the difference. Every screen
was deliberately made to say it in words, from one shared `baseLabel`.

The cost lands on anything reading the screen rather than a person. The drive that found this had to
read labels to know which radio was selected, and then confirm the real value **off the wire** — which
is the correct method and is also the reason it took a request interception to answer a question a
`data-value` would have answered from the DOM.

The `finance-drive` skill's own rule is *"read the option VALUES out of the DOM, not the option text"*,
because two schools' identically-labelled rows are indistinguishable by label. That rule cannot be
followed on this control at all.

## The precedent is one file away

`resources/js/components/ui/base-dropdown.tsx:214` puts `data-value` on every option, and its comment
at `:202-207` gives this drive's own reason for doing so. The pattern is established, in the same
directory, for the same purpose.

## What closes it

`data-value={…}` on each radio, matching `base-dropdown`'s shape. One line per option.

It was deliberately **not** fixed during the drive: a drive that repairs what it finds destroys the
evidence, and this one's whole subject was whether the control is bound. Fixing the handle mid-run
would have changed the thing being observed.

Worth doing before the next drive of this screen rather than after, since that drive will otherwise
pay the same workaround — but it is a testability improvement, not a defect, and it does not gate the
BSS work.
