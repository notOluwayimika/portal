# The cold reviewer is handed the implementing session's scratchpad

**Status:** OPEN. **A harness question for the project lead, not a repo change** — nothing in this
repository can fix it, and nothing here should try.

**Raised by:** the sixth cold review of `fix/subledger-single-clock-frame`, 2026-08-12. The reviewer
found the files while writing its own junit into the directory it had been given, reported the
exposure itself as a finding, and **did not open any of them**. It said so explicitly.

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

## Not proposed here

No repo change. The scratchpad path comes from the harness, not from anything under version control,
so this cannot be closed by a lint, a test or a convention in `CLAUDE.md`. The shape of a fix — a
per-invocation scratchpad, or a fresh one for the review side — is the project lead's call about
tooling.

Recorded so the next reviewer knows to check what is in the directory before using it, and so this
is not rediscovered as a novel observation.

## Related

- `docs/handoff/tickets/reports-must-not-carry-risk-rankings.md` — the other half of the same
  concern: what leaks from implementer to reviewer through the artifacts, rather than through the
  filesystem.
