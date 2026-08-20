# feat/docs-only-gate — a push that changes only documentation does not run the suite

Branch: `feat/docs-only-gate`, cut from `origin/staging` at `f5ac5ab`.
Files: `bin/is-docs-only-push` (new), `.githooks/pre-push` (66 inserted lines, nothing
removed or edited), `tests/Feature/Quality/DocsOnlyPushCoverageTest.php` (new, 25 arms) and
`tests/Feature/Quality/NothingReadsDocumentationTest.php` (new, 5 arms), plus one ticket.

`bin/quality` is not changed. It still means "run everything", for a manual run and for
`bin/quality-promote`.

---

## 1. The premise, checked before anything was built

The brief named two commits on `feat/u7-supplementary-invoice-wire` and asked for them to be
verified rather than accepted. `git show --stat` on both:

| commit | files | contents |
| --- | --- | --- |
| `7237ad3` | 12 | ten drive PNGs under `docs/handoff/drives/2026-08-20-supplementary-invoice/`, `docs/handoff/reports/feat-u7-supplementary-invoice-wire.md`, `docs/handoff/tickets/nothing-shows-which-invoices-are-supplementary.md` |
| `8570a0b` | 1 | `docs/handoff/reports/feat-u7-supplementary-invoice-wire.md` |

Neither touched a file outside `docs/`. The premise stands as stated; nothing about it
narrows.

Both ranges are recognised by the finished checker:

```
$ bin/is-docs-only-push 7237ad3^ 7237ad3   → exit 0, 12 paths listed
$ bin/is-docs-only-push 8570a0b^ 8570a0b   → exit 0, 1 path listed
$ bin/is-docs-only-push 7237ad3^ 8570a0b   → exit 0   (both, as one push)
$ bin/is-docs-only-push ab9abc6^ ab9abc6   → exit 1   (a migration commit)
```

## 2. RETRACTED — the narrow-case claim in this section was FALSE

**The claim published here was false, and it is retracted in those words.** It read: *"no test
and no lint in this repository reads a file under `docs/`"*, and *"The rule as stated is
therefore not wrong on this repository's current contents."* Both are wrong.

`app/Console/Commands/AuthzObservations.php:22` holds

```php
public const CLASSIFICATIONS_PATH = 'docs/runbooks/authz-observation-classifications.json';
```

resolves it through `base_path()` at `:149` and reads it with `file_get_contents()` at `:155`.
`tests/Feature/Rbac/AuthzObservationsCommandTest.php` reads the same real file at `:59-60` and
asserts at `:46-53` that the gate DENIES — which holds only while `"classes"` is empty.

### The mechanism that made the claim false

The check was `grep -rn "docs/" tests/`, **which begins by matching the literal string `docs/`
in the file**. The path is a class constant, so the one file that mattered was invisible at
step one:

```
$ grep -c "docs/" tests/Feature/Rbac/AuthzObservationsCommandTest.php
0
```

The file never entered the result set, so no amount of reading the results could have caught
it. A method that starts by grepping for the string cannot find a path that is not spelled out
where it is used.

### The consequence, reproduced before anything was changed

One commit making exactly the edit `docs/runbooks/authz-observation-review.md` step 3
prescribes — one entry added to `"classes"` — under the location-only rule:

```
$ bin/is-docs-only-push HEAD^ HEAD
docs/runbooks/authz-observation-classifications.json
exit=0

$ printf 'refs/heads/x <head> refs/heads/x <base>\n' | bash .githooks/pre-push origin url
pre-push: DOCS-ONLY PUSH — bin/quality was NOT RUN.
Every file changed in this push is under docs/:
    docs/runbooks/authz-observation-classifications.json
```

and the suite on the tree that skip let through:

```
control, unmodified tree:
{"tool":"pest","result":"passed","tests":5,"passed":5,"assertions":15,"duration_ms":11700}

after the prescribed edit:
{"tool":"pest","result":"failed","tests":5,"passed":3,"assertions":14,"duration_ms":11616,"failed":2,
 "failures":[
  {"test":"…it_summarizes_denial_classes_with_role_breakdown_and_classification_status",
   "message":"Failed asserting that two strings are identical.\n--- Expected\n+++ Actual\n@@ @@\n-'UNCLASSIFIED'\n+'expected'"},
  {"test":"…it_the_unclassified_gate_DENIES_while_an_observed_class_lacks_a_classification__instrument_bite_proof_",
   "message":"Unexpected status code 0 was received.\nFailed asserting that 0 is not equal to 0."}]}
```

5/5 to 3 passed 2 failed, gate skipped.

### The counts published here do not reproduce either

The figures given were "28 hits across 24 files" for `tests/` and "12 hits across 8 files" for
`bin/`. Re-run today, plainly:

```
$ grep -rn "docs/" tests/ | wc -l          57
$ grep -rl "docs/" tests/ | wc -l          23
$ grep -rn "docs/" bin/   | wc -l          21
$ grep -rl "docs/" bin/   | wc -l           9

# excluding the two files this branch itself adds, i.e. the tree as it was:
$ grep -rn "docs/" tests/ | grep -v DocsOnlyPushCoverageTest | wc -l    31
$ grep -rn "docs/" bin/   | grep -v is-docs-only-push        | wc -l    13
```

