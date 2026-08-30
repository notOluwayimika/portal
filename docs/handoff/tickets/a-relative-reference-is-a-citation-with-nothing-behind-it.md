# TICKET — a relative reference is a citation with nothing behind it

**Status:** open as a rule that does not exist. Both instances below are fixed; the class is not.

## The class

A reference that names its **position** rather than its **target** — `(same source)`, `(see above)`,
`as above`, `ibid` — resolves correctly only while the thing it points at stays adjacent. Any
insertion between them silently repoints it, and nothing anywhere notices: not review, not a test,
not the citation lint.

## Two instances, two media

**In a skill, 27 August.** `.claude/skills/finance-drive/SKILL.md`'s `php artisan serve` entry ended
`(same source)`, resolving to the `:8001` Sanctum entry above it purely by adjacency. Adding a third
friction entry between them repointed it at the new entry. Caught by the author mid-edit, not by any
gate.

**In a code comment, earlier.** `BillableEnrollmentAdapter.php::toBillableEnrollment()` said *"The
enrollment row carries NO school_id (see above)"* while the thing above said the opposite — written
up as F4 of `docs/handoff/reports/feat-u6-cohort-enrollment-port.md`, described there as *"the trap
left half-closed"*.

Same failure, in a `.md` and in a `.php`, found twice by a human reading carefully, generalised
neither time.

## Why the citation lint cannot help, and that is the point

`bin/ci-citation-lint.php` works by requiring a citation to **name its target** — a path, a line, a
symbol it can resolve. A relative reference names none of those. There is no token to check, so the
lint is not weak here; it is structurally absent, and always will be.

That is what makes the class worth writing down rather than assuming a gate covers it. Everything
else in this project's citation discipline has a machine behind it. This one has only the reader.

## What closes it

A rule, and it is one sentence: **a reference states its target, never its position.** Name the entry,
the file, the section. `(the Sanctum entry's source)` survives an insertion; `(same source)` does not.

Where it belongs is a judgement — `CLAUDE.md` alongside the other writing rules is the obvious home,
since it applies to comments, reports, skills and tickets equally, and none of those has a lint.

A grep is not the fix but is worth running once when this is picked up — and it will mislead you, so
read this first. **Every parenthesised occurrence left in the repository is a document *describing*
the bug rather than committing it**: four in this ticket, one in the u6 report. There are no live
instances. A future reader running that grep and finding five hits has found the write-ups, not the
defect; check what the surrounding sentence is doing before acting on a count.

Bare `above`/`below` in prose is far more common and is mostly ordinary English — do not try to
mechanise the difference, which is the trap the boundary-lint and negated-expectation gates both
document in their own words.

## One note that is NOT a finding

The first repair — writing the cited path out in full — reddened the citation lint, because
`docs/handoff/drives/2026-07-25/README.md:77` grew from a baselined 2 occurrences to 3. That is
**documented, argued behaviour**: the baseline key is `rule / citingPath / citedToken / count`
(`bin/ci-citation-lint.php:167`), and its docblock states at `:172` that a second byte-identical
citing line raises the count and fails. The lint refusing a *new* citation of a *baselined* target is
the shrink-only ratchet doing its job.

The right move was the one taken — name the entry rather than re-cite it — which removes the relative
reference instead of duplicating a citation. Recorded here only so the next person to hit it
recognises it as the ratchet rather than a bug.
