# The cold reviewer is handed the implementing session's scratchpad

**Status:** OPEN, partially mitigated. **The remaining half is a harness question for
the project lead, not a repo change** — and it is not closable from inside this
repository.

**Raised by:** the sixth cold review of `fix/subledger-single-clock-frame`, 2026-08-12. The reviewer
found the files while writing its own junit into the directory it had been given, reported the
exposure itself as a finding, and **did not open any of them**. It said so explicitly.

**Mitigated by:** `chore/agent-scratch-isolation`, 2026-08-12 — three rules, from the project lead,
after the correction below. See § "What the rules close, and what they do not". The remedy came from
the project lead; the per-channel accounting was the correction that changed the outcome from
*closed* to *partially mitigated*.

## What was visible

The `finance-reviewer` subagent's scratchpad directory was the same directory the implementing
session had been using. Recorded by **name pattern only** — no contents were read by the reviewer,
and none are reproduced here:

- `*.fixed.php`, `*.ok` — the implementer's saved-good copies of files it was about to mutate, kept
  so a planted regression could be restored after each watched red;
- `msg*.txt` — seven drafts of commit messages, including ones never used;
- `*.log` — the implementer's own suite output;
- a junit XML the reviewer then wrote alongside them.

## Why it matters, and it is not about trust

The whole value of the cold review on this project is that the reviewer **re-derives** rather than
reads. `finance-execute` is explicit about it: pass the reviewer *"only the report path and the
branch name. Nothing else. Not a summary, not your reasoning"* — because *"a reviewer wearing your
frame is a more confident version of you, not a check on you."*

A shared scratchpad hands over exactly what that instruction withholds: the implementer's drafts,
its intermediate states, its own gate and suite logs. Two specific losses:

1. **`*.log` and `*.ok` are artifacts the reviewer is supposed to reproduce, not read.** The
   difference between "I ran the suite and got these numbers" and "I read the numbers the
   implementer got" is the entire evidentiary weight of the review, and nothing in the transcript
   distinguishes them afterwards.
2. **The commit-message drafts carry the implementer's reasoning**, including reasoning it discarded
   — which is precisely the frame the separation exists to keep out.

The exposure is **structural, not a lapse**. Neither side chose it, neither side would notice it,
and a reviewer that did read the files would produce a review that looks identical to one that did
not. That is the property that makes it worth recording: it is undetectable after the fact.

## Two channels, not one

The original filing treated this as a single problem. It is two, and they need separate accounting
because a rule that closes one of them says nothing about the other.

- **The scratchpad channel.** Two sessions writing into one directory outside the repository. This
  is what was actually observed above.
- **The working-directory channel.** Untracked files left in the repository directory itself, which
  a reviewer reading the tree encounters without looking for them. This was not what the reviewer
  saw, but it is real, and it was live on the same branch: `git status --untracked-files=all` on
  `fix/subledger-single-clock-frame` showed an uncommitted
  `docs/handoff/reports/fix-subledger-single-clock-frame.md` — the report itself, written and not
  yet committed. It was committed by `9947585` before this ticket was revised.

## The three rules

Recorded in full where they are enforced; summarised here only so the accounting below is readable.

1. **Private temp directory** — `.claude/skills/finance-execute/SKILL.md` § "Your scratch lives in a
   private temp directory". The implementer creates its own scratch area with `mktemp -d`, and does
   **not** use the scratchpad the session handed it. A handed scratchpad is *inherited* — a subagent
   receives its parent's — so "outside the repository" was not a sufficient statement of the rule:
   the shared scratchpad is already outside the repository. The path is not exported and not written
   into the report; a private directory whose path is published is not private.
2. **Nothing untracked in the working directory** — same file, same section. The test is *"is it in
   the repository directory at all"*, not *"is it ignored"*. `.gitignore` is explicitly rejected as
   a solution and the rejection is written into the skill: ignoring the patterns hides them from
   `git status` while leaving them on disk where the reviewer can read them, which removes the only
   signal that would have surfaced them.
3. **Reviewer inputs, and the fresh clone** — `.claude/skills/finance-review/SKILL.md` § "Your
   inputs, and the tree you read them in", short form in `.claude/agents/finance-reviewer.md`. The
   committed tree and the report path are the only legitimate inputs; untracked files found in the
   working directory are reported by pattern, never by contents. For high-impact branches and
   release validation the review runs against a **fresh clone**, and the review states which of the
   two it ran against.

## What the rules close, and what they do not

| Rule | Closes | Does **not** close |
| --- | --- | --- |
| 1 — private temp dir (`mktemp -d`, not the handed scratchpad) | The implementer's **use** of the shared scratchpad. Nothing of the implementer's is written there, so there is nothing there to be read. | **The sharing itself.** The directory is still shared. Anything else that writes into it — a tool, a hook, a future skill, a lapse — is exposed again, and nothing in the repository would detect it. |
| 2 — nothing untracked in the working directory | Untracked files in the working directory. A real and separate channel, and the one the live instance on `fix/subledger-single-clock-frame` actually was. | Anything outside the repository directory, which is the entire scratchpad channel. |
| 3 — fresh clone for high-impact and release validation | Working-directory overlap **by construction** rather than by discipline: a clone cannot contain another session's scratch. | **The scratchpad channel, entirely.** The reviewer's scratchpad is assigned by the harness regardless of which tree it reads, so cloning the branch changes nothing about it. |

## What remains open

Whether a subagent inherits its parent session's scratchpad is a property of the harness, not of
this repository. An instruction can stop the implementer *using* the shared area; it cannot stop the
area *being* shared. Rule 1 is therefore a discipline standing in for a mechanism, and rule 3 —
which is the only one of the three that achieves isolation by construction — does not reach this
channel at all.

So the residual is: **the two sessions are still handed one directory, and the repository has no way
to know when something lands in it.** The shape of a real fix — a per-invocation scratchpad, or a
fresh one for the review side — is the project lead's call about tooling, and is where this ticket
was filed in the first place.

This ticket is deliberately **not closed**. A ticket closed on coverage it does not have is the
exact failure this ticket is about.

Recorded so the next reviewer knows to check what is in the directory before using it, and so this
is not rediscovered as a novel observation.

## Related

- `docs/handoff/tickets/reports-must-not-carry-risk-rankings.md` — the other half of the same
  concern: what leaks from implementer to reviewer through the artifacts, rather than through the
  filesystem. **Separate ticket, separate rule, still open — do not merge the two.** That one
  governs report *content*; this one governs file *isolation*.
