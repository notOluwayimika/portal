# Outstanding drives — what is unrendered, and where each runbook lives

Three screens are built, gated green and **never rendered**. Each has a runbook here. They sit on
**two different branches**, which is the thing most likely to waste your time — a runbook is only
useful checked out beside the code it describes.

## The three, in the order I would drive them

| # | Screen | Branch | Runbook | Time |
| --- | --- | --- | --- | --- |
| 1 | Student curricula table refreshes after a reassignment (#286, the 3→4 re-check) | `feat/reassignment-ui` | [student-curricula-table-refresh.md](student-curricula-table-refresh.md) | ~5 min |
| 2 | Bulk selection & cohort reassignment on the students index (M5) | `feat/reassignment-ui` | [bulk-reassignment-students-index.md](bulk-reassignment-students-index.md) | ~20 min |
| 3 | Year rollover surface (M4) | `feat/m4-rollover-surface` | [m4-rollover-surface.md](m4-rollover-surface.md) | ~25 min |

1 and 2 share a branch and a fixture, so drive them in one sitting. 3 needs a different checkout and
its own data.

## Read this first, whichever you drive

**A green suite says nothing about any of these.** Every one of the three is in the category the
backend cannot see: a 200 with the right list, a 200 with an empty list, and a 200 rendering an error
where a list should be are the same assertion. That category is what hid the NaN badge, and #286 is
literally a case where the server was right, the payload arrived, and the screen ignored it.

**Check the fixture precondition before opening a browser.** Every runbook leads with one, and it is
not ceremony: on M5 §2b and on M4, the interesting states are *unreachable by clicking* unless the
data contains a specific shape. A step that renders the boring state and passes is worse than a
skipped step, because it reads as covered. Each runbook gives the SQL to confirm by **id**.

**Each step names what a FAIL looks like**, not just what to click. Several of the failures are
wording rather than behaviour — on M4 especially, the phrasing *is* the safety feature, and a screen
that works while reading wrong is a finding.

**⚠️ M4 §5 queues a real batch.** If a queue worker is running, pupils actually move. Use a throwaway
database, or confirm no worker is running and clear `jobs` afterwards. The other two are read-mostly
and safe against a copy.

## What to hand back

Per-runbook lists differ, but three rules are constant:

- **GIFs for transitions, stills for states.** #286 is entirely a transition — a still of a correct
  table is indistinguishable from a still of a stale one that happens to look right. Same for M5 §1
  (selection clearing) and M4 §6 (the stale preview).
- **Counts as digits**, not impressions. "Export selected (4) yielded 4 rows" is a finding; "the
  export looked right" is not.
- **Isolation by id, never by label.** The fixtures deliberately reuse names across schools, because
  that is what makes a name check useless.

## Why these are the gate

M3, M5 and M4 are otherwise mergeable — gates green, mutations red where they should be, cold reviews
closed. These three passes are the only outstanding requirement, and `CLAUDE.md` is explicit that
tests alone are not verification.

Related but **not** outstanding: the guardian create-path and uniqueness work (#262, #263) merged and
was driven by its implementer; `feat/guardian-merge-command` is CLI-only cleanup tooling with no
screen and needs no drive.
