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

Twelve instances across four days, which is why it earns a section:

| The description | What it claimed | What was true |
|---|---|---|
| a CHECK-constraint test holding a NAMED list | "the replaced constraints are gone" | it could not see a constraint nobody named — two shipped under it |
| a collation scanner | "no bare string comparisons" | it did not match `<=>`, the operator the defect used |
| the same scanner | "these comparisons are defective" | it flagged `BINARY`-guarded ones — someone else's correct code |
| a mutation summariser | "this mutant survived" | it read only Pest's `failures` bucket; the guard had killed it by throwing |
| a test named *"a redaction may change the payload and nothing else"* | "nothing else" | its loop never varied `id` |
| a migration docblock: *"the rule is written ONCE, in one place"* | one place | the update door had no copy of it at all |
| a test fixture: *"holds exactly the abilities named"* | exactly those | its helper assigned `admin`, making every negative arm vacuous |
| a 502 fixture, under a test named *"a 5xx is UNAVAILABLE"* | that the 5xx branch works | its body was not JSON, so it passed through the UNREADABLE-BODY branch — deleting the 5xx check left it green |
| a docblock claiming the comparison is `hash_equals` and never `===` | timing-safety | true, and **unverifiable by any behavioural test** — see below |
| two true measurements about a branch (*"lacks migration X"*, *"list lacks constraint Y"*) | *"so it will break"* | neither branch **reaches** the guard — no test there creates an invoice |
| `git push` exiting 0 | the remote moved | the pre-push hook had aborted it; the remote was 4 commits behind |
| `grep -c` returning `0` | the content is absent | the pattern was case-mismatched; the content was there |

**A TRUE MEASUREMENT CAN CARRY AN UNMEASURED INFERENCE, and its credibility transfers.** The
branch row above is the sharpest form in this table, because nothing in it is false. Both
measurements were real, both outputs were real — and the conclusion drawn from them was never
measured at all. It reads as solid precisely *because* the checking was genuine; the inference
arrives wearing the evidence's clothes.

The discriminator is **reachability**. A check that establishes a hazard is PRESENT licenses
*"this branch could hit X"*. It never licenses *"this branch will break."* Those are different
claims and only the first was measured. The same word had been used correctly about a finding one
turn earlier — *reachability not established, so ticketed rather than claimed* — and then not
applied to the next one. **Presence is not reachability**, and the gap between them is where a
confident wrong conclusion lives.

**Two of those rows are worse than the others and are worth separating out.**

**The FIXTURE row is the one READING cannot catch.** That test was correct in structure, correct in
its assertions, and correctly named — and it exercised the wrong branch because of the *data* it was
handed. Nothing in the file was wrong. Only mutating the branch it claimed to cover revealed that
deleting that branch changed nothing. **When two branches can produce the same refusal, the fixture
has to make the one under test the only one that can fire** — the same discipline as giving a fixture
enough distinguishing structure that the rule under test is the sole explanation for the pass, one
level down, in the input rather than the arrangement.

**The `hash_equals` row is the hardest form, because the claim is unverifiable IN PRINCIPLE.** Every
other row is a description nobody had checked, and the fix is to write the missing test. Timing-safety
is invisible to assertions about return values: swapping `hash_equals` for `===` changes no observable
behaviour at all, so no behavioural test can exist to catch it. **When a property cannot be reached
behaviourally, the artifact itself is the only available instrument — assert against the source.**
That is the same move as reading trigger bodies back out of `information_schema` instead of trusting
the migration that claims to install them: check the artifact, not the intent. It is crude, and crude
beats a sentence with nothing behind it.

**A GATE NEEDS THE KNOWN NEGATIVE MORE THAN A TEST DOES**, and this is the one asymmetry in the
table worth stating separately. A test that is broken-closed goes red and somebody looks. **A gate
that is broken-closed refuses everything — and refusing everything is indistinguishable from
strictness until someone bypasses it, then disables it, and you are left with neither the gate nor
the knowledge that it is gone.** The failure is silent compliance rather than a red.

