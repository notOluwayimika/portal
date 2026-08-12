# A report carries findings and evidence — never confidence about where the risk lies

**Status:** a method rule, recorded because it was violated twice in one branch and cost two rounds.

**Applies to:** implementation reports (`docs/handoff/reports/`), implementing-agent briefs, PR
bodies — anything written to be read by someone whose job is to look for what the writer missed.

## The rule

State what was done and what was proven. Do **not** state where the risk is concentrated, where it
is absent, how many rounds of review something has survived, or which part is "the safe half".

Those sentences are not facts about the code. "Verified three times" is a fact about how many
reviews ran; "none of the findings were here" is a fact about what previous reviewers happened to
look at. Written into an artifact whose whole purpose is independent attack, both become a hint —
and the reader who most needs to ignore it is the one least able to tell it apart from evidence.

The same sentence is fine in a conversation, where it can be questioned. It is not fine in a
document that outlives the conversation.

## Both instances, on one branch

**First.** A report's headline recorded a count of which half of the change previous review rounds
had found problems in. It was true, and it was a ranking. The next reviewer opened with: *"the
report's own framing … is a don't-look-here signal and I ignored it"* — and then found two defects
in the half the framing pointed away from, including a guard that passed green while the regression
it existed to catch was live.

**Second, and worse.** The next round added a note *warning about the first instance* — and the note
repeated the ranking in the course of explaining it ("both of this round's findings were in X",
"verified independently three times"). The warning became the anchor it was written to prevent. The
reviewer after that flagged the same shape again, in the same document.

That second instance is why this is a standalone record rather than a paragraph inside a report. A
lesson about not writing rankings into reports cannot itself live in a report.

## How to write the same thing without the ranking

| Instead of | Write |
|---|---|
| "eleven findings were on X and none on Y" | nothing — or, if the split matters, the decision and its criterion, without the tally |
| "verified independently three times" | nothing; the reviews are in the log |
| "the risky part is the migration" | nothing; let the reviewer derive scope |
| "this half is well covered" | the coverage itself: which arm, what it asserts, what red was watched |

Retractions and corrections **stay**. "This transcript was captured against a draft" is a finding
about the work, not confidence about it, and the reader needs it.

## Related

- `.claude/skills/finance-execute` — "write the report for someone who did not do the work … pass
  the reviewer nothing but a path and a branch". This rule is that instruction's other half: the
  path and the branch are not enough if the document at the end of the path does the steering.
- `.claude/skills/finance-method` — "attack the result"; a ranking is the cheapest way to make that
  attack land where it was already going to land.
