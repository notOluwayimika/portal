---
name: finance-review
description: Reviewing work on the Brookstone platform — what depth a change earns, what to attack first, and how to verify an implementation report against the repo rather than against itself. Load this whenever a change, PR, commit, migration, test or implementation report comes back to be checked, whenever you are asked "does this look right", and whenever you are about to accept that something is done. Also load it before signing off on anything touching money, RBAC, migrations, isolation or a fixture oracle.
---

# Reviewing

**First: did you do this work?** If the implementation happened in this same
context — you wrote the migration, you ran the tests, you produced the report —
then stop. You cannot review it. Not as a matter of etiquette: you carry the
same assumptions and blind spots that produced it, and what you will generate is
the shape of a rigorous review without its substance. That is worse than no
review, because it is more confident and harder to distrust.

This review belongs in a fresh invocation, given the report plus repository
access and explicitly not the implementation conversation. Say so and hand it
off.

The separation is the whole mechanism. It works because the reviewer does not
know what shortcuts were taken and therefore has to ask, and because the
implementer cannot lean on the reviewer's reasoning and therefore has to
re-derive. On this project that structure caught a test whose setup silently
no-op'd, a `tsc` baseline calibrated against a corrupted tree, six enforcement
gates reporting green while blocking nothing, and a migration-rollback audit
that passed while testing the wrong migration. Every one of those was green to
the hand that wrote it.

---

A review that reads the diff and agrees with it is a cost with no product. The
job is to attack the result: find the case where it is wrong, and if you cannot,
say what you tried.

The single rule underneath everything here: **verify against the repo, never
against the report.** A report is a claim. Open the files. You did not watch
this work happen, and that is your advantage — do not spend it by taking the
narrative on trust.

## Does this change earn a review?

Depth is not free, and a review that treats everything alike produces noise —
and noise gets skipped, including the review that mattered. Match the depth to
what the change can break.

**Full review — always.** Anything touching money handling or money columns; any
migration; any change to roles, permissions, grants or the seeder map; anything
touching `school_id`, `BelongsToSchool` or `SchoolScope`; any change to a gate,
lint, trigger or DB constraint; any change to a fixture oracle; anything
append-only; any deletion or weakening of a test assertion.

**Targeted review.** New endpoints and requests, new commands, changes to
`bin/quality`, anything with a new query against a `finance_` table. Check the
boundary, the authorization, the scoping and the query hygiene — not the whole
world.

**Light pass.** UI wrappers, copy, formatting, type-only changes, doc edits.
Confirm the shape is what it claims to be, confirm no gate was touched, and
exit. A one-line "no findings, light pass, checked X and Y" is a complete
review of a `<Can>` wrapper and it should take a minute.

Cheap exits are part of the design. If a change earns a light pass, take it —
that is what keeps attention available for the migration next week.

## Attack order

Go where the defects have actually been, in this order:

1. **The premise.** Was the brief's finding true? A faithful implementation of a
   false premise passes every other check on this list.
2. **Deviations.** Anything the implementer did differently, and especially any
   general rule they formed to justify it. Those rules are usually right and
   occasionally false in a specific way that then gets recorded in the repo as
   fact.
3. **The guard's scope.** Does it see the violation it claims to? User-scoped,
   role-scoped and assignment-time checks catch genuinely different things and
   none is a superset of another. Ask specifically: what violation would slip
   past this and look clean?
4. **Ordering.** Does the pre-flight run before the write? Does the abort
   actually roll back everything, or just the last statement?
5. **The environment it lands in.** Fresh install versus existing install versus
   production. The two most expensive defects here were both invisible locally
   and live on production, and both came from a non-destructive sync path.
6. **The watched red.** Was one produced? Did the failure message name the right
   thing, or just fail? A red that fails for an unrelated reason is not a red.
7. **The numbers.** Re-derive them. Do not accept a count, a line number, a step
   count or a sha from the report.
8. **Privacy.** Any name, email or amount in the report or the code's output is
   a fix-level finding on its own.

## Reviewing a report specifically

Take each load-bearing claim and ask which it is: read, ran, told or inferred.
Then check the read and ran ones against the repo. In practice this is three or
four `path:LINE` lookups and it is where reviews find things.

Watch for: output summarised rather than pasted; "and the tests pass" with no
indication which; a deviation explained in a way that generalises further than
it should; an assertion narrowed to make it pass.

## Writing the review

Findings only. No summary of the change — the reader has the diff.

Each finding: what is wrong, `path:LINE` or pasted evidence, the concrete
failure it causes, severity (**stop** / **fix** / **ticket**) with one line on
why that level and not the next one up, and what would close it.

Then a short line on what you checked and did not find. That is the part that
tells the reader what your green covers — a review with no findings and no
coverage statement is indistinguishable from a review that did not happen.

If you found nothing, say so plainly and say what you attacked. Manufacturing a
finding to justify the review is worse than an honest clean pass.

## Templates

- `references/review-template.md` — the review output.
- `references/decision-template.md` — for when the review produces a fork rather
  than a finding, and someone has to choose.
