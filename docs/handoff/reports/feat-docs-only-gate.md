# feat/docs-only-gate — a push that changes only documentation does not run the suite

Branch: `feat/docs-only-gate`, cut from `origin/staging` at `f5ac5ab`.
Files: `bin/is-docs-only-push` (new), `.githooks/pre-push` (66 inserted lines, nothing
removed or edited), `tests/Feature/Quality/DocsOnlyPushCoverageTest.php` (new, 17 arms).

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

## 2. Does anything in the repository READ a file under `docs/`?

This is the question the rule stands on, so it was asked directly rather than assumed.

`grep -rn "docs/" tests/` returns 28 hits across 24 files. Every one is a prose citation
inside a comment or a docblock — a ticket path, a report path, a spec reference. None is a
read: `grep -rn "docs/" tests/ | grep -E "file_get_contents|file_exists|base_path|__DIR__|is_file|glob\(|realpath"` returns nothing.

`grep -rn "docs" bin/` returns 12 hits across 8 lint scripts and gate scripts; all 12 are
comments naming a ticket, ADR or runbook. No lint opens a file under `docs/`.

`bin/lint-changed.sh:42-50` classifies changed files for Pint, Prettier and ESLint by suffix
and prefix: Pint takes `*.php`, Prettier takes `resources/*.{ts,tsx,js,jsx,vue,css,json}`,
ESLint takes `*.{ts,tsx,js,jsx}`. A file under `docs/` matches none of the three, so a
docs-only push already ran all three lints over an empty list before this change.

The rule as stated is therefore not wrong on this repository's current contents. That is a
statement about what was measured today, not a property the rule enforces: nothing prevents a
future test from reading a fixture out of `docs/`, and nothing here would notice.

## 3. The rule

`bin/is-docs-only-push <base-sha> <head-sha>` exits **0** when every path in
`git diff --name-only --no-renames <base>..<head>` begins with `docs/`, **1** when any path
does not, and **2** when the range could not be computed.

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

### Two details that are load-bearing rather than incidental

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
the file being reverted as changed. Measured in a planted repository: remote tip carries
`app/T.php`, local ref carries only `docs/a.md`, and the checker lists both and exits 1.

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

`tests/Feature/Quality/DocsOnlyPushCoverageTest.php`, 17 arms, group `arch`. On
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
{"tool":"pest","result":"passed","tests":17,"passed":17,"assertions":55,"duration_ms":8652}
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
  elapsed time is a sample, not a constant, and the saving computed from it inherits that.
- **The rule's premise is a measurement of today's tree, not an enforced property.** §2
  establishes that no test and no lint currently reads a file under `docs/`. Nothing in this
  change would notice a future one that did, and no arm asserts the absence.
- **The mirror-image rename** (`thing.php` → `docs/thing.md`) is argued from `--no-renames`
  semantics and from arm (f)'s red, not separately planted.
- **Malformed stdin** — a ref line with fewer than four fields — is not planted. Reasoned:
  `remote_sha` expands empty, the checker receives one argument, prints usage and exits 2, and
  the hook runs the full gate. Not measured.
- **A squash or rebase push** is not planted. The force-push shape was measured (§3) and the
  two-dot semantics are the same for both, but the specific case was not run.
- The standing residuals of this floor are unchanged and apply here: no PHP version matrix, no
  clean-room OS, no remote enforcement, `--no-verify` still bypasses everything including this.

## 9. Files

| file | change |
| --- | --- |
| `bin/is-docs-only-push` | new, 165 lines. The decision, with its own exit code. |
| `.githooks/pre-push` | 66 lines inserted at line 72. Nothing removed, nothing edited; `git diff -U0` reports a single hunk, `@@ -71,0 +72,66 @@`. `.githooks/pre-push:41-70`, the release gate, is untouched. |
| `tests/Feature/Quality/DocsOnlyPushCoverageTest.php` | new, 17 arms, group `arch`. |
| `bin/quality` | **not changed.** |

## 10. The second push — the rule firing on a real push

This report is committed on its own, so the second push of this branch changes nothing but a
file under `docs/`. Recorded here after the fact:

Filled in by the follow-up commit, which is itself a third docs-only push.
