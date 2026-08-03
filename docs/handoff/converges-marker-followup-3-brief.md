# Brief — follow-up 3 on `fix/converges-marker` (`34a2f20`)

Verdict: **ship with fixes.** Four fixes, then merge. Nothing here is new scope.

**Finding 1 is my defect, not yours.** `docs/handoff/converges-marker-followup-2-brief.md` prescribed
`git show $head:<path>` verbatim, and you implemented what I wrote. The line is wrong for the reason
the reviewer gives and I am the author of it. Fix it as below; do not treat it as a lapse in your
work.

Verified against the repo at `34a2f20`, not against the report.

---

## 1. `$markersOnModified` — fix (`bin/ci-grants-convergence-lint.php:556-572`)

`:568` today:

```php
$count = preg_match_all('/@converges/', git('show', $head.':'.$parts[1]));
```

`git show $head:<path>` reads the file as it stands at head. It never compares against base, so the
notice fires on markers that were **already on the base** and merely happen to sit in a file this
branch touched for an unrelated reason. The notice's own words — *"a marker added to it declares a
convergence nothing performed"* — assert an author action the code cannot observe. Reviewer
reproduced it out-of-tree and it is live on this branch right now: `--diff-filter=M staging...HEAD --
database/migrations/` returns exactly the two migrations this commit edited for comment-only reasons.

Count `@converges` on the **added lines of the diff** instead:

```php
$patch = git('diff', '--unified=0', $base.'...'.$head, '--', $parts[1]);
$count = 0;
foreach (explode("\n", $patch) as $patchLine) {
    if ($patchLine === '' || $patchLine[0] !== '+' || str_starts_with($patchLine, '+++')) {
        continue;
    }
    $count += preg_match_all('/@converges/', $patchLine);
}
```

`--unified=0` so no context line can be miscounted, and the `+++` test so the header is not read as
content. Write the reason above the block, in the same register as the `--diff-filter=A` comment you
wrote at `:540-546` — that one is good and this one is its other half.

One edge, and disclose it in the comment rather than engineering around it: a marker merely **moved**
within an already-shipped migration counts as added and will report. That is acceptable — a marker on
a migration that has already run exempts nothing whichever line it sits on — but the message reads as
an accusation, so say in the comment that "added" means added-in-patch and includes a move. Do not
net adds against deletes: an author who deletes one marker and adds a different one has done exactly
the thing the notice exists to catch, and netting would silence it.

Unchanged: notice only, never `$addedMigrations`, never `$declared`, failing path only.

## 2. MARKER 9 needs a second arm

The reviewer is right that the present fixture cannot distinguish the two implementations. In
`GrantsConvergenceLintTest.php:978` the base has the migration **without** a marker and the branch
adds one — so the marker is both present-at-head and added-in-diff, and the arm is green either way.
It proves the rule half, not the notice half.

Add a sibling arm: the migration exists on the base **with** the marker already on it, and the branch
modifies that same file for an unrelated reason (a comment line is enough — that is precisely what
this commit did to two real migrations). Lint still exits 1 for the seeder grant. Assert:

- `$exemptions` is `''` and the output does not contain `declares @converges` — unchanged, the rule
  half still holds
- the output does **not** contain `sit on a migration that is not new in this diff`
- the output does **not** contain that migration's path

Prove it red first by reverting the `:568` fix, actually reverting, not reasoning about it. Under the
old line it names the file; under the new one it does not. If it passes before the fix, the arm is
not testing what it claims and I want to see it before it is edited.

## 3. Finding 2 — overrule the reviewer: this is a fix, not a ticket

`bin/ci-grants-convergence-lint.php:41` reads *"`grantsMap()['admin']` opens with five consecutive
spreads"*. It opens with **six** — `RbacSeeder.php:180-185`: `$guardianFull`, `$studentSubjectFull`,
`$enrollmentAdmin`, `$assessments`, `$activityAdmin`, `$resultChecker`. I verified the count; the
wrong number is mine, carried from the follow-up-2 brief into your comment.

