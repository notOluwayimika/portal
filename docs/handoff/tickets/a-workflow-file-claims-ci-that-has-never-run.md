# TICKET — a workflow file claims CI that has never run, and the repository is written as though it had

**Status:** open, decision not made. Raised by `fix/release-gate-static-analysis-composer-timeout`,
which had to reason about `.github/workflows/lint.yml` in order to change a `bin/quality` step, and
found the file is load-bearing for documentation while executing nothing.

## The fact

`.github/workflows/lint.yml` exists, is 118 lines, is syntactically valid, and triggers on every push
to `main`/`staging` and every pull request against them. It has never executed a step. Neither has
`.github/workflows/tests.yml`, which sits beside it on the same triggers — **the brief for this
ticket named one file; there are two.**

Derived, not remembered:

```
$ gh api "repos/notOluwayimika/portal/actions/runs?per_page=1" --jq '.total_count'
1544

$ gh api "repos/notOluwayimika/portal/actions/runs/32665327913/jobs" \
    --jq '.jobs[] | {name, conclusion, steps: (.steps|length)}'
{"conclusion":"failure","name":"quality","steps":0}

$ gh run view 32665327913
X staging linter notOluwayimika/portal#111 · 32665327913
JOBS
X quality in 3s
ANNOTATIONS
X The job was not started because your account is locked due to a billing issue.
```

1,544 runs. Zero steps. The account is billing-locked and billing is not being pursued
(`CLAUDE.md` § "The enforcement floor is LOCAL, permanently"), so this is the steady state, not a
backlog.

**The runs conclude `failure`, not `skipped`.** That matters more than the file merely being inert:
every pull request on this repository carries two red checks that describe nothing about the change.
`fix/subledger-clock-frame-test-race` and `fix/release-gate-static-analysis-composer-timeout` both
show red `linter` and `tests` on their PRs, and both passed the full local gate.

## Why this is worth a ticket rather than a shrug

Same shape as a watched-red recipe that no longer reproduces: **a claim with nothing behind it,
believed because it is written down.** The file is not neutral clutter. It is cited as the enforcing
authority throughout the repository, and those citations are read as true:

- **`CONTRIBUTING.md:65-70`** — the gate table attributes six enforcement mechanisms to the
  workflows: test failures to `tests.yml`, and tsc errors, commented-out authz, boundary rules,
  architecture rules and static analysis to `lint.yml`. A contributor reading that table concludes
  their branch is checked on push by CI. It is not; `bin/quality` and the `.githooks/pre-push` hook
  are the only things that ever run.
- **`bin/quality:10-11`** — "This mirrors the CI jobs step for step … If you change
  `.github/workflows/{lint,tests}.yml`, change this too." This is a live maintenance obligation
  pointing at a file that cannot execute. It was paid this week: changing step 15 to call
  `vendor/bin/phpstan` directly required arguing, in a code comment, why bin/quality now deliberately
  diverges from a mirror that has never run.
- **`CONTRIBUTING.md:70`** still describes static analysis as `lint.yml` → `composer analyse`, which
  is the invocation that could not complete on a cold cache and was replaced in `bin/quality` at
  `6c4cbda`. The workflow file still carries it. Two spellings of one gate, one of them unreachable.

A reader opening this repository — a new contributor, a reviewer, or an agent — sees workflow files,
a CONTRIBUTING table naming them, and red checks on PRs, and forms an accurate-sounding and wrong
model of what is enforced and by what. `CLAUDE.md` records the truth, but `CLAUDE.md` is one file
against a workflow directory, a contributor guide and the PR checks UI.

## The choice

Two options, and this ticket deliberately does not pick between them:

**Enable it.** Resolve the billing lock so the workflows run. Restores the mirror obligation in
`bin/quality:10-11` to something meaningful, makes the CONTRIBUTING table true, and buys back the two
residuals the local floor cannot cover (the PHP 8.3/8.4/8.5 matrix and a clean-room OS/env — see
`CLAUDE.md`'s residuals table). Costs money and re-opens the question of what happens when a workflow
and `bin/quality` disagree.

**Delete them.** Remove both workflow files, rewrite the `CONTRIBUTING.md` gate table to name
`bin/quality` and its scripts, and drop the mirror instruction from `bin/quality`'s header. Makes the
repository say what is true, ends the red-check noise on every PR, and costs the option of turning CI
back on without rewriting the files. Does not change what is enforced by one step, because nothing is
enforced by them today.

Whichever is chosen, the residuals table in `CLAUDE.md` is the thing to update alongside it — "PHP
version matrix" and "Clean-room OS/env" are listed as accepted permanent gaps *because* Actions is
off, and enabling would close them.

## Not proposed here

Which option, and on what timeline. `CLAUDE.md` already states that reviving Actions "is a fresh
decision, not a trigger waiting to fire" — this ticket does not make that decision, it records that
the files are still present, still cited as authority, still failing on every pull request, and that
leaving them in place is itself a choice nobody has made explicitly.
