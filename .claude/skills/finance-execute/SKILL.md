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

**The drive is a pointer, not a section.** If the change touches a screen, ask
for the drive in three lines — which screen, which seats, what to look at there —
and point at the `finance-drive` skill for everything else. Do not re-specify how
to seed the fixture, how to sign in, or how to check isolation. Every brief for
months carried its own near-identical copy of that procedure, each drive
rediscovered the same friction independently, and the only part that ever varied
was the screen. The procedure now lives in one place that gets corrected once.

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

**If a screen changed, drive it — and load `finance-drive` before you do.** The
suite is structurally blind to rendering: a 200 with the right list, a 200 with
an empty list and a 200 rendering an error where a list should be are the same
assertion. The brief names the screen and the seats; the skill carries the
environment, the fixture check that comes before the browser, the seats and what
each proves, the isolation-by-id method, and the friction you would otherwise
pay for again.

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

## Your scratch lives in a private temp directory

A standing rule, not a suggestion.

**Every file you create that is not destined for the commit is written into a
private temporary directory you create yourself — `mktemp -d` — and nowhere
else.** Not the working directory. Not the scratchpad the session handed you.

Two clauses, because they close two different holes.

**The private directory.** Use `mktemp -d`, not the scratchpad you were given. A
handed scratchpad is *inherited*: a subagent gets its parent session's, so the
reviewer you spawn is handed the same directory you have been writing into.
Inherited is the whole problem — "outside the repository" is not enough on its
own, because the shared scratchpad is already outside the repository. Create your
own.

Then: **do not export that path, and do not write it into the report.** A private
directory whose path is published is not private.

**Nothing untracked in the working directory.** A separate hole, closed
separately. Named explicitly, because these are the kinds actually observed on
`fix/subledger-single-clock-frame`:

- partially-edited and saved-good copies of source files (`*.fixed.php`, `*.ok`)
  kept so a planted regression can be restored after each watched red;
- saved command output — suite logs, gate transcripts;
- status markers, gate-passed flags;
- drafts of the message you will send to the project lead, including the ones you
  discard.

The test is not *"is it ignored"*. It is *"is it in the repository directory at
all"*.

**Do not solve this with `.gitignore`** — recorded here so nobody proposes it as
an obvious improvement. Ignoring the scratch patterns makes them invisible to
`git status` while leaving them on disk exactly where the reviewer can read them.
That is strictly worse than doing nothing, because it removes the one signal that
would have surfaced them. The rule is absence from the directory, not absence
from the diff.

### Why this is a rule and not hygiene

The cold review is worth what it is because the reviewer works from exactly two
inputs: the committed tree, and the report. It does not know what you tried, what
you believed, or what you concluded. That is what makes *"this arm does not test
what it claims"* an independent finding rather than an echo of a doubt you
already had. Hand over your drafts, your intermediate states and your own suite
logs, and the reviewer becomes a more confident version of you — which is the one
thing the separation exists to prevent.

Two specific losses, if this reads as tidiness:

1. **Your logs are artifacts the reviewer is supposed to reproduce, not read.**
   The difference between "I ran the suite and got these numbers" and "I read the
   numbers the implementer got" is the entire evidentiary weight of the review,
   and nothing in the transcript distinguishes them afterwards.
2. **Your message drafts carry your reasoning, including reasoning you
   discarded** — precisely the frame the separation exists to keep out.

This rule exists because a reviewer could see the scratch, declined to open it,
and reported the exposure itself as a finding. A separation protected by the
reviewer choosing not to look is a courtesy, not a control, and this project does
not accept a rule with no mechanism behind it anywhere else.

What this closes and what it does not is recorded in
`docs/handoff/tickets/reviewer-can-see-implementer-scratchpad.md`. Read it before
concluding the channel is shut: it is not, and the residual is deliberate.

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
