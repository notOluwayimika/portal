# TICKET — the discount base control is proven by one drive and guarded by nothing

**Status:** open as a *guard* gap. The correctness question is **closed** —
`docs/handoff/drives/2026-08-27-discount-base/` read the request off the wire:

```
click "of the whole bill" →
POST /api/v1/finance/discount-policy-changes
{"kind":"create",…,"basis":"percent","percent":50,"base":"total"}
```

The control is bound and `send()` spreads what `changeTerms()` returns. That was the open question
when this ticket was written and it is answered.

## What remains

That evidence is one build on one day. Nothing runs again.

- `vitest.config.ts` is `environment: 'node'` deliberately and argues the case in its own docblock.
  There is no `jsdom`, no `happy-dom`, no `@testing-library`, and no test imports from
  `resources/js/pages`.
- The covered half is the two pure functions — `amendBase()` (which base an amendment opens on) and
  `changeTerms()` (which keys reach the server for each basis), both bite-proved, plus the endpoint's
  own arms in `BssPerStudentDiscountTest`.
- The uncovered half is the JSX binding the control to `form.base` and the one line in `send()` that
  spreads the result into `axios.post`. Break either and every arm stays green and every lint passes;
  what fails is the school's first `total` policy, silently authored as `discountable` — a smaller
  discount, a bigger bill.

## What the drive also established, which changes the shape of the risk

**The form states `base` on every percent submit**, seeded by `amendBase()`. So a change authored on
this screen never omits it, and `DiscountPolicyChange::effectiveBase()`'s inheritance step is
reachable **only from an API client** — an import, an integration, a console call.

That is the intended design (the screen shows what will be stamped and posts what it shows, so the
form and the server cannot disagree), and it narrows this ticket usefully: a regression here does not
silently fall through to inheritance and land on a plausible value. It posts nothing, and the server
inherits from a policy the maker may not have been looking at.

A consequence worth knowing when reading the approvals queue: for any change a human authored,
`base` and `effective_base` are equal. They diverge only on API-authored changes, which is exactly
where the two-key design earns its place — and exactly where nobody is watching.

## Why a DOM harness was not added

Adding a renderer is an independent decision about the project's test posture, taken days before
cutover, in a commit whose subject is a form control. The alternative that was refused is worth
naming: an endpoint test wearing a form test's name. Posting to the endpoint and asserting the policy
carries the base proves the server, which was already proved, and says nothing about the screen.

## What closes it

Either a DOM environment and a render test for this control, or the drive scripted and re-runnable
rather than left in a report. The repo has drive tooling and a `finance-drive` skill; what it does
not have is a committed drive script (the skill says so explicitly).

The trigger is the second screen that needs a form-submission guarantee. Right now this is one
control on one page; the day there are two, "no DOM harness" stops being a local judgement and
becomes the project's answer.

**Related, found by the same drive:** the base radios carry `value="on"` and no `data-value`, so the
selected base has no machine-readable handle in the DOM — see
`the-base-radios-have-no-machine-readable-value.md`. That is why this drive had to confirm the value
off the wire rather than off the screen.
