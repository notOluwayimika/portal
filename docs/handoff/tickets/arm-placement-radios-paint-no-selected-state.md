# The arm-placement radios paint no selected state, so the control reads as dead

**Found:** review of the progression modal on `feat/ccm-fold-surface` (PR #306), 2026-09-03, by live
DOM inspection during the review. **Pre-existing on `staging`** — the arm-placement block is
byte-identical there; the fold branch only ever added lines to this file (the CCM/term-slot bits) and
never touched these radios. Found *via* the branch, not introduced by it. The browser drive missed it
because the drive exercised the native CCM checkbox and the rollover flow and never touched these
radios.

## What an operator sees

On **Progression → <level> → "Arm placement when no mapping matches"**, clicking either option
(`Distribute evenly` / `Explicit mapping only`) produces no dot. Neither option ever shows selected.
The control reads as dead — "uncheckable" was the operator's word.

## What is actually happening — and it is not what "uncheckable" implies

This is a **rendering** defect, not a logic defect. From the live DOM, on a seat that holds
`academic_setup.manage`:

```
input[type=radio][name=arm_distribution_strategy]
  appearance:       none          <- native rendering stripped
  background-image:  none         <- nothing draws a replacement
  ::before content:  none
  background-color:  white,  24x18 box
  radio[0].checked = true         <- IS selected, and still paints no dot
```

Two things prove the logic is fine and the paint is the whole bug:

- The **selected** radio is `checked: true` in the DOM and still shows nothing — so the miss is the
  checked-state paint, in both states, not just on click.
- Firing the radio's own `click()` flips React state `[true,false] -> [false,true]` correctly. The
  `checked` / `onChange` / `setStrategy` wiring works; the selection registers under the hood. The
  operator simply can't see it, so every click looks inert.

The sibling **CCM checkbox in the same modal** computes `appearance: checkbox` (native) and renders
its mark fine. So this is radio-specific, not a modal-wide reset — which is what makes it easy to
walk past.

## Why this is a defect and not polish

It is a dead control on a configuration screen. The stored `arm_distribution_strategy` defaults to
`round_robin` ("Distribute evenly"), so nothing is destroyed — but an operator cannot change it and
cannot see which option is live, so the **end-of-year arm-distribution strategy is silently locked to
its default**. `MoveToNextYearJob` reads this to decide whether pupils with no arm mapping are spread
across the target level's arms or left unplaced for a human — a real behavioural fork the operator is
now unable to set. Falsely-cautious direction (no data loss), but a control that does nothing visible
is worse than an absent one: it invites the operator to believe they have chosen.

## A second, real-but-not-causal defect in the same block

The `<input>` omits `value={option.value}`. Both radios carry the DOM value `"on"`. The `.click()`
test proves this does **not** break React's controlled behaviour — `option.value` is closed over in
`onChange`, so state still updates. But it is wrong for form semantics and is the same shape as the
CCM-setter lesson this branch already internalised: the intended value lives only in the handler and
never reaches the element. Fix it in the same patch; do not let it stand as "harmless."

## VERIFICATION, 2026-09-03 — the stylesheet premise above does not hold

Everything in this section was re-derived from the working copy before the fix was written, and it
**contradicts the mechanism this ticket asserts**. Recorded rather than quietly fixed, because the
prescribed fix ("remove the `appearance: none`", "it may be an app-wide base rule") rests on the
premise, and a competent implementation of a false premise is exactly what review cannot reject.

**THERE IS NO `appearance: none` RULE REACHING THESE RADIOS.** Searched, and stated as a checked
absence rather than a recollection:

| Searched | Result |
| --- | --- |
| `resources/css/app.css` (the project's ONLY stylesheet) | no `appearance`, and no `radio` / `checkbox` / `input[` selector at all |
| Tailwind v4 preflight (`node_modules/tailwindcss/preflight.css`) | `appearance` appears twice: `::-webkit-search-decoration` and `appearance: button` for `button` / `[type=button|reset|submit]`. **Radios are not touched.** |
| `@tailwindcss/forms` | **not installed** — it is the plugin that would strip radio appearance, and it is absent from `package.json` and `node_modules/@tailwindcss/` |
| the `<style>` block in `resources/views/app.blade.php` | two rules, both `html { background-color }` |
| the compiled bundle `public/build/assets/*.css` | the only `appearance:none` occurrences are `::-webkit-search-decoration`, the **opt-in** `.appearance-none` utility, and two `[&::-webkit-*-spin-button]` utilities |

The element carries **only** `mt-0.5`, so it holds none of those opt-in utilities. So the blast-radius
question this ticket poses — *base-layer rule or local?* — resolves to **neither: no such rule
exists**, and the fix it prescribes has nothing to remove.

Two further notes against the report's own evidence: a `24x18` white box is not a native radio's
metrics in any engine, so the inspected node may not have been the radio; and the report's
`appearance: none` cannot be reproduced from the tree.

**The one app-wide candidate, named and NOT acted on.** `resources/css/app.css` carries
`@layer base { * { @apply border-border; } }`, which sets a border colour on every element including
form controls. In some engines applying border/background to a checkbox or radio suppresses native
painting. It is app-wide if it is the cause — but it hits checkboxes identically, and this report says
the sibling checkbox renders correctly, so it does not explain a radio-only symptom either. **A
base-layer change on an unconfirmed mechanism would convert a known blind spot into an unknown one**,
which is the "partial fix to a gate is worse than the gap" rule one layer out. It is therefore left
alone pending a reproduction in a real browser.

**Also stale: the arms section below.** `no-javascript-test-runner.md` has read **CLOSED** since
`66cc22b` (2026-08-23). `vitest` is installed, has a dedicated `vitest.config.ts`, ships five test
files and is step 19 of `bin/quality`. What is genuinely missing is narrower: the environment is
`node` with **no DOM and no component renderer**, so a component test needs `jsdom` plus a renderer
added first — a real decision, per that config's own docblock, not an impossibility. And no DOM
environment applies external stylesheets anyway, so the **paint** half is not unit-testable in any
case; only a browser can settle it.

## What closes it

In `resources/js/components/setup/progression-panel.tsx`, the `STRATEGIES.map(...)` radio block:

1. **Add `value={option.value}`** to each input. Verified defect, read at the source.
2. **Make the checked state high-contrast** with `accent-primary-600`. The `accent-primary-*`
   utilities already exist in `app.css`, `accent-color` applies to native-appearance controls, and
   these radios ARE native — so unlike the ticket's original reading, the utility takes effect here.
   This stands on its own whether or not the reported paint failure reproduces.
3. **Do NOT touch the base layer** until the paint failure is reproduced in a browser and its
   mechanism identified. See the verification section.

## Arms it needs

- The paint half needs a **browser**: a selected arm-placement radio shows a visible marker, and
  clicking the other option moves the selection. No unit environment can substitute — jsdom does not
  apply stylesheets.
- Keyboard and label-click operability preserved by the fix.
- The `value` half IS unit-testable once a DOM environment and renderer are added; until then it is
  covered by the build plus the browser check above.

## Related

- `docs/handoff/reports/feat-ccm-fold-surface-drive.md` — the drive that surfaced the operator-facing
  findings; this one sits beside them but was found in review, not on the drive.
- `the-fold-refusal-names-ids-where-the-gate-names-the-class.md` — sibling operator-facing rendering
  defect from the same surface; pair them.
- `no-javascript-test-runner.md` — **closed**; see the verification section.
- Pre-existing on `staging`: this is not a `feat/ccm-fold-surface` regression, and the fix should not
  be back-dated into that branch's history.
