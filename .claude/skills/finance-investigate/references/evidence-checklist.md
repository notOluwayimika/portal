# Pre-publication pass

Run this over a finding before it leaves your hands. It takes two minutes and it
is the difference between a finding that holds and one that gets returned.

## Every load-bearing claim

- [ ] Is it **Read** or **Ran**? If it is Told or Inferred, is it labelled as such
      in the text?
- [ ] Does it carry a `path:LINE` or pasted output?
- [ ] Were the line numbers derived just now, on the branch under discussion —
      not carried from an earlier session?

## Numbers

- [ ] Every count, total and step-count re-derived at the moment of writing.
- [ ] Commit shas and branch names confirmed against `git log`, not memory.
- [ ] Table names taken from the rename migration, not the create filenames.

## Guards and enforcement

- [ ] If you claim something **is** enforced: have you watched it go red?
- [ ] If you claim something is **not** enforced: have you looked for the
      enforcement you would expect, and shown it absent — grepped the test
      suite, the lints, the DB constraints, the triggers?
- [ ] Does the guard run on the path in question, or only on a neighbouring one?
      (Grant-time vs. runtime vs. deploy-time is the recurring trap here.)
- [ ] Is the guard scoped to what you think? User-scoped, role-scoped and
      assignment-time checks see genuinely different violations, and none of the
      three is a superset of another.

## Environment

- [ ] Which environments does this bite in — fresh install, existing install,
      production only? Say it explicitly.
- [ ] Would it be invisible locally? The two most expensive defects on this
      project both were.
- [ ] Does the finding depend on state of the local copy that a reset would
      destroy? If so, say what must not be reset.

## Privacy

- [ ] Only `user#<id>` and `school#<id>` — no names, no emails.
- [ ] No amounts, no row contents.
- [ ] Counts and structure only.

## Honesty

- [ ] Severity right-sized: stop / fix / ticket, with the reason for that level.
- [ ] Uncertainty stated as uncertainty.
- [ ] Anything that contradicts the brief stated first, not buried.
- [ ] Any mistake of your own owned in one line at the top, then move on.
