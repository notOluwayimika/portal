---
name: finance-method
description: The working method for the Brookstone multi-school platform — how to propose, implement, prove and review changes so a green result actually means something. Load this at the START of any Brookstone task, including any change to app/Finance, any migration, any RBAC or grant change, any test or gate or baseline, any money handling, and any time you are writing or reviewing an implementing-agent brief. Also load it whenever you are about to report a result, assert a fact about the repo, or say something is done. If you are unsure whether it applies, it applies.
---

# The method

This project runs on a separation: one context proposes, a **separate
invocation** implements and reports, and a **third** attacks the report without
having done the work. That is not ceremony and it is not a preference — it is
the only reason the reviews find anything. It has caught a test whose setup
silently no-op'd, a `tsc` baseline calibrated against a corrupted tree, six
enforcement gates reporting green while blocking nothing, and a
migration-rollback audit that passed while testing the wrong migration. All four
were green to the hand that wrote them.

A single context that implements and then "switches to review" cannot reproduce
this. It carries its own assumptions into the review and produces the shape of
rigour — headings, evidence classifications, considered disagreement — while
systematically missing what it did not already know it missed. Do not collapse
the hands. If you have just done the work, your task ends at the report.

## The loop

Propose → implement → **attack the result**.

The third step is the one people skip, and it is the one that pays. A change is
not done when it is written and the suite is green. It is done when you have
tried to break it and failed.

## Bite-proving

A guard, gate, rule, pre-flight or test that you have only ever seen green
proves nothing. It might be passing because it works, or because it never runs,
or because its assertion is vacuous, or because a fixture quietly satisfies it.
You cannot tell those apart from a green.

So: **plant a regression, watch it go red, restore, paste both.** That is the
whole discipline. Comment out the `givePermissionTo`, add the maker to a role
that already has the checker, delete the middleware — whatever the guard claims
to catch, cause it, and watch the guard catch it.

If you cannot make it go red, you have found something more interesting than the
change you were making. Report that.

## Rules without enforcement are wallpaper

A convention written in a doc, a comment, a PR description or a brief is not a
rule. It is a wish. A rule is something with a lint, a gate, a test or a DB
constraint behind it — something that fails a build when violated.

When you find a stated rule with no enforcement, that is a finding in itself,
and it is often worth more than whatever you were originally looking at. Two
live examples in this repo: the ratchet-baseline rule is documented and
unenforced (`docs/handoff/slice-2-brief.md:67`), and `RbacSeeder::grantsMap()`
is pinned by nothing.

The corollary when you propose something: if your proposal is a convention with
no mechanism, say so plainly rather than dressing it as a control.

## A description is not a property — make the assertion executable

The wallpaper rule above is about a **rule** with no mechanism. This is its neighbour and it is more
common: a **description** — a name, a docblock, a comment — that asserts a property the artifact does
not actually have. Nothing is missing; something is *claimed*. And because the claim reads as
verification, it stops anyone looking.

Seven instances across two days on one branch, which is why it earns a section:

| The description | What it claimed | What was true |
|---|---|---|
| a CHECK-constraint test holding a NAMED list | "the replaced constraints are gone" | it could not see a constraint nobody named — two shipped under it |
| a collation scanner | "no bare string comparisons" | it did not match `<=>`, the operator the defect used |
| the same scanner | "these comparisons are defective" | it flagged `BINARY`-guarded ones — someone else's correct code |
| a mutation summariser | "this mutant survived" | it read only Pest's `failures` bucket; the guard had killed it by throwing |
| a test named *"a redaction may change the payload and nothing else"* | "nothing else" | its loop never varied `id` |
| a migration docblock: *"the rule is written ONCE, in one place"* | one place | the update door had no copy of it at all |
| a test fixture: *"holds exactly the abilities named"* | exactly those | its helper assigned `admin`, making every negative arm vacuous |

**The fix has been identical every time: turn the sentence into an assertion.** Enumerate the exact
set instead of naming members. Run the matcher over a case it must find *and* one it must not flag.
Count errors as kills. Put the missing axis in the loop. Read both trigger bodies. Pin that the bare
fixture holds no roles and no permissions.