The cold review reports 68 across 23 files and 41 across 9. The **file** counts reconcile
exactly — 23 and 9 — against a plain `grep -rl`. The line counts do not, and the published
28/12 match none of them. The mechanism for the published pair is visible in the original
commands: both were piped through `head -40` and the `bin/` one through a second
`grep -E "file_get_contents|glob|scandir|…"` filter, and the number reported was what was
visible on screen rather than a `wc -l`. A truncated pipeline reported as a total.

### A second count in this branch was wrong the same way

The count of non-prose files under `docs/` was given as **eleven**. It is **nine**:

```
$ git ls-files docs/ | grep -viE '\.(md|png|jpg|jpeg|gif|svg)$' | wc -l                  11
$ git ls-files -z docs/ | tr '\0' '\n' | grep -viE '\.(md|png|jpg|jpeg|gif|svg)$' | wc -l   9
```

The two extra entries are:

```
"docs/Finance Module \342\200\224 Implementation Master Plan - v10.md"
"docs/Finance-Module\342\200\224Phase-1.md"
```

`git ls-files` QUOTES a path holding a non-ASCII byte, so each name ends with a `"` — and the
anchor `\.md$` therefore does not match. They are `.md` files counted as non-prose.

This is the same defect class as §6's `-z` finding, **committed in the very message that warns
about it**: the commit body for the first push says `--no-renames` and `-z` are load-bearing
because git quotes non-ASCII paths, and the count in it was made with a command that git had
quoted. Recorded here because it is evidence for the rule — a path is not a string you can
pattern-match casually — not as an aside.

### What replaced the claim

Nothing in this report now asserts that no reader exists. The invariant is
`tests/Feature/Quality/NothingReadsDocumentationTest.php` (§5), and the rule itself was
narrowed from location to FORMAT (§3), which is what makes the real `.json` reader harmless:
the gate runs for that push.

## 3. The rule

`bin/is-docs-only-push <base-sha> <head-sha>` exits **0** when every path in
`git diff --name-only --no-renames <base>..<head>` is under `docs/` **and ends in an
allowlisted documentation extension**, **1** when any path is not, and **2** when the range
could not be computed.

**DOCUMENTATION IS A FORMAT, NOT A DIRECTORY.** The first cut tested location alone and §2 is
what that cost. The allowlist lives on one line of the script and is read back by two tests, so
the three cannot drift:

```bash
DOC_EXTENSIONS="md png jpg jpeg gif svg"
```

A `.json`, `.sql`, `.txt`, `.pdf` or extensionless path under `docs/` falls to the full gate.
The one-sentence reason: **data under `docs/` gets read by code**, and `.txt` drive logs and
`.pdf` captures are excluded not because anything reads them but because an allowlist is the
safe direction — a format nobody thought about falls to the full gate rather than past it.

The extension test also settles two shapes a location rule cannot: `docs/tools/run.php` is PHP,
and a submodule gitlink at `docs/vendored` is reported by git as a bare directory name with no
extension at all. Both run the full gate, and both are arm (s). **The cold review filed these
as a separate ticket; they are closed by the format rule instead of filed.**

It never returns 0 in these cases:

- **the base is all zeros.** git sends zeros for a ref that does not exist on the remote. A
  new branch has no base to diff against, and every commit beneath its first push was never
  gated by this hook.
- **the range changes no files.** "Every file is under `docs/`" is vacuously true of no files.
- **either sha is not a commit in this repository**, or `git diff` fails after the shas
  resolve. Exit 2, not 1 — `bin/landed:32-36`'s discipline, that "wrong" and "unknown" are
  different answers. The caller collapses them, because both mean "run the gate".

`.githooks/pre-push` consults it once per pushed ref, after the release-gate block and before
the `bin/quality` invocation. It skips only when **every** ref's range came back 0. It sets
"not docs-only" and runs the full gate when:

- any ref's `remote_ref` is `refs/heads/main` — checked before the checker is consulted;
- the checker exits non-zero for any reason, "not documentation" and "could not determine"
  alike;
- `bin/is-docs-only-push` is absent or not executable.

`.claude/**` and root-level `*.md` are not in this cut and were not added.

### Five details that are load-bearing rather than incidental

**The prefix carries the slash.** `docs*` is the glob written first and it matches
`docsomething.php` at the repository root, which is a PHP file. Arm (e) is that file.

**`--no-renames`.** With rename detection on, `git diff --name-only` reports a move as its
DESTINATION only. `docs/moved.md` → `moved.md` presents as the single path `moved.md`, which
is caught; the mirror image `thing.php` → `docs/thing.md` presents as the single path
`docs/thing.md` and would be judged docs-only while a PHP file was deleted. `--no-renames`
splits every move into a delete and an add, so both ends are judged. Arm (f) is the first
direction; the second is the reason, and is not separately planted.

