# TICKET — the diff-aware gate steps cannot see uncommitted work, so people reach past them

**Status:** open, not implemented. Raised by `feat/finance-bank-accounts`; deliberately not fixed
there, because it modifies the enforcement floor that verifies that very commit.

**Root:** `bin/lint-changed.sh` and `bin/ci-grants-convergence-lint.php` scope to **committed**
changes. They are therefore unusable on the tree a person is actually working on — so the person
reaches past them and runs the underlying tool directly, against a path. **Going around them is what
produces 71-file formatting diffs.**

## This is a scoping defect, not a reporting one

An earlier draft of this ticket framed it as a "silent green", and that framing was **wrong** and
would have been closed by a change that fixed nothing. Neither step is silent — both report honestly
about the range they were given:

| Step | What it prints when it examines nothing | Where |
|---|---|---|
| `lint-changed` | `==> Pint: no changed PHP files` | `bin/lint-changed.sh:59`, `:66`, `:73` |
| `grants-convergence-lint` | `OK — database/seeders/RbacSeeder.php is unchanged in this diff.` | `bin/ci-grants-convergence-lint.php:514` |

Both statements are TRUE of the range. The range is the defect:

```
bin/lint-changed.sh:51
  git diff -z --name-only --diff-filter=ACMR "$BASE"...HEAD
```

Three dots, and against **HEAD**. Uncommitted work — worktree and index — is outside it. On a branch
with nothing yet committed the set is empty and the step correctly reports examining nothing.

## Five symptoms, one root — and two of them CAUSE the other three

| # | Branch | Symptom |
|---|---|---|
| 1 | #229 | `lint-changed` reported a clean pass having examined **zero files**, because the work was uncommitted |
| 2 | this branch | `grants-convergence-lint`'s first green reported `RbacSeeder.php is unchanged in this diff`, same reason |
| 3 | #223 | `pint` run across `tests/`; unrelated files reformatted into the commit |
| 4 | this branch | `pint` run across five directories — **89 files where the change was 18**; all 71 strays correctly formatted, and all of them noise that would have made the diff unreviewable |
| 5 | this branch, again | `pint $(git diff --name-only HEAD …)` on an already-clean tree expanded to **no arguments**, and `pint` with no path lints the WHOLE PROJECT — **172 files**. This happened in the command written immediately after the rule "never run pint against a directory" was added to `CLAUDE.md` |

**3, 4 and 5 are the consequence of 1 and 2, and that is the part a future reader cannot reconstruct.**
The tool that is *supposed* to lint your changes cannot see them until you commit, so you reach for
`pint <directory>` to check your work before committing — and a directory is the only argument that
feels natural when you do not have a file list to hand. The formatting sweep is not carelessness
downstream of the scoping bug; it is the workaround the scoping bug requires.

**And the workaround is itself booby-trapped**, which is symptom 5 and the reason requirement 1
matters more than a documented habit. `pint` with no path argument lints everything, so the
hand-rolled substitute — `pint $(git diff --name-only …)` — becomes a whole-project reformat the
moment the substitution is empty, silently and with a zero exit code. A person following the written
rule correctly still gets a 172-file diff. No amount of documentation fixes an interface whose
failure mode is "do the largest possible thing when given nothing"; only requirement 1 removes the
need to hand-roll it at all.

## Requirement 1 — fix the scoping

`bin/lint-changed.sh` must be usable on an uncommitted working tree, so there is **no operational
reason** to run `pint` against a directory. The file set must be the union of:

- what the branch changed (`$BASE...HEAD`), **and**
- what is uncommitted — **both worktree and index**

`bin/ci-grants-convergence-lint.php` takes the same base and has the same blind spot; it needs the
same treatment.

**The pre-push hook and an interactive run see different trees BY NATURE**, and the fix must make
both correct rather than making one match the other. At pre-push the work is committed, so
`$BASE...HEAD` is the whole truth and the uncommitted union adds nothing. Interactively the
uncommitted half is the entire point. A union is right in both cases; "just use `$BASE`" or "just
diff the worktree" is right in only one, and the step is run in both.

## Requirement 2 — put the count where the DECISION is made

**Not "report the count" — that already exists, already works, and did not help.**

`bin/quality:78-95` prints `lint-changed`'s per-tool counts on a green, shipped in #229. On this
branch it printed, accurately, on both versions of the tree:

```
bloated commit (89 files):   Pint (check) on 82 changed PHP file(s)
real commit    (18 files):   Pint (check) on 12 changed PHP file(s)
```

**82 against 12.** The instrument was right. It scrolled past inside fourteen steps of gate output
and nobody read it. The 71-file sweep was caught by `git diff --stat` compared against a mental model
of the change, at the push — not by the number the gate had already printed four minutes earlier.

So the requirement is **placement**: the count must reach the moment of decision — surfaced in the
pre-push summary, beside the number of files the commit touches, where a person is about to choose to
push. A number in the middle of a fifteen-step log is data nobody reads; the same number at the push
prompt is a check.

Zero examined stays a legitimate outcome — a docs-only change lints nothing. What must not happen is
a zero that reads like coverage.

## Explicitly out of scope

**No gate should fail a commit for containing correct formatting changes.** Every one of those 71
files was *more* compliant afterwards; a rule that rejected them would be wrong, and would make the
codebase worse by discouraging formatting fixes.

What catches an unintended sweep is `--stat` compared against your own model of the change before
pushing. That is a judgement, not a rule, and it is what caught this one.

## The enumeration — walked from `bin/quality`

Exactly **two** of the fifteen steps are diff-aware; both are passed `"$BASE"` explicitly.

| Step | Line | Diff-aware? |
|---|---|---|
| **3 lint-changed** | 177 | **YES** — `bash bin/lint-changed.sh "$BASE"` |
| **8 grants-convergence-lint** | 213 | **YES** — `php bin/ci-grants-convergence-lint.php "$BASE"` |
| 1 dependency-integrity · 2 wayfinder · 5 build · 7 boundary-lint · 9 money-lint · 10 runtime-zero · 11 identifier-generation · 12 sql-clock · 13 arch · 14 larastan | — | no — whole-tree or state |
| 4 tsc-ratchet · 6 authz-lint · 15 test-ratchet | — | no — **baseline** comparisons |

Derived two ways that agree: `grep -n '"\$BASE"' bin/quality` returns those two `check` lines, and
`grep -l "git diff" bin/*` returns only `lint-changed.sh`, `ci-grants-convergence-lint.php` and
`quality` itself.

**The baseline steps (4, 6, 15) are a different shape and are NOT in scope.** They compare against a
committed baseline rather than a diff, so they always examine the whole tree; their failure mode is a
stale baseline — ADR 0041's subject.

## Related

- ADR 0053 residual #5 — the floor is not deterministic. Same family: a gate whose green is weaker
  evidence than it looks.
- `docs/handoff/reports/feat-finance-capture-columns-s2-s3.md` — symptom 1, with the pre-push
  contrast that exposed it.
- `docs/handoff/reports/feat-finance-bank-accounts.md` — symptoms 2 and 4, including the 82-vs-12
  figures.