Measured: `bin/db-exclusive` was written to refuse concurrent suite runs, and its first version
matched the invoking shell — whose command line contains the script's own text — so it refused on a
free database, always. A busy-only bite-proof passes that gate. Only asserting **free → exit 0**
alongside **busy → exit 1** caught it. Every gate you write gets both arms, and the free arm is the
one that matters.

**AND A BITE-PROOF MUST SURVIVE THE FIXTURE'S OWN SETUP, or it tested the erasure.** The newest
shape, and it is not an instrument blind to its axis — it is a *proof destroyed by the thing it was
proving against*. Measured 2026-08-30: a collation tripwire was bite-proven by planting a bare
comparison with `CREATE TRIGGER` before the run. It passed 4/4 — because the file uses
`RefreshDatabase`, which runs `migrate:fresh` and had dropped the planted trigger before the first
assertion. **The gate had never been shown to fire, and the passing bite-proof said it had.**

The general fix: plant *inside the thing the fixture rebuilds from*, not on top of what it rebuilds.
For a schema gate that means **mutating the migration**, so the refresh re-creates the defect. Ask of
any bite-proof: what does `beforeEach` do to my plant?

This is the same family as *a green is not a pass when it was measured against a base that has
moved* — a result measured against a state that no longer existed by the time the assertion ran.

## Report the board from `bin/board`, never from recall

A one-line rule with a script behind it, because it was learned the hard way inside the very session
that wrote the section below.

**A status report derived from your own account of your work is not a measurement of your work.** It
inherits every gap in the account, including the ones you have no way to notice. On 2026-08-31 two
finished, fully-verified branches were reported as ready while existing **only locally**. The summary
was not careless — it was an accurate summary of a board being read from memory, and memory has no
entry for *"I verified this and did not push it."*

`bin/board` reads refs, fetches first, and prints local vs remote vs base for every branch. Run it
before any status claim. Its first run on the real repository surfaced a branch nine commits ahead,
local only, that had never appeared in any board reported by hand.

**"Verified but unpushed" emits nothing** — no red, no warning, no diff — which puts it squarely in
the class described immediately below. Knowing about that class is not what protects you from it.

## A rule with no local failure signal has no adoption gradient

Its own section, because it explains a distribution rather than an incident, and because the fix is
structural rather than a habit.

> **A correctness rule whose violation produces no red propagates by memory alone — and memory does
> not propagate. Not across developers, not across a week, not to the file next door. So its uptake
> looks like NOISE, not like a date. Writing it down is not propagation; only a gate is.**

**Measured, and it began as a wrong hypothesis.** The collation rule — every string comparison in a
finance trigger under `COLLATE utf8mb4_bin` — was recorded in `2026_08_17_100000`'s docblock and
corrected under #95. The natural theory was a **dated cohort**: the bare comparisons are the ones
written before the correction, so a dated sweep fixes them.

That is **false, measured**. Six of the ten affected triggers POSTDATE the correction, and one is
`2026_07_26_140001` — the same-day SIBLING of the migration that recorded it. The adjacent file, the
same afternoon, did not pick it up.

**The absence needed an explanation and this is it.** Nothing fails when you omit the clause. There
is no red, no lint, no failing test — so the only transmission mechanism is somebody remembering, and
the observed distribution is exactly what random recall produces: not a date, not a gradient, noise.
The list then grew from 29 to 31 *during the two days a gate for it was being written*, which is the
same fact demonstrating itself.

**The generalisation, which is why this is here and not in the ticket:** ANY rule whose violation is
silent at write time is in this class. Currency shape before the CHECKs. Citation format before
`citation-lint`. `COLLATE utf8mb4_bin` before this. The docblock version of such a rule is not a
weaker gate — **it is not a gate at all**, and its apparent adoption is sampling noise.

Two operational consequences:

- **Do not infer a cohort from an undated defect.** If violations are silent, their distribution
  carries no information about when the rule was written, and a dated sweep will miss most of them.
- **When you write a rule of this shape, the gate is the deliverable.** The sentence is documentation
  of the gate, not a substitute for it. If a gate is genuinely too expensive today, say the rule is
  currently unpropagated rather than recording it as practice.

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