**Two-dot, not three.** The range compares the two trees git is being asked to move between.
Where the remote holds a commit the local ref does not — a force-push — a two-dot diff reports
the file being reverted as changed; three-dot diffs from the merge base and does not. Arm (w)
pins it: the first version shipped this correctly and **unpinned**, and the one-character
mutation `..` → `...` left all seventeen arms green.

**The diff runs from the top level, with relative paths forced off.** `git push` is invoked
from whatever directory the developer is in and the hook passes that cwd straight through. With
`diff.relative=true` set — a real setting — a diff run from a subdirectory emits paths relative
to it and DROPS everything outside it, so from a directory containing a nested `docs/` a range
holding `app/T.php` reports as documentation. `public/assets/docs/` is tracked here, so the
shape is reachable. `git -C "$top" -c diff.relative=false` closes both halves; arm (x) plants
it, **on the checker, because no hook fixture can reach it** — the hook fixture always cds to
the top level and carries no local config.

**Every printed path is escaped.** A filename may legally contain a newline, and printed raw it
splits into two lines under the SKIP banner, the second of which can read like a source file
the reader believes was checked. The verdict was never wrong; the display was. Backslash is
escaped first, then newline, carriage return and tab; nothing else is touched, so ordinary
non-ASCII documentation filenames stay readable. `printf '%q'` was rejected: it renders an em
dash as `\342\200\224` under `LC_ALL=C` and differently under `en_US.UTF-8`, which is a
machine-dependent output this file should not have.

## 4. What a skipped green means, and what it does not

The hook prints, in yellow, and lists every file:

```
pre-push: DOCS-ONLY PUSH — bin/quality was NOT RUN.
Every file changed in this push is under docs/:
    docs/handoff/reports/feat-u7-supplementary-invoice-wire.md
  range(s) examined:
    8167282f..8570a0b0  refs/heads/feat/u7
  Nothing was verified. No test, lint, type-check, arch rule or Larastan run
  measured this push — this is a SKIP, not a quality result. The next push that
  touches any file outside docs/ runs the full gate over everything since the base.
```

It **means**: every file in the pushed range is under `docs/`, and the fifteen steps did not
run.

It does **not** mean anything was checked. No test, lint, type-check, architecture rule or
Larastan pass measured the push. The code beneath the pushed commits is unmeasured by *this*
push; what it carries is whatever the last non-docs push established.

`bin/quality`'s success line is `✓ quality: PASS — per-push floor. Promoting to main? run bin/quality-promote.`
The skip line shares none of those words. Arm (k) asserts the absence of `quality: PASS` and
of `running bin/quality` in the skip output, so a scrollback can be grepped for either
sentence and will never return the other kind of green.

The file list printed is the list the checker itself judged — the hook captures the checker's
stdout rather than re-deriving the paths, so the displayed files cannot disagree with the
verdict.

## 5. The arms, and the mutation each one caught

`tests/Feature/Quality/DocsOnlyPushCoverageTest.php`, **25 arms** (17 from the first cut, 8
added by the cold review), group `arch`. On
`LandedCheckCoverageTest`'s model: each arm builds a real repository under `mktemp -d`, runs
the real script, and asserts the exit code and the message. No network, no database. Config
isolation is `GIT_CONFIG_GLOBAL=/dev/null`, `GIT_CONFIG_SYSTEM=/dev/null`, `HOME` redirected
into the temp directory, and `GIT_CONFIG_COUNT` with all 32 `GIT_CONFIG_KEY_n` /
`GIT_CONFIG_VALUE_n` pairs unset — those outrank every config file including the two
redirected above.

Arms (k) through (q) run the **real** `.githooks/pre-push`, copied into the fixture repo,
against planted stdin, with a stub `bin/quality` that prints `___QUALITY_RAN` and exits 0. The
stub exits 0 on purpose: an arm asserting on `___QUALITY_RAN` is asserting that the gate was
*invoked*, which is the only thing the wiring is responsible for.

All 17 green:

```
$ DB_DATABASE=portal_testing ./vendor/bin/pest tests/Feature/Quality/DocsOnlyPushCoverageTest.php
{"tool":"pest","result":"passed","tests":25,"passed":25,"assertions":79,"duration_ms":10703}

$ DB_DATABASE=portal_testing ./vendor/bin/pest tests/Feature/Quality/NothingReadsDocumentationTest.php
{"tool":"pest","result":"passed","tests":5,"passed":5,"assertions":14,"duration_ms":594}
```

### The mutations