The reviewer called it a ticket. I am overruling that. This sits inside the disclosure comment whose
entire stated justification is *"the next author reasons from it"* — a disclosure carrying a wrong
count is worse than no disclosure, because it is read as measured. One word, one file, this commit.

Change `five` to `six`. Nothing else in that paragraph.

## 4. Finding 3 — fix, with the range re-derived

The reviewer is right that the drift pre-existed, and right that **this commit worsened it**: +9 lines
above the guard in `2026_08_05_100000_converge_finance_access_grants.php`. Causing a regression and
then ticketing it is not the same as declining a pre-existing one. Fix it here.

`tests/Feature/Rbac/FinanceAccessGrantConvergenceTest.php` cites the migration in two places:

- `:146` — the fresh-install guard, cited `:75-91`
- `:170` — the deliberately-namespace-keyed rationale, cited `:75-84`

Both are stale. My reading puts the guard at `:93-113` and the namespace rationale at `:93-98`.
**Re-derive both yourself and do not trust my numbers** — `grep -n` the file at head, cite what you
see, and say in the report what you derived.

While you are in that test file: `:122` cites `RbacSeeder.php:377-391` for `internal_auditor`, whose
block actually opens at `:411`. That one is not this commit's doing, but you are already in the file
correcting citations and leaving the third stale would be perverse. Correct all three.

## 5. Finding 4 — the count is 7, and stop typing it

`git rev-list --count 8c354a5..HEAD` returns **7**. The report said five; the reviewer said six. This
is the second consecutive report to miscount its own commits, which is a mechanical problem with a
mechanical answer: derive it, never type it.

In this and every future report, produce the count as the literal output of

```
git rev-list --count $(git merge-base staging HEAD)..HEAD
```

and paste the command beside the number.

## 6. Two things you got right, recorded so they are not undone later

**Your gate answer is better than mine.** *"'Not added in this diff' is a property of the range, not
the tree, and the range moves: `bin/quality-promote` uses a wider base than the per-push run, so the
same tree would gate differently depending on which script invoked it."* That is the correct reason
and it is stronger than the one I would have given. It stands: this stays a notice. Put that sentence
in the code comment beside the notice if it is not already there — it is the answer to the next
person who asks why this is not a gate.

**Carrying `"the other two governed roles"` verbatim was right.** It is on the deferred list, and
fixing it by stealth inside a paragraph you were already touching would have hidden a real change
inside a cosmetic diff. Leave it. It gets its own line when the deferred list is worked.

Also noted: the reviewer's independent proof of MARKER 9's rule half — mutating `--diff-filter=A` to
`AM` out-of-tree and getting `exit=0, 1 exempted` — is the right technique and covers the half my
watched red did not. Keep doing that.

## 7. Do NOT on this branch

- Do not make the notice a gate.
- Do not touch `$addedMigrations`, `$declared`, or the `--diff-filter=A` rule.
- Do not work the fragment-resolution blind spot. It is the next brief and I am writing it.
- Do not fix `"the other two governed roles"`.
- Do not start `docs/handoff/credit-note-approver-move-brief.md` yet — it lands on exemption 3 and is
  sequenced after this merges.

## 8. Report, then merge

`bin/quality` 13/13 before reporting. Report as:

- the `$markersOnModified` block in full, after the fix
- the new MARKER 9 arm, red-before / green-after, proven by reverting `:568`
- the lint run on this branch showing the notice no longer names the two comment-only migrations
- the three corrected citations, with the `grep -n` output you derived each from
- the commit count as the output of the `git rev-list --count` command above
- anything here you think is wrong

Then merge `--ff-only` to `staging` and report the tip. If `--ff-only` is no longer clean, stop and
report — do not rebase without saying so first.

Next after the merge, in order: the fragment-resolution brief (mine to write), then
`docs/handoff/credit-note-approver-move-brief.md`.