Two things follow for how you work:

**When you write a description that quantifies — "only", "every", "exactly", "nothing else", "once",
"in one place" — you have written a test.** Either make it executable in the same change or weaken
the words to what you actually checked. A quantifier in prose is a claim nobody can fail.

**The danger scales with the audience.** A wrong number in your own notes costs you an hour. The
same number in a ticket names another team's correct work as defective, in the message asking them to
trust your other findings — and a finding they discount is worse than one you never sent. Before a
description leaves your hands, ask what it asserts and whether anything checks it.

## Re-derive; never carry a number

Numbers go stale between sessions, between branches, and between the moment a
report is written and the moment it is read. Counts, holder totals, line
numbers, step counts, table names — re-derive them from the repo or the database
at the moment you use them.

Carried numbers are how a stale fact becomes a confident assertion. "`bin/quality`
is 11 steps" was true once and wrong when it was repeated.

## Verify against the repo, never against the report

When an implementing agent, a PR body, a doc or a previous session reports what
was done, that report is a claim, not evidence. Open the files. Read the
migration that ran, not the one that was described. Read the rename migration,
not the create filenames.

A report that matches the repo costs you five minutes to confirm. A report that
does not, and that you accepted, costs a production defect.

## Read before asserting

Do not state anything about this codebase you have not just read. Not from
memory, not from a summary, not from a doc that describes the code. The repo is
the only ground truth about the repo.

If you are asserting a fact, be able to cite `path/to/file.php:LINE`. If you
cannot, you are guessing — say you are guessing.

## Don't front-load a primitive ahead of its consumer

Building the general mechanism before the thing that needs it produces an
abstraction shaped by imagination rather than use. Build the consumer, let it
demand the primitive, then extract. When a brief asks for infrastructure with no
caller in the same change, push back.

## Severity, honestly

Right-size it. A finding inflated to sound impressive costs you the credibility
you need when something is actually severe. A real defect softened to keep the
tone pleasant is worse.

Three levels are enough: **stop** (do not ship; this breaks correctness, money,
isolation or audit), **fix** (ship-blocking for this change but bounded), and
**ticket** (real, worth recording, not worth blocking on). Say which, and say
why that one and not the next one up.

Surface uncertainty as uncertainty. Own mistakes in one line at the top, before
anything else, then carry on — not buried, not apologised over at length.

## Disagree with the brief

If the code says something different from what you were asked to assume, the
code wins and you say so before doing the work. A brief written on a false
premise executed faithfully produces a confidently wrong change.

This is not licence to redesign the task. It is licence — and an obligation — to
name the premise and stop.

## Operating constraints

These are not style preferences. Each one exists because it was violated.

**Privacy: structure and totals, not rows.** Report `user#<id>` and
`school#<id>`. Never names, never emails, never amounts, never row contents.
Counts and ids answer every question worth asking about staffing, drift and
duty separation. If you find yourself needing a name to make a point, the point
can be made with an id.

**Ground truth: never reset the copy.** The local database is a copy of
production and that is exactly what makes it useful. `rbac:sync --fresh`
discards runtime matrix edits (`RbacSync.php:11`); running it makes the copy
stop being ground truth for every finding derived from it afterwards. Same class
of error: stripping production-derived roles from the copy to unblock a proof.
Findings are derived locally; production is not a debugging surface.

**Read `.env.example`, never `.env`.** `.env` carries live credentials and
reading it puts them in a transcript that outlives the task.

**No commits, no pushes, from the advising side.** Branches, commits, PRs and
tags are the implementing hand's.

## Output shape

Be concise. Prose over bullets. Name the next action explicitly rather than
describing what could be done — if the answer is an artifact, produce the
artifact, not advice about the artifact.

## Companion skills

- `finance-context` — durable substrate facts about this codebase. Load with this one.
- `finance-investigate` — deriving a finding you can stand behind.
- `finance-execute` — writing the implementing-agent brief.
- `finance-review` — reviewing what came back.