| # | mutation | applied to |
| --- | --- | --- |
| M1 | `docs/?*)` → `docs*)` — the naive prefix glob | checker |
| M2 | `--no-renames` dropped from the diff | checker |
| M3 | `-z` dropped, and `read -r -d ''` → `read -r` | checker |
| M4 | the all-zeros base rule deleted | checker |
| M5 | unknown-sha `exit 2` → `exit 0` | checker |
| M6 | empty-range `exit 1` → `exit 0` | checker |
| M7 | `exit "$verdict"` → `exit 0` | checker |
| M8 | `refs/heads/main) docs_only=0` deleted | hook |
| M9 | the `else docs_only=0` on a non-zero checker exit deleted | hook |
| M10 | `[ -x "$checker" ] \|\| docs_only=0` deleted | hook |
| M11 | the skip line replaced with `bin/quality`'s success wording | hook |
| M12 | the diff read back through a command substitution (the original defect, §6) | checker |
| M13 | M9 and M10 together | hook |
| M14 | the docs-only block moved above the release-gate block | hook |
| M15 | M8 and M14 together | hook |
| M16 | `"$BASE..$HEAD_SHA"` → `"$BASE...$HEAD_SHA"` | checker |
| M17 | `git -C "$top" -c diff.relative=false` dropped | checker |
| M18 | the extension check deleted — the rule reverts to location-only | checker |
| M19 | `escape_path` replaced by a raw `printf '%s\n'` | checker |
| M20 | `json` appended to `DOC_EXTENSIONS` | checker |
| M21 | class-constant resolution disabled in the reader sweep | invariant test |
| M22 | the reader sweep returns no call sites | invariant test |

### Arm by arm, with the raw red

**(a) range touches only `docs/` → docs-only.** Two files, not one, deliberately — see §6.
Killed by **M12**:

```
it (a) says docs-only when the range touches only docs/
Failed asserting that 1 is identical to 0.
```

**(b) range touches `docs/` and one `.php` → NOT docs-only.** Killed by **M7**:

```
it (b) refuses when the range touches docs/ and one PHP file
Failed asserting that 0 is identical to 1.
```

**(c) range touches only `.php` → NOT docs-only.** Killed by **M7**:

```
it (c) refuses when the range touches only PHP
Failed asserting that 0 is identical to 1.
```

**(d) several commits, mixed → NOT docs-only.** The range is the unit, not the commit: a
checker reading only the tip commit would skip a PHP file sitting between two prose commits.
Killed by **M7**:

```
it (d) refuses a multi-commit range whose middle commit is code
Failed asserting that 0 is identical to 1.
```

**(e) `docsomething.php` at the repository root → NOT docs-only.** Killed by **M1**, the naive
glob, which returns 0 — the suite skipped over a PHP file:

```
it (e) refuses docsomething.php at the repository root — a prefix match is not a directory
Failed asserting that 0 is identical to 1.
```

**(f) a file renamed out of `docs/` → NOT docs-only.** Killed by **M2**. The red shows exactly
what rename detection hides — the delete side under `docs/` is not in the output at all:

```
it (f) refuses a file renamed out of docs/
Failed asserting that 'moved.md\n
is-docs-only-push: not documentation: moved.md\n
___EXIT:1' [ASCII](length: 65) contains "docs/moved.md" [ASCII](length: 13).
```

**(g) base is all zeros → NOT docs-only, regardless of contents.** Killed by **M4**:

```
it (g) refuses when the base is all zeros, however docs-only the contents are
Failed asserting that 2 is identical to 1.
```

**(h) unknown/missing sha → NOT docs-only.** Killed by **M5**:

```
it (h) cannot determine anything from a sha that is not in the repository
Failed asserting that 0 is identical to 2.
```

**(i) a range that changes nothing → NOT docs-only.** Killed by **M6**:

```
it (i) refuses to call a range that changes nothing docs-only
Failed asserting that 0 is identical to 1.
```

**(j) a `docs/` path holding a non-ASCII byte → docs-only.** Killed by **M3**: without `-z`,
git quotes and octal-escapes the path, it no longer begins with `docs/`, and the checker
refuses for the wrong reason:

```
it (j) reads a docs/ path containing a non-ASCII byte
Failed asserting that 1 is identical to 0.
```

**(k) the hook skips `bin/quality` and says so in words `bin/quality` never prints.** Killed
by **M11**:

```
it (k) the hook skips bin/quality on a docs-only push, and says so in words bin/quality never prints
Failed asserting that '✓ quality: PASS — per-push floor. Promoting to main? run bin/quality-promote.\n
Every file changed in this push is under docs/:\n
    docs/report.md\n …' contains "DOCS-ONLY PUSH" [ASCII](length: 14).
```

**(l) the same push plus one `.php` runs the full gate.** Killed by **M9**:

```
it (l) the hook runs the full gate when the same push touches one PHP file
Failed asserting that 'pre-push: DOCS-ONLY PUSH — bin/quality was NOT RUN.\n
Every file changed in this push is under docs/:\n
  range(s) examined:\n …' contains "running bin/quality" [ASCII](length: 19).
```

**(m) a docs-only push to `refs/heads/main` still hits the release gate.** No stamp in the
fixture, so the correct outcome is the existing refusal at `.githooks/pre-push:48`. Killed by
**M15**:

```
it (m) a docs-only push to main still hits the release gate
Failed asserting that 0 is identical to 1.
```

**(n) one non-docs ref in a multi-ref push runs the full gate for all of them.** Killed by
**M9**:

```
it (n) one non-docs ref in a multi-ref push runs the full gate for all of them
Failed asserting that 'pre-push: DOCS-ONLY PUSH — bin/quality was NOT RUN.\n
Every file changed in this push is under docs/:\n
    docs/report.md\n
  range(s) examined:\n
    00f8ba22..dad2ab73  refs/heads/feat/docs\n …' contains "___QUALITY_RAN" [ASCII](length: 14).
```

**(o) the first push of a new branch runs the full gate even though its commit is prose.**
Killed by **M9**:

```
it (o) the first push of a new branch runs the full gate even though its commit is prose
Failed asserting that 'pre-push: DOCS-ONLY PUSH — bin/quality was NOT RUN.\n
Every file changed in this push is under docs/:\n
  range(s) examined:\n …' contains "___QUALITY_RAN" [ASCII](length: 14).
```

**(p) a docs-only push to `main` runs the full gate even WITH a valid release stamp.** Killed
by **M8**. This arm exists because arm (m) does not reach the code it appears to test: without
a stamp the release gate refuses at `.githooks/pre-push:48` and the docs-only block is never
reached, so (m) stays green with the `refs/heads/main` exclusion deleted — verified, M8 ran
both (m) and (p) and only (p) failed. With a stamp, the release gate passes and execution
falls through, which is the only path on which the exclusion does any work:

```
it (p) a docs-only push to main runs the full gate even WITH a valid release stamp
Failed asserting that 'pre-push: release gate verified for a4d0e953\n
pre-push: DOCS-ONLY PUSH — bin/quality was NOT RUN.\n
Every file changed in this push is under docs/:\n
    docs/report.md\n
  range(s) examined:\n
    0dea4097..a4d0e953  refs/heads/main\n …' contains "___QUALITY_RAN" [ASCII](length: 14).
```

**(q) the hook runs the full gate when the checker is not present at all.** Killed by **M13**:

```
it (q) the hook runs the full gate when the checker is not present at all
Failed asserting that 'pre-push: DOCS-ONLY PUSH — bin/quality was NOT RUN.\n
Every file changed in this push is under docs/:\n
  range(s) examined:\n …' contains "___QUALITY_RAN" [ASCII](length: 14).
```

### Two mutations that did NOT go red, and what that says

**M10** (the `[ -x "$checker" ]` guard alone) left arm (q) green:

```
{"tool":"pest","result":"passed","tests":1,"passed":1,"assertions":3,"duration_ms":753}
```

With the checker deleted, `changed="$("$checker" …)"` fails with 127, the `if` is false, and
the `else docs_only=0` produces the fall-through on its own. **M9** (the `else` alone) also
left (q) green, for the mirror reason: the `[ -x ]` guard produces it. The two mechanisms are
each redundant with the other, and arm (q) is red only under **M13**, which removes both. The
arm covers the outcome; no single-line mutation of the hook can defeat it.

**M14** (the docs-only block moved above the release-gate block) left both (m) and (p) green:

```
{"tool":"pest","result":"passed","tests":2,"passed":2,"assertions":9,"duration_ms":1012}
```

The `refs/heads/main` exclusion is checked inside the docs-only block itself, so the block's
position relative to the release gate does not change the answer. Arm (m) is red only under
**M15**, which deletes the exclusion *and* moves the block.

### The arms the cold review added

**(r) a `.json` under `docs/` that code reads through a class constant → NOT docs-only.** The
STOP, in miniature. Killed by **M18** (and by **M20**, which merely widens the allowlist to
admit it):

```
it (r) refuses a .json under docs/ that code reads through a class constant
Failed asserting that 0 is identical to 1.
```

**(s) a `.php` under `docs/` and an extensionless path under `docs/` → NOT docs-only.** Killed
by **M18**:

```
it (s) refuses a .php under docs/ and an extensionless path under docs/
Failed asserting that 0 is identical to 1.
```

**(t) `.txt` and `.pdf` under `docs/` → NOT docs-only.** Killed by **M18**:

```
it (t) refuses .txt and .pdf under docs/, which nothing reads
Failed asserting that 0 is identical to 1.
```

**(u) every allowlisted extension is accepted, case-insensitively.** The non-vacuity control
for (r)–(t): a rule that refused everything would pass all three of them. `.PNG` is included
because a screenshot arriving uppercase from a capture tool is not a different kind of file.

**(v) the allowlist in the script is the one the tests assert against.** Killed by **M20**:

```
it (v) the allowlist in the script is the one this test asserts against
Failed asserting that two arrays are identical.
--- Expected
+++ Actual
@@ @@
     3 => 'jpeg',
     4 => 'gif',
     5 => 'svg',
+    6 => 'json',
 ]
```

**(w) a force-push range that drops a file which left the remote tip → NOT docs-only.** Killed
by **M16**, the one-character `..` → `...`:

```
it (w) refuses a force-push range that drops a file which left the remote tip
Failed asserting that 0 is identical to 1.
```

**(x) not fooled by `diff.relative` and the caller cwd.** Killed by **M17**:

