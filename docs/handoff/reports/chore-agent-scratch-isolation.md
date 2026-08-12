# `chore/agent-scratch-isolation`

**Base:** `origin/staging` @ `ab55763`. **Shape:** four files, all modifications — no new files, no
app code. **Gate:** `bin/quality` PASS 14/14, raw, on base `ab55763`.

Agent scratch isolation: the implementer's rule in `.claude/skills/finance-execute/SKILL.md`, the
reviewer's rules in `.claude/skills/finance-review/SKILL.md` with the short form in
`.claude/agents/finance-reviewer.md`, and
`docs/handoff/tickets/reviewer-can-see-implementer-scratchpad.md` rewritten rather than closed.

**The rules are not restated here.** They live in the two skills, and what each one does and does
not close lives in the ticket. A fourth copy is the drift shape this branch exists to prevent.

This report exists for the two things that are otherwise nowhere on disk.

## 1. What the untracked report turned out to be

The block that commissioned this work recorded `git status --untracked-files=all` on
`fix/subledger-single-clock-frame` as showing:

```
?? docs/handoff/reports/fix-subledger-single-clock-frame.md
```

It was **a report written and not yet committed.** Not a superseded draft, and not a duplicate of a
committed one. `9947585` added it with raw status `A`:

```
:000000 100644 0000000 150b8e9 A	docs/handoff/reports/fix-subledger-single-clock-frame.md
```

**The limit on that conclusion, stated because it is load-bearing:** this is *structural* evidence —
a new-file add plus a clean tree — and **not a byte-diff.** The untracked file no longer exists on
disk, so it could not be diffed against the committed blob. What is established is that no committed
version existed on that branch for it to be a duplicate or a stale copy *of*. What is not
established, and cannot now be, is that its bytes were identical to what `9947585` committed.

**Separate lineage, not the untracked file.** A different blob of that same path exists at `ff57312`
on local branch `fix/sql-clock-lint`: 53,532 bytes there against 52,187 at `9947585`, a 757/663 line
delta. `ff57312` is not an ancestor of `HEAD` and is not merged to `staging`.

## 2. The disproved hypothesis

**The commissioning block asserted that this was "a stale report sitting untracked beside a live
one".** There was no live one. On that branch the path was untracked and nothing else — the first
committed version of it is `9947585` itself.

Recorded plainly, and attributed, because disproving it was the value of running the check at all. A
report that only writes down the guesses that turned out right teaches the next reader that guesses
are usually right, which is the opposite of what this record is for.

## Facts

- Four files, all modifications. No new files, no app code.
- `bin/quality` PASS 14/14, raw, base `ab55763`.
- `git status --untracked-files=all --porcelain` returned zero lines both before the commit and
  again after the gate.
- **This task created no scratch file at all** — none in the repository, none outside it. The
  rule's first application to itself.

## Not done

- **Not pushed.** The project lead's call.
- **No cold review on this branch — the project lead's decision, recorded here so the absence is
  deliberate rather than an omission.** Nothing executable changed, no gate behaviour moved, and the
  substantive review of rule 1 happened in the exchange that produced it: the rule as originally
  written said "outside the repository", which would not have prevented the exposure that was
  actually observed, and was corrected to a private `mktemp -d` before anything was written.
