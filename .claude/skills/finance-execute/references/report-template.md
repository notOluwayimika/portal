# Implementation report — template

Written for someone who was not there and will check it against the repo. Put
the things that would change their mind first.

---

## Headline

<Done / done with deviations / blocked.> <One sentence.> <Branch, commit sha,
PR number if opened.>

## Deviations from the brief

<Anything you did differently, first, not in a footnote. For each: what the
brief said, what you did, and why. If none, say "None." explicitly — the empty
statement is informative.>

<If a deviation rests on a general rule you formed — "X is a superset of Y", "Z
is always true here" — state the rule as a rule, so it can be checked. General
rules formed mid-implementation are right most of the time and wrong expensively.>

## Contradictions of the premise

<Anything the repo or the data said that the brief assumed otherwise. If the
finding in the brief did not reproduce, this is the whole report.>

## What changed

<Files, with line counts. What each does in one line.>

## Proof

<Each step from the brief's prove-it section, with **raw pasted output**. Not a
summary — the reader is checking the output, not your reading of it.>

<For each: expected vs. observed, said plainly.>

## The watched red

<The exact mutation you made. The failure output, pasted. Confirmation that the
message named the right thing. Confirmation you restored.>

<If you could not produce a red: say so prominently. That is a finding about the
guard, not a formality you skipped.>

## The drive

<Only if a screen changed. The `finance-drive` skill lists what this section
carries: the fixture count table pasted from the command, what the selects
actually contained by count and by value (raw, uncut), what each observation
establishes, both seats side by side with ids visible for the isolation check,
and what was not driven. Screenshots in
`docs/handoff/drives/<date>-<screen>/`, named so a reader knows what each shows.>

<If no screen changed, delete this section. If a screen changed and you did not
drive it, say that here and say why — do not delete it.>

## Database observations

<Under the privacy rule: `user#<id>`, `school#<id>`, counts, structure. No
names, no emails, no amounts.>

<Before and after state, as counts.>

## Not done

<Unproven arms. Skipped steps and why. Anything left for a follow-up. Anything
you were unsure about and resolved by choosing — name the choice.>

## Findings raised, not fixed

<Things you noticed that were out of scope. One line each, with `path:LINE` and
a suggested severity: stop / fix / ticket.>