```
it (x) is not fooled by diff.relative and the caller cwd
Failed asserting that 0 is identical to 1.
```

**(y) a path containing a newline prints as one escaped line.** Killed by **M19**. The red is
the defect itself — the path split across two printed lines:

```
it (y) prints a path containing a newline as one escaped line
Failed asserting that 'docs/a\n
b.md\n
___EXIT:0' [ASCII](length: 21) contains "docs/a\nb.md" [ASCII](length: 12).
```

### The invariant, as a test rather than a claim

`tests/Feature/Quality/NothingReadsDocumentationTest.php`, 5 arms, group `arch`. It tokenises
every `.php` file under `app`, `bin`, `tests`, `config`, `database`, `routes` and `bootstrap`
with `token_get_all`, finds every `base_path(` / `realpath(` / `file_get_contents(` / `fopen(`
call and every `Storage::` / `File::` static call and every `__DIR__`, **resolves the first
argument including class constants**, and fails if one lands on a path under `docs/` whose
extension `bin/is-docs-only-push` would skip. On the current tree:

```
callSites=188  unresolved=86  violations=0
```

Constants resolve two ways: a `const NAME = 'literal';` declared in the same file (which is
what makes an unautoloadable fixture — and the real defect's shape — resolvable), and through
the autoloader for a class named via the file's `use` statements.

**The main arm bites on the real defect.** With
`app/Console/Commands/AuthzObservations.php:22` retyped from `.json` to `.md` — the constant
changed, nothing else — the sweep finds both readers, including the cross-class one in the test
that `grep -c "docs/"` scored 0 on:

```
it nothing in the codebase reads a documentation file the skip rule would let through
A file under docs/ with an extension bin/is-docs-only-push SKIPS is read by code, so a push
editing it would not run the gate. …

  app/Console/Commands/AuthzObservations.php:149  base_path  →  docs/runbooks/authz-observation-classifications.md
  tests/Feature/Rbac/AuthzObservationsCommandTest.php:59  base_path  →  docs/runbooks/authz-observation-classifications.md
```

**The planted-constant arm** is killed by **M21**, which disables constant resolution — the
exact blindness the original grep had:

```
it resolves a class constant — the shape the original grep could not see
Failed asserting that actual size 0 matches expected size 1.
```

**The non-vacuity assertion** is killed by **M22**, the shape every neutered lint in this
repository has had at least once:

```
it nothing in the codebase reads a documentation file the skip rule would let through
Failed asserting that 0 is greater than 50.
```

Two further arms: a `docs/*.json` read through a constant is **not** flagged (the gate runs for
that push), proved not-by-blindness against a `.md` twin of the same fixture that **is**
flagged; and a runtime-assembled path (`base_path('docs/handoff/'.$name)`) is counted as
unresolved rather than as resolved-and-clean.

## 6. A defect the arms caught during construction

The first version of the checker read the diff into a shell variable:

```bash
raw="$(git diff -z --name-only --no-renames "$BASE..$HEAD_SHA" 2>&1)"
```

`-z` is mandatory — `git diff --name-only` quotes and octal-escapes any path holding a
non-ASCII byte, the hazard `bin/lint-changed.sh:24-29` records silently dropping files — but
**bash discards NUL bytes in a command substitution**, so every path arrived concatenated with
no separator left in it. The `read -r -d ''` loop then read zero fields. Every range in the
repository, including both real docs-only commits, reported:

```
$ bin/is-docs-only-push 7237ad3^ 7237ad3
is-docs-only-push: 7237ad3^..7237ad3 changes no files — refusing to call an empty range docs-only
exit=1
```

The failure direction was safe by luck: it was the empty-range guard, added for the vacuous-
truth reason and not for this one, that turned the truncation into a refusal. Without that
guard the same silent truncation is a skip over an empty file list.

The diff now goes to a temp file, which carries NULs intact. M12 restores the defect and is
the stated mutation for arm (a); arm (a) plants **two** files rather than one because a
single-file arm would still have gone red but for the wrong reason.

## 7. Measured cost

**The skip path**, run in this repository against the real range `8167282f..8570a0b0`
(`7237ad3^..8570a0b`, the twelve files of `7237ad3` plus the one of `8570a0b`), by feeding the
real hook the stdin git would send:

```
$ time (printf 'refs/heads/feat/u7 %s refs/heads/feat/u7 %s\n' "$HEADSHA" "$BASE" \
    | bash .githooks/pre-push origin git@github.com:x/y.git)
0.04s user 0.03s system 88% cpu 0.074 total
```

The checker alone, ten consecutive runs over the same range: `0.507 total`, i.e. ~0.05 s each.

**The full gate**, this branch's own first push, which touches `bin/`, `.githooks/` and
`tests/` and therefore is not docs-only:

```
[1/15] dependency integrity … ✓
…
[15/15] tests (failure ratchet vs tests/ratchet-baseline.txt)
   ✓ test-ratchet

✓ quality: PASS — per-push floor. Promoting to main? run bin/quality-promote.
 * [new branch]      feat/docs-only-gate -> feat/docs-only-gate

END rc=0 ELAPSED=630s
```

**The saving on a docs-only push**, from those two figures: 630 s becomes 0.074 s. The two
commits in §1 would have cost 0.15 s between them instead of two full gates.

The 630 s is this branch's own gate, on this machine, at this moment. It is a sample of a
distribution, not a constant — see §8.

**This branch's second push** carries only this report and is therefore a real docs-only push.
Its result is recorded in §10.

## 8. What could not be verified

- **The full-gate figure is one run on one machine.** ADR 0053 records byte-identical code
  producing both PASS 14/14 and FAIL 23, and the suite's own band is ~350–440 s. A single
  elapsed time is a sample, not a constant, and the saving computed from it inherits that. The
  cold review states it did not re-derive the 630 s figure.
- **The reader invariant cannot prove no reader exists.** Its own docblock says so, and the
  cold review says the same: it cannot prove no reader exists via an idiom its sweep does not
  name. Concretely, three things are invisible to it — a path built at RUNTIME (86 of the 188
  call sites on the current tree resolve to nothing and are counted, not assumed clean); an
  IDIOM IT DOES NOT NAME (`include`, `require`, `SplFileObject`, `readfile`, `finfo`, Symfony
  `Finder`, a `Process` running `cat`); and a NON-PHP READER, since only `.php` files are
  tokenised, so a shell script under `bin/` doing `cat docs/something.md` is not seen. A grep
  for that last one would false-positive on every prose citation in those same scripts, so it
  is stated as a limit rather than approximated badly.
- **The allowlist is a judgement, not a derivation.** `.md` and five image formats are on it
  because nothing reads them today and because an allowlist fails toward the gate. No argument
  here proves an `.svg` can never be read by code; the invariant test is what would catch it if
  one started.
- **The mirror-image rename** (`thing.php` → `docs/thing.md`) is argued from `--no-renames`
  semantics and from arm (f)'s red, not separately planted.
- **Malformed stdin** — a ref line with fewer than four fields — is not planted, and is now its
  own ticket rather than a residual: `.githooks/pre-push:33-39` exits 0 silently on it and on a
  delete-only push, which predates this branch and is filed at
  `docs/handoff/tickets/pre-push-exits-zero-silently-on-shapes-it-cannot-parse.md`.
- **A squash or rebase push** is not planted. The force-push shape is now arm (w), and the
  two-dot semantics are the same for both, but the specific case was not run.
- The standing residuals of this floor are unchanged and apply here: no PHP version matrix, no
  clean-room OS, no remote enforcement, `--no-verify` still bypasses everything including this.

## 9. Files

| file | change |
| --- | --- |
| `bin/is-docs-only-push` | new. The decision, with its own exit code. Format allowlist, top-level diff with `diff.relative` forced off, escaped path printing. |
| `.githooks/pre-push` | 66 lines inserted at line 72. Nothing removed, nothing edited; `git diff -U0` is a single hunk `@@ -71,0 +72,66 @@`. `.githooks/pre-push:41-70`, the release gate, is untouched — and `:33-39` is untouched, which is why its silent exits are a ticket and not a fix here. |
| `tests/Feature/Quality/DocsOnlyPushCoverageTest.php` | new, 25 arms, group `arch`. |
| `tests/Feature/Quality/NothingReadsDocumentationTest.php` | new, 5 arms, group `arch`. The invariant. |
| `docs/handoff/tickets/pre-push-exits-zero-silently-on-shapes-it-cannot-parse.md` | new ticket. |
| `bin/quality` | **not changed.** |

## 10. The second push — the rule firing on a real push

This report is committed on its own, so the second push of this branch changes nothing but a
file under `docs/`. Recorded here after the fact:

```
$ git push
pre-push: DOCS-ONLY PUSH — bin/quality was NOT RUN.
Every file changed in this push is under docs/:
    docs/handoff/reports/feat-docs-only-gate.md
  range(s) examined:
    15540a18..49cef879  refs/heads/feat/docs-only-gate
  Nothing was verified. No test, lint, type-check, arch rule or Larastan run
  measured this push — this is a SKIP, not a quality result. The next push that
  touches any file outside docs/ runs the full gate over everything since the base.
To github.com:notOluwayimika/portal.git
   15540a1..49cef87  feat/docs-only-gate -> feat/docs-only-gate

ELAPSED=4s
```

4 s wall for the whole `git push`, of which the gate decision is the 0.07 s measured in §7;
the rest is the network round trip, which a full gate also pays on top of its 630 s. The same
push under the previous hook would have run the fifteen steps over a range whose only file is
this report.

The commit that adds this section is a fourth push, docs-only for the same reason, and is not
separately transcribed.

## 11. Cold review

The branch was reviewed cold, against the repository rather than against this report. The
verdict was that it does not merge until the stop is closed. Severities below are **as set by
the review**, carried unchanged and not re-ranked here.

### STOP 1 — the rule is by location and must be by format, and the invariant must be enforced

`app/Console/Commands/AuthzObservations.php:22` holds the classifications path as a class
constant, resolves it at `:149` and reads it at `:155`;
`tests/Feature/Rbac/AuthzObservationsCommandTest.php` reads the real file at `:59-60` and
asserts at `:46-53` that the gate DENIES, which holds only while `"classes"` is empty. The
review planted one commit making exactly the edit `docs/runbooks/authz-observation-review.md`
prescribes: the checker said docs-only, the hook skipped, and the suite went from 5/5 to 3
passed 2 failed.

Reproduced here before anything was changed — the raw control, the skip, and the red are in §2.

Closed by two changes:

**A. The rule is narrowed to format.** §3. Every changed path must be under `docs/` **and** end
in `md png jpg jpeg gif svg`. Arms (r), (s), (t), (u), (v); mutations M18 and M20.

**B. The invariant is an arm.** `tests/Feature/Quality/NothingReadsDocumentationTest.php`, §5.
It resolves class constants, which is the point — the grep that missed this failed because the
path was a constant. Bitten by M21 and M22, and shown red against the real defect restored.

### TICKET 4 — dropped rather than filed

The review filed `docs/tools/run.php` and a submodule gitlink at `docs/vendored` as a separate
ticket. Both fail the extension test: `.php` is not on the allowlist, and a gitlink is reported
by git as a bare directory name with no extension at all. Arm (s) plants both. The ticket is
dropped rather than filed, as the review directed.

### FIX 2 — two-dot is unpinned

`bin/is-docs-only-push` used `"$BASE..$HEAD_SHA"` correctly and nothing held it there:
`..` → `...` left all 17 arms green, and on a planted force-push the mutant drops a file that
left the remote tip. The shipped code was correct; the arm was missing. Arm (w); mutation M16,
confirmed red.

### FIX 3 — the verdict depends on the caller's cwd and on diff.relative

The diff ran in whatever directory `git push` was invoked from, with the developer's config.
With `diff.relative=true` and a cwd containing a nested `docs/`, a range holding `app/T.php`
reports docs-only; `public/assets/docs/` is tracked, so the shape is reachable. Fixed by
running the diff from the repository top level with relative paths forced off:
`git -C "$top" -c diff.relative=false`. Arm (x), **on the checker** — the review established
that no hook fixture can reach it, because the fixture always cds to the top level and carries
no local config, and the arm's comment says so. Mutation M17, confirmed red.

### FIX 4 — the printed evidence

A path containing a newline printed as two lines, one of which can read like a source file
under a SKIP banner. The verdict was right; only the display lied. Each path is now escaped
before printing — backslash first, then newline, carriage return and tab, and nothing else, so
ordinary non-ASCII documentation filenames stay readable. The acknowledgement in the script's
header is kept and rewritten to say what it now does. Arm (y); mutation M19, confirmed red.

### TICKET 5 — the pre-existing silent exits

`.githooks/pre-push:33-39` exits 0 with no gate and no output on garbage stdin (one field) and
on delete-only pushes. Confirmed here: `printf 'garbage\n'`, a delete-only ref line, and empty
stdin all produce `exit=0` and no output. Predates this branch, unchanged by it, and the docs
block never runs on those paths. Filed as its own ticket at
`docs/handoff/tickets/pre-push-exits-zero-silently-on-shapes-it-cannot-parse.md`, naming both
shapes and the line. Not fixed here.

### Report corrections

- §2's narrow-case claim is **retracted in those words**, with the mechanism that made it
  false: the grep began by matching the string `docs/` in the file, and the path is a class
  constant, so the file was invisible at step one —
  `grep -c "docs/" tests/Feature/Rbac/AuthzObservationsCommandTest.php` → `0`.
- §2's counts did not reproduce. Both re-run and published there, with the mechanism: the
  original pipelines were truncated by `head -40` and narrowed by a second filter, and the
  number reported was what was on screen rather than a `wc -l`. The file counts (23 and 9)
  reconcile with the review's exactly; the line counts do not, and the published 28/12 match
  neither.
- The comment at `bin/is-docs-only-push:26-27` carried the same false claim and is rewritten.
  It now states the `.json` reader by name and points at the invariant test.
- The count of non-prose files under `docs/` was wrong — eleven, actually nine — because the
  two em-dash filenames are quoted by `git ls-files` and a `\.md$` anchor misses the trailing
  quote. Same defect class as §6's `-z` finding, made in the message warning about it.
  Recorded in §2 as evidence for the rule.

### The review's own limits, in its words

It did not re-derive the 630 s figure by instruction, and it cannot prove no reader exists via
an idiom its sweep does not name.

### A defect this round introduced and the arms caught

Reverting the STOP-1 plant with `git reset --hard HEAD^` also discarded the uncommitted edits
to `bin/is-docs-only-push`, because that file is tracked. The checker silently reverted to the
committed version between the verification that showed it working and the test run. Six arms
went red and named it. Plants are now reverted with `git checkout HEAD -- <path>` on the
specific file.
