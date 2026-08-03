# Report — `@converges` follow-up 3: four fixes, then merge

**Branch:** `fix/converges-marker` · **This report covers `34a2f20..f871ba8`** (one commit).
**Brief:** `docs/handoff/converges-marker-followup-3-brief.md`

Commit count, derived not typed:

```
$ git rev-list --count $(git merge-base staging HEAD)..HEAD
8
```

(8 at the time of writing; 9 once this report commits. The command is now the only way this number
appears in a report of mine.)

`bin/quality` 13/13. `GrantsConvergenceLintTest` 22 passed / 115 assertions;
`FinanceAccessGrantConvergenceTest` 6 / 38.

Nothing from §7 is in this commit — `git show --stat f871ba8` touches the lint, its arm file,
`FinanceAccessGrantConvergenceTest.php`, and the two convergence migrations. `$addedMigrations`,
`$declared` and the `--diff-filter=A` rule are byte-identical to `34a2f20`.

---

## 1. `$markersOnModified`, in full, after the fix

```php
// COUNTED ON THE ADDED LINES OF THE PATCH, NOT ON THE FILE AT HEAD — the other half of the
// `--diff-filter=A` rule above. `git show $head:<path>` never compares against the base, so it counts
// markers that were ALREADY THERE and merely sit in a file this branch touched for an unrelated
// reason. The notice's own words assert an author action ("a marker added to it"), so counting the
// file at head makes it accuse an author who did nothing. Live shape, not hypothetical: a comment-only
// docblock edit to a shipped convergence migration is `M` and carries its old markers.
//
// `--unified=0` so no context line can be miscounted, and the `+++` test so the diff header is not
// read as content.
//
// "ADDED" MEANS ADDED-IN-PATCH, AND THAT INCLUDES A MOVE. A marker relocated within an
// already-shipped migration counts and will report. Accepted rather than engineered around: a marker
// on a migration that has already run exempts nothing whichever line it sits on, so the notice is
// still telling the truth about consequences even when it is wrong about intent. And adds are NOT
// netted against deletes — an author who deletes one marker and adds a different one has done exactly
// the thing this notice exists to catch, and netting would silence it.
$markersOnModified = [];
foreach (explode("\n", git('diff', '--name-status', '--diff-filter=M', $base.'...'.$head)) as $line) {
    $parts = preg_split('/\t/', trim($line));
    if (count($parts) < 2 || ! str_starts_with($parts[1], 'database/migrations/')) {
        continue;
    }

    $patch = git('diff', '--unified=0', $base.'...'.$head, '--', $parts[1]);
    $count = 0;
    foreach (explode("\n", $patch) as $patchLine) {
        if ($patchLine === '' || $patchLine[0] !== '+' || str_starts_with($patchLine, '+++')) {
            continue;
        }
        $count += preg_match_all('/@converges/', $patchLine);
    }

    if ($count > 0) {
        $markersOnModified[$parts[1]] = $count;
    }
}
```

Unchanged: notice only, never `$addedMigrations`, never `$declared`, failing path only.

Your §6 sentence is now in the code beside the notice, as the standing answer to "why is this not a
gate": the range property, `bin/quality-promote`'s wider base, the documented replay form, and the
fact that the red already exists and is already correct.

## The two ranges, measured

Both migrations are `M` over both ranges. Counted with the shipped block's own logic:

```
range 8c354a5...f871ba8   (staging..branch — this branch DID add those markers)
  2026_08_03_100000_converge_finance_change_grants.php   OLD(at head)=3  NEW(added in patch)=3
  2026_08_05_100000_converge_finance_access_grants.php   OLD(at head)=2  NEW(added in patch)=2

range e6600eb...f871ba8   (the comment-only edits — the false-accusation case)
  2026_08_03_100000_converge_finance_change_grants.php   OLD(at head)=3  NEW(added in patch)=0
  2026_08_05_100000_converge_finance_access_grants.php   OLD(at head)=2  NEW(added in patch)=0
```

**CORRECTED 2026-08-03 (follow-up 4, reviewer finding 3).** The paragraph that stood here said the
notice "still names both files" over `staging...HEAD`. It does not, and it cannot: the lint's notice
is on the failing path only, and over that range `database/seeders/RbacSeeder.php` is not in the diff
at all, so the run short-circuits at the seeder-unchanged early return —
`grants-convergence-lint: OK — database/seeders/RbacSeeder.php is unchanged in this diff`, `exit 0` —
long before `$markersOnModified` is printed. The brief's "live on this branch right now" premise was
unreachable for the same reason, and so was my correction to it.

**The table above is therefore a measurement of the extracted block, not of a lint run**, and it is
labelled as such. The evidence for the fix is the reviewer's out-of-tree reproduction plus MARKER 9b,
which holds the marker byte-identical between base and head and so discriminates the two
implementations end-to-end through the real script. The counts still stand: over the comment-only
range the count goes `3→0` and `2→0`, which is the false accusation removed.

**One extra change to make that 0 a real 0.** The first probe returned `1` and `1`, not `0` and `0`.
The reworded lead-ins I wrote last commit contained the literal marker word — "in
``bin/ci-grants-convergence-lint.php``'s `@converges` syntax" — which is the accepted prose false
positive, self-inflicted. Both now read "in the marker syntax `bin/ci-grants-convergence-lint.php`
reads". The false positive stays unsuppressed in the lint, per your standing decision; I removed the
one instance I had authored rather than teach the parser about it.

---

## 2. MARKER 9b — red before, green after

Fixture: migration on the base **with** the marker, branch reobjects only an unrelated comment line
in it, plus the seeder grant. Asserts the rule half still holds (`$exemptions` `''`, no
`declares @converges`) **and** that the notice stays silent and the path is unnamed.

