---
name: finance-execute
description: The implementing-agent brief on the Brookstone platform — how to write one that cannot be misread, and how to work one and report back. Load this whenever you are about to hand work to an implementing agent, write a prompt or brief for a change, scope a migration or test or fix, or when you ARE the implementing agent carrying out a brief and need to know what proof and what report are expected. Also load it before reporting that a change is done. It carries the brief template and the report template.
---

# The brief

The brief is the interface between the side that proposes and the side that
implements. It is the most repeated artifact on this project, and its shape is
settled. Follow it — a brief that surprises the reader in structure costs
attention that should go to the content.

You are on one of two sides of it. Read the section that applies, then the
template in `references/`.

---

## Writing a brief

Full template: `references/brief-template.md`. The reasoning behind each section:

**Base, branch, shape.** Name the base commit, the branch, and the physical
shape of the change — how many files, what kind, how many commits. This is what
lets the implementer detect immediately that they are not where you thought they
were. "Base: `staging` @ `01fdeda`. Branch: `x`. Shape: one migration + one test
file. One commit."

**The finding.** What is actually wrong, with evidence, and — critically — the
environment it bites in. A defect that is invisible locally and live on
production must say so in its first paragraph, because everything about how the
work is prioritised and proven follows from that.

**What NOT to do.** This section earns its place. Every plausible-looking wrong
fix that occurred to you, named, with the reason it is wrong. Left unwritten,
the implementer will find those same options and pick one, reasonably. Typical
entries: resetting the ground-truth copy, hand-patching one environment,
editing a migration that has already run.

**Parts, numbered.** Each part one coherent unit of work. Carry over explicit
requirements as a numbered list rather than "follow the pattern in X" — pattern
references are read selectively under time pressure, numbered requirements are
not.

**Pre-flights.** Their order matters and should be stated. A pre-flight that
runs after the write it was meant to prevent is decoration. State what aborts,
what merely warns, and — for anything inside a transaction — that a throw rolls
the whole thing back.

**Prove it.** The commands, in order, with the expected output of each. Say what
constitutes a flip, a pass, a stop. Ask for raw output pasted, not summarised —
a summary of output is a claim about output.

**The watched red.** Required, explicitly, with the mutation to make and the
instruction to paste both states. Without this named as a deliverable it will
not happen, and a green nobody watched go red proves nothing.

**Stop and report.** The conditions under which the implementer must halt rather
than improvise. Be generous here. Every one of these is a place where a
well-meaning fix would have made things worse and taken a day to unwind.

**Not in scope.** Bound the blast radius. Name the adjacent tempting things that
must not be touched, including known flakes so they are not chased.

Two more rules for the writer:

- **Derive targets, never hardcode a second copy.** If a value exists in a
  seeder map or a config, the change must read it from there. A second
  hardcoded list is drift waiting for a deploy.
- **Database work is queries in the brief.** You cannot run SQL from the
  advising side. Fold the query in, with the shape of the expected answer, and
  the privacy rule attached: `user#<id>`, `school#<id>`, counts only.

---

## Working a brief

Full template for reporting: `references/report-template.md`.

**Read the premise before the task.** Confirm the finding against the repo — the
files it names, the line numbers, the claimed behaviour. If the code disagrees
with the brief, stop and say so before writing anything. A brief executed
faithfully on a false premise produces a change that looks finished and is
wrong.

**Do the parts in order.** Pre-flights before writes. If a pre-flight aborts,
that is the brief working, not an obstacle to route around — report it.

**Prove what you were asked to prove, and paste raw.** Not a summary of the
output. The reader is checking your output, not your reading of it.

**Watch the red.** Plant the regression the brief names, confirm the failure
message names the right thing, restore, paste both. If you cannot make it fail,
that is the most important thing you found today — report it and stop.

**Deviations are first-class.** If you departed from the brief — dropped a
check, changed a shape, chose differently at a fork — that goes at the top of
the report with the reasoning, not in a footnote. A deviation with a plausible
reason is often right and occasionally is a general rule that is false in
particular; either way it needs a second pair of eyes on it, which it only gets
if it is visible.

**Do not weaken a test to make it pass.** If an assertion fails on
currently-seeded data, that is a finding. Narrowing the assertion converts a
finding into permanent invisible breakage.

**Report under the privacy rule.** `user#<id>`, `school#<id>`, counts and
structure. No names, no emails, no amounts.

**Say what you did not do.** Unproven arms, skipped steps, things left for a
follow-up. An honest gap is cheap; a discovered gap is not.

---

## Where this skill ends

Your task ends when the report is emitted. **Do not review your own work.**

Not because self-review is forbidden as etiquette, but because it does not work
and produces something worse than nothing: you carry the same assumptions and
the same blind spots into the review, and you will reliably generate the *shape*
of a rigorous review — headings, evidence classifications, "I disagree with this
because…" — while systematically failing to find what you did not already know
you missed. That output is more confident than an unreviewed change and harder
to distrust.

So: write the report **for someone who did not do the work**. State what you
verified, how you verified it, and what you assumed. Assume the reader will not
be able to ask you a follow-up question, and will check every claim against the
repo rather than against you.

## The hand-off

Do this every time, as the last thing you do:

1. **Write the report to a file** — `docs/handoff/reports/<branch>.md`, using
   `references/report-template.md`. Not to the chat only. The reviewer needs a
   path, and a file is also the record of what you claimed at the moment you
   claimed it.
2. **Spawn the `finance-reviewer` subagent**, passing it **only** the report
   path and the branch name. Nothing else. Not a summary, not your reasoning,
   not "the risky part is the migration", not "I already checked the oracles".
   Every one of those narrows the reviewer toward your own blind spot, and a
   reviewer wearing your frame is a more confident version of you, not a check
   on you.
3. **Return its findings raw** to the project lead, alongside your report. Do
   not answer them, argue with them, or resolve them in the same breath. If a
   finding is wrong, that is the lead's call to make with both texts in front of
   them.
4. **Commit on the branch. Never push.** An uncommitted working tree is one
   `git checkout` away from gone, and it gives the reviewer no ref to diff
   against — `git diff <base>...<branch>` needs a branch that has something on
   it. Commit even when the change is comment-only. Pushing, merging and
   opening the PR are the project lead's, not yours.

The honest limit of this: the reviewer is spawned by the thing being reviewed.
It cannot hide a shortcut from you — it reads the repository, not your story —
but it *can* be pointed at the wrong wall, because you control the frame. That
is why you pass it nothing but a path and a branch, and why it is instructed to
re-derive scope rather than accept yours.

**Reserve a cold session for the `full review` tier.** The subagent is the floor,
not the ceiling. When the change touches money, a migration, roles or
permissions or grants, `school_id` isolation, a gate or lint or trigger, or a
fixture oracle, say so in your headline: *"This is full-review tier — subagent
review attached, recommend a cold session before merge."* A separate
conversation, started fresh from the report file, is the only review that does
not share a process with you at all.

Then stop. `finance-review` is not yours to run in this context.

If a decision is needed mid-task — a fork, a trade-off, a modal — that is a
separate consultation, not you changing hats. Emit the decision request (see
`finance-review`'s `references/decision-template.md`) and wait.
