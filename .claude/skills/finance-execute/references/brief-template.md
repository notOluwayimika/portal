# Implementing-agent brief — template

Copy the structure. Delete sections that genuinely do not apply; do not delete
"What NOT to do", "Watched red" or "Stop and report" — those are the ones that
pay.

---

# Implementing-agent prompt — <one-line title: what changes and why>

**Base:** `<branch>` @ `<sha>`. Branch: `<new-branch-name>`.
**Shape:** <n files: which kinds>. <n> commit(s). <"No behaviour change." if so>
**<Severity line if it matters — e.g. "This is a PRODUCTION defect, not a local
one. Read the next section before writing anything.">**

---

## The finding

<What is actually wrong. Evidence as `path:LINE` or pasted output. Code block
the decisive lines.>

<Which environments it bites in — fresh install, existing install, production
only. If it is invisible locally, say that here, not later.>

<What it costs if shipped, concretely.>

## What NOT to do

- **<The tempting wrong fix>.** <Why it is wrong, with the citation that makes it
  a fact rather than an opinion.>
- **<The second one>.** <Why.>
- **<Editing something already run / merged>.** <An edit to a migration that has
  already executed is invisible. New migration.>

## Part 1 — <the change>

<Where. Which file, which new filename, modelled on which existing file.>

<Scope: precisely what is in it.>

Carry over verbatim in behaviour:

1. <Requirement — derived from source of truth, not hardcoded twice.>
2. <Requirement — scoping, e.g. `whereNull('school_id')` and why.>
3. <Fresh-install guard.>
4. <Pre-flight, with its order relative to the others.>
5. <Idempotency: compute the diff first; if aligned, echo and return before any
   write.>
6. <Transaction shape. Which primitive, and which primitive is forbidden and
   why — e.g. never `syncPermissions`, its raw detach fires no event.>
7. <Cache invalidation.>
8. <Report shape: counts, ids, no names.>
9. <`down()`: what it does and why, in a docblock.>

<Anything this change must have that the model file does not — call it out
separately with the reason.>

## Part 2 — prove it

Run in this order and paste the **raw** output of each:

1. `<command>` — before. Expected: <shape>.
2. `<command>` — the change. Paste <what>.
3. `<command>` — after. Expected: <the flip>. If it does not flip, **stop and
   report** — do not chase it with a second change.
4. `<audit command>` — expected finding count <n>, with <known pre-existing
   findings> expected to remain. A new finding is a **STOP**.
5. Idempotency on the real data: <rollback + re-run>. Second run must <echo
   what> and write **no** new rows. Prove that with a count before and after,
   not by assertion.

Report every seat as `user#<id>` / `school#<id>`. **No names, no emails, no
amounts.**

## Part 3 — the test, with a watched red

New file `<path>`. <n> arms:

1. **<Name>.** <Setup. Assertions — including the one that proves the thing you
   were most worried about.>
2. **<Idempotent.>** <Assertions.>
3. **<Guard bites.>** <Plant the violation. Assert it throws, that the message
   names the offender, and that **nothing changed** — the abort must precede any
   write.>

**Watched red — required, paste it.** With arm <n> green, <the exact mutation>,
re-run, confirm it fails naming <what>. Restore. Paste both. A green you have
not watched go red proves nothing.

## Part 4 — drive it

<Only if a screen changed. Three lines, not a procedure — the procedure is the
`finance-drive` skill and the brief must not restate it.>

Load the `finance-drive` skill and drive `<the screen / route>`.

- **`<seat>`** — <what this seat is here to establish on this screen.>
- **`<isolation seat>`** — <which selects/lists must carry only their own school's
  rows.>
- <Anything specific to this screen the drive would not otherwise think to look
  at — a total the server computes, a control that must disappear, a lifecycle
  state the fixture can or cannot reach.>

<If this screen needs something the fixture's count table does not yet count, say
so here: the column is part of this change.>

## Stop and report

1. <Condition — the proof does not land as predicted.>
2. <Condition — a new finding appears where none was expected.>
3. <Condition — a guard fires, meaning the premise itself is wrong.>
4. <Condition — an oracle changes when none should.>
5. <Condition — you cannot produce the red.>

## Not in scope

<Adjacent tempting changes. Known flakes, named so they are not chased. Any file
that has already run or merged.>