`:568` reverted to `preg_match_all('/@converges/', git('show', $head.':'.$parts[1]))`:

```
failed  21 passed / 1 failed
  MARKER_9b_—_a_marker_ALREADY_on_the_base_is_not_reported_whe ::
    Expecting '…' not to contain 'sit on a migration that is not new in this diff'.
```

**One arm, failing on exactly the string that distinguishes the two implementations.** Restored:
22 passed, 115 assertions.

MARKER 9 is unchanged and still carries the rule half.

---

## 3. Finding 2 — `five` → `six`

Derived rather than accepted:

```
$ sed -n '178,190p' database/seeders/RbacSeeder.php
        return [
            'admin' => [
                ...$guardianFull,          180
                ...$studentSubjectFull,    181
                ...$enrollmentAdmin,       182
                ...$assessments,           183
                ...$activityAdmin,         184
                ...$resultChecker,         185
                PermissionEnum::MANAGE_TEACHER_ASSIGNMENTS->value,   186
```

Six. Changed; nothing else in that paragraph touched. I agree with the overrule — a disclosure whose
stated warrant is "the next author reasons from it" is the one comment where a carried number is not
cosmetic.

---

## 4. Finding 3 — four citations re-derived

You told me not to trust your numbers. I did not, and one of them differs.

| Site | Was | Now | Derivation |
| --- | --- | --- | --- |
| `FinanceAccessGrantConvergenceTest.php:146` | `:75-91` | **`:93-113`** | `93:` `// Fresh-install guard, keyed on the PERMISSION substrate` … `99: $financeSubstrate = Permission::query()` … `108: if (! $financeSubstrate) {` … `112: return;` `113: }` |
| `:170` | `:75-84` | **`:93-98`** | the rationale paragraph alone: `93`–`98`, ending `// substrate, and the pre-flight below must abort on it instead of returning a quiet green.` |
| `:10` | `:147` | **`:169`** | `169:  throw new RuntimeException("converge-finance-access-grants ABORTED: global role [{$roleName}] is missing.");` |
| `:122` | `RbacSeeder.php:377-391` | **`:377-394`** | see below |

Your reading of `:93-113` and `:93-98` matches mine exactly.

**Where I differ from you, and it changes the answer.** You wrote that the `internal_auditor` block
"actually opens at `:411`". `:411` is the `'internal_auditor' => [` **array key**. But the citation's
job is to point at what *records the grant as DECIDED and UNIMPLEMENTED*, and that is the comment,
which still opens at **`:377`**:

```
$ sed -n '377,378p;387,394p' database/seeders/RbacSeeder.php
377:  // Internal Auditor (IA) — new 2026-08-01, activity-log-only. Still NO finance.access, but the
378:  // ORIGINAL REASON NO LONGER HOLDS. It was: finance.access is not a read-only gate — both payment
387:  // The grant is therefore UNIMPLEMENTED, not undecided — do not re-open it as a question. v10 §7.2
...
394:  // docs/rbac/finance-seat-realignment.md carries the same record.
```

So `:377` was never wrong; only the **end** had drifted, from `:391` to `:394`. Citing `:411` would
point at the array key and past the paragraph the comment is about. I wrote `:377-394`. If you meant
the citation to name the key rather than the rationale, say so and I will change it.

**Two deviations to flag.**

1. **I corrected `:10` as well**, which your §4 did not list (it names `:146`, `:170`, `:122`). It is
   the same +9 drift, in the same file, in the same commit — and your own "leaving the third stale
   would be perverse" argument applies identically. If you wanted it left, revert one line.
2. **`2026_08_05_100000_converge_finance_access_grants.php:36` still cites `RbacSeeder.php:377-391`**
   for the same fact. Same staleness, different file, and your §4 scoped this to the test file — so I
   left it. Flagging rather than silently widening. It is one line whenever you want it.

---

## 5. Finding 4 — the count

Done, at the top, as the literal output of the command with the command beside it. The mechanical
answer is right: I miscounted twice by typing a number I had in my head from a `git log --oneline`
I had run several tool calls earlier.

---

## Anything here I think is wrong

**Nothing in the brief.** One observation and one disclosure:

- **The move edge is wider than "a move".** `--unified=0` counts a marker line as added whenever git
  emits it as `+`, and git will do that for a *reformat* too — re-wrapping a docblock, or Pint
  normalising whitespace around the line. So the notice can fire on an author who neither added nor
  moved a marker, only reflowed the comment around it. Same acceptance as the move: the marker still
  exempts nothing, so the notice is right about consequences and wrong about intent. It is in the
  comment as "added-in-patch", which covers this, but I would not want the next reader to think the
  edge is only literal relocation.
- **`--diff-filter=M` still misses `R`.** A shipped migration renamed *and* given a marker is in
  neither list — no exemption (safe) and no notice (the silence the fix removed, narrower). The
  reviewer flagged this last round; it remains open and I did not widen the filter, because `R`
  detection depends on git's rename heuristic and that is a similarity threshold making a judgement
  about intent, which is the thing this whole arc has been removing.

## What is still open, unchanged

- The fragment-resolution blind spot — disclosed, not closed. Yours to brief.
- `bin/quality` step 7's step-name still carries the same over-claim as the sentence I qualified.
  One line, best taken with the fix.
- `"the other two governed roles"` — carried verbatim again, still deferred.
- MARKER 9 covers `M`-with-added-marker; MARKER 9b covers `M`-with-pre-existing-marker; a migration
  carrying a marker and *untouched* at head is in neither list and is unarmed, deliberately.
- Nothing driven against the dev database or a real app flow.
