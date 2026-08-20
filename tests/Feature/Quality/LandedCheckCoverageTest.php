<?php

/*
 * Coverage for bin/landed itself.
 *
 * WHY THIS IS NOT OPTIONAL. Six lints in this repository were reported green while blocking
 * nothing, and SqlClockLintCoverageTest's header records the shape: a gate whose BEHAVIOUR was
 * forbidden but whose matcher had drifted printed OK over a live violation. bin/landed is more
 * exposed than a lint, not less — it is a handful of ref comparisons, every one of which reduces
 * to a string equality that a one-line edit can pin true forever, and there is no tree for a
 * reviewer to notice the violation in. A green from a neutered bin/landed reads exactly like a
 * green from a working one.
 *
 * So these PLANT the failures. Each arm builds a real repository in a temp directory, wires a real
 * bare repository as `origin` by path, arranges the history that produced the defect, runs the real
 * script, and asserts BOTH the exit code AND the message. The exit code alone does not
 * discriminate: this script has four distinct failure modes and three of them exit 1, so an arm
 * that checked only the code would pass while the script reported the wrong one.
 *
 * NO NETWORK. `git fetch origin` works against a local path remote, so every arm — including the
 * fetch-failure arm — runs offline. Nothing here touches the repository the suite lives in: the
 * fixtures are built under mktemp -d, outside the tree, so a leaked fixture cannot become a
 * committable untracked file the way SqlClockLintCoverageTest's plant-into-the-tree approach can.
 *
 * THE FIXTURES ARE CONFIG-ISOLATED. GIT_CONFIG_GLOBAL and GIT_CONFIG_SYSTEM are pointed at
 * /dev/null and HOME is redirected into the temp directory, so the developer's own git config —
 * a signing key, a commit template, a core.hooksPath — cannot reach in and change what these
 * assert. An arm that passes on one machine and fails on another is not a gate.
 *
 * BITE-PROVED. Every arm below was verified to go RED with the corresponding check removed from
 * bin/landed; the mutation each one caught is recorded in docs/handoff/reports/feat-landed-check.md.
 * Arm (f) is the one that most easily passes vacuously — it was specifically verified to fail when
 * the failed-fetch `exit 2` is changed to `exit 0`.
 */

uses()->group('arch');

/**
 * Build a planted repository, run the real bin/landed inside it, and return
 * [exitCode, ansi-stripped output, vars] where `vars` are the `___VAR:key=value` lines the
 * fixture printed (used to assert on the actual shas rather than on a shape).
 *
 * $setup is bash, run with `set -e`, in a work repo on branch `staging` whose `origin` is a bare
 * repo at $ORIGIN, with one pushed commit already on staging.
 */
function landedFixture(string $setup, string $args = 'feat/x', string $runIn = 'work'): array
{
    $root = dirname(__DIR__, 3);
    $tmp = rtrim(shell_exec('mktemp -d') ?? '', "\n");

    if ($tmp === '' || ! is_dir($tmp)) {
        throw new RuntimeException('could not create a temp directory for the landed fixture');
    }

    $script = sprintf(<<<'BASH'
set -euo pipefail

TMP=%s
ROOT=%s
ORIGIN="$TMP/origin.git"

# Isolate from the developer's own git configuration — see this file's header.
export GIT_CONFIG_GLOBAL=/dev/null
export GIT_CONFIG_SYSTEM=/dev/null
export GIT_TERMINAL_PROMPT=0
export HOME="$TMP/home"
mkdir -p "$HOME"

# GIT_CONFIG_COUNT and its GIT_CONFIG_KEY_n / GIT_CONFIG_VALUE_n pairs OUTRANK EVERY CONFIG
# FILE, including the two redirected to /dev/null above, so they were the one channel the
# isolation did not close. Closing it is precautionary, not a repair: poisoning these was
# measured to kill the fixture loudly — the fixture's own `git` calls fail and the arm errors —
# rather than to produce a false pass. Unsetting COUNT alone disables the channel (git reads the
# numbered pairs only when COUNT is set); the pairs are cleared too so nothing is left addressable.
unset GIT_CONFIG_COUNT
for n in $(seq 0 31); do
    unset "GIT_CONFIG_KEY_$n" "GIT_CONFIG_VALUE_$n"
done

git init -q --bare "$ORIGIN"
mkdir -p "$TMP/work"
cd "$TMP/work"
git init -q
# `git init -b` is 2.28+; a symbolic-ref before the first commit names the branch on any version.
git symbolic-ref HEAD refs/heads/staging
git config user.email fixture@example.test
git config user.name "Landed Fixture"
git config commit.gpgsign false
git remote add origin "$ORIGIN"

echo base > f.txt
git add f.txt
git commit -qm "base commit"
git push -q origin staging

%s

set +e
cd "$TMP/%s"
"$ROOT/bin/landed" %s 2>&1
echo "___EXIT:$?"
BASH,
        escapeshellarg($tmp),
        escapeshellarg($root),
        $setup,
        $runIn,
        $args,
    );

    try {
        $scriptPath = $tmp.'/fixture.sh';
        file_put_contents($scriptPath, $script);

        $output = [];
        $ignored = 0;
        exec('bash '.escapeshellarg($scriptPath).' 2>&1', $output, $ignored);
        $raw = implode("\n", $output);

        // Strip SGR sequences: the assertions are about words, and the script colours whole lines.
        $clean = preg_replace('/\033\[[0-9;]*m/', '', $raw);

        $exit = null;
        $vars = [];
        foreach (explode("\n", $clean) as $line) {
            if (str_starts_with($line, '___EXIT:')) {
                $exit = (int) substr($line, 8);
            }
            if (str_starts_with($line, '___VAR:')) {
                [$key, $value] = explode('=', substr($line, 7), 2);
                $vars[$key] = $value;
            }
        }

        if ($exit === null) {
            throw new RuntimeException("the fixture never reached bin/landed:\n".$clean);
        }

        return [$exit, $clean, $vars];
    } finally {
        exec('rm -rf '.escapeshellarg($tmp));
    }
}

it('(a) passes when the branch merged at its head and everything is pushed', function () {
    // The healthy shape, and the control for every other arm: if this one cannot go green the
    // failures below prove nothing, because a script that always fails would pass all of them.
    [$exit, $output] = landedFixture(<<<'BASH'
git checkout -q -b feat/x
echo one >> f.txt
git commit -qam "the reviewed commit"
git push -q origin feat/x
git checkout -q staging
git merge -q --no-ff -m "Merge pull request #1 from o/feat/x" feat/x
git push -q origin staging
BASH);

    expect($exit)->toBe(0)
        ->and($output)->toContain('✓ landed')
        ->and($output)->toContain('contains every commit on origin/feat/x')
        ->and($output)->toContain('took the head origin/feat/x points at')
        // The PR number comes from the merge commit's own message, never from `gh`.
        ->and($output)->toContain('Merge pull request #1')
        ->and($output)->toContain('subject read from the merge commit')
        // …and the green says what it does not mean, in the output, not only in the docblock.
        ->and($output)->toContain('says nothing about whether the merge was correct');
});

it('(b) reports instance A through check 1, and offers the merge-message hint without claiming it', function () {
    // INSTANCE A, reproduced in miniature. PR #265's merge commit 9849689 has second parent
    // 37500c8 — the conflict-resolution merge — while two further commits (c7ec9a6, 6e770ae)
    // were pushed to the branch after the merge and have never reached origin/staging.
    //
    // CHECK 4 DOES NOT DETECT THIS AND MUST NOT CLAIM TO. It once did, and arm (i) is the
    // measurement that killed that claim: this topology — a merge on the target whose second
    // parent is an ancestor of the branch and is not the branch head — is indistinguishable
    // from a branch that never merged and was merely cut from a commit that later became a
    // merge parent. Check 1 is the detector here, and it is sufficient: it names both commits.
    //
    // The useful SENTENCE survives as a hint, read from the merge commit's message rather than
    // from the graph, and contributing nothing to the failure count.
    [$exit, $output, $vars] = landedFixture(<<<'BASH'
git checkout -q -b feat/x
echo one >> f.txt
git commit -qam "the reviewed commit"
git push -q origin feat/x
git checkout -q staging
git merge -q --no-ff -m "Merge pull request #2 from o/feat/x" feat/x
git push -q origin staging
echo "___VAR:reviewed=$(git rev-parse --short=8 feat/x)"

# …and now work continues on a branch whose PR has already closed.
git checkout -q feat/x
echo two >> f.txt
git commit -qam "the fix that never landed"
echo three >> f.txt
git commit -qam "and its documentation"
git push -q origin feat/x
echo "___VAR:head=$(git rev-parse --short=8 feat/x)"
git checkout -q staging
BASH);

    expect($exit)->toBe(1);

    // CHECK 1 IS THE DETECTOR, and the ONLY failure. The count is the assertion: if check 4 is
    // ever re-promoted to a verdict this goes to 2 and this arm goes red.
    expect($output)->toContain('origin/feat/x has 2 commit(s) that origin/staging does not have')
        ->and($output)->toContain('the fix that never landed')
        ->and($output)->toContain('and its documentation')
        ->and($output)->toContain('✗ NOT landed')
        ->and($output)->toContain('(1 check(s) failed)');

    // Check 4 declines, out loud. Silence would leave a reader wondering whether it ran.
    expect($output)->toContain('origin/feat/x is not contained in origin/staging')
        ->and($output)->toContain('no merge-head claim is made')
        ->and($output)->not->toContain('PR merged')
        ->and($output)->not->toContain('commit(s) between them');

    // THE HINT. The sentence the ticket wanted — which sha the merge took, and how far the
    // branch has run past it — read from the merge MESSAGE, labelled as such, and costing
    // nothing: the exit code and the count above are asserted with the hint present, so a
    // hint that started contributing to the failure count would turn this arm red.
    expect($output)->toContain('READ FROM A MERGE MESSAGE')
        ->and($output)->toContain('took '.$vars['reviewed'])
        ->and($output)->toContain('origin/feat/x is now '.$vars['head'])
        ->and($output)->toContain('2 commit(s) beyond that')
        ->and($output)->toContain('A hint, not a verdict');
});

it('(c) fails when the local target is ahead of origin — a merge nobody else can see', function () {
    // INSTANCE B. The remedy for instance A was merged into the local `staging` at 5a3f212 and
    // never pushed; origin/staging kept config/rbac.php at ten fail_closed_models entries while
    // the working tree read twelve. `git status` said "ahead of origin/staging by 1 commit" for
    // the whole period and it was read by nobody, which is why this is an exit code and not a line
    // of output.
    //
    // The branch itself is fully merged AND pushed here, so checks 1 and 4 are green and this arm
    // isolates check 2 — a mutation that pins check 2 true has nothing else to hide behind.
    [$exit, $output] = landedFixture(<<<'BASH'
git checkout -q -b feat/x
echo one >> f.txt
git commit -qam "the reviewed commit"
git push -q origin feat/x
git checkout -q staging
git merge -q --no-ff -m "Merge pull request #3 from o/feat/x" feat/x
git push -q origin staging

echo unpushed > local.txt
git add local.txt
git commit -qm "a merge nobody else can see"
BASH);

    expect($exit)->toBe(1)
        ->and($output)->toContain('your local staging has 1 commit(s) that are not on origin')
        ->and($output)->toContain('a merge nobody else can see')
        ->and($output)->toContain('✗ NOT landed')
        // …and it is check 2 ALONE that fired — asserted by the count, not only by the two greens
        // below, so a check that starts failing silently for a third reason is caught here.
        ->and($output)->toContain('(1 check(s) failed)')
        ->and($output)->toContain('✓ origin/staging contains every commit on origin/feat/x')
        ->and($output)->toContain('took the head origin/feat/x points at');
});

it('(d) passes, with an informational line, when the local target is merely behind origin', function () {
    // Being behind is the normal state of any checkout between pulls; being AHEAD is the defect.
    // If the two were reported the same way the reader would learn to ignore both, so this arm
    // pins the asymmetry: exit 0, and the line still printed.
    [$exit, $output] = landedFixture(<<<'BASH'
git checkout -q -b feat/x
echo one >> f.txt
git commit -qam "the reviewed commit"
git push -q origin feat/x
git checkout -q staging
git merge -q --no-ff -m "Merge pull request #4 from o/feat/x" feat/x
git push -q origin staging

# Rewind the local branch only — origin keeps the merge.
git reset -q --hard HEAD~1
BASH);

    expect($exit)->toBe(0)
        ->and($output)->toContain('behind origin')
        ->and($output)->toContain('✓ landed')
        // Behind must not be reported as ahead.
        ->and($output)->toContain('✓ your local staging is not ahead of origin');
});

it('(e) fails distinctly when the branch was never merged at all, past an unrelated merge', function () {
    // A DIFFERENT ANSWER from arm (b), and the script must not collapse them: there is no
    // "reviewed head" to disagree with when nothing merged the branch. Both exit 1, so only the
    // message discriminates.
    //
    // THE UNRELATED MERGE IS THE POINT OF THE FIXTURE, not scenery. `feat/x` is cut from staging
    // AFTER `other/y` merged, so `other/y`'s head is in `feat/x`'s ancestry — and a check 4 that
    // looked for "the newest merge whose second parent is an ancestor of the branch" would find
    // `other/y`'s merge and report it as `feat/x`'s, printing a stale-head mismatch for a branch
    // that simply never merged.
    //
    // NECESSARY AND INSUFFICIENT, and worth stating exactly. This arm killed the loose
    // ancestor-based match; it did NOT kill the merge-base match that replaced it, and could
    // not, because HERE THE UNRELATED MERGE COMMIT *IS* THE MERGE-BASE — nothing's second
    // parent equals it, so the merge-base form correctly found nothing. In arm (i) the
    // unrelated merge's SECOND PARENT is the merge-base, which is the shape this fixture cannot
    // reach and which the merge-base form got wrong on the real repository. One fixture, one
    // shape; that is why check 4 now gates on containment instead of on any matcher at all.
    [$exit, $output] = landedFixture(<<<'BASH'
git checkout -q -b other/y
echo unrelated > g.txt
git add g.txt
git commit -qm "an unrelated branch"
git checkout -q staging
git merge -q --no-ff -m "Merge pull request #6 from o/other/y" other/y
git push -q origin staging

git checkout -q -b feat/x
echo one >> f.txt
git commit -qam "never merged"
git push -q origin feat/x
git checkout -q staging
BASH);

    expect($exit)->toBe(1)
        ->and($output)->toContain('origin/feat/x has 1 commit(s) that origin/staging does not have')
        ->and($output)->toContain('never merged')
        ->and($output)->toContain('origin/feat/x is not contained in origin/staging')
        ->and($output)->toContain('no merge-head claim is made')
        // No verdict wording of any kind — that is the outcome this arm exists to refuse.
        ->and($output)->not->toContain('branch head is')
        ->and($output)->not->toContain('PR merged')
        ->and($output)->toContain('✗ NOT landed')
        // One failure: check 1. Check 4 contributes nothing.
        ->and($output)->toContain('(1 check(s) failed)')
        // And no hint either: `other/y`'s merge subject names `other/y`, not `feat/x`, so the
        // message scan correctly declines to offer a lead about a different branch.
        ->and($output)->not->toContain('READ FROM A MERGE MESSAGE');
});

it('(f) exits 2, not 0 and not 1, when origin cannot be reached', function () {
    // THE ARM MOST LIKELY TO PASS VACUOUSLY, and the reason it is written last. The fixture builds
    // a FULLY HEALTHY, FULLY PUSHED state first and only then points origin at a path that does
    // not exist — so the remote-tracking refs are present and correct, and a script that ignored
    // the fetch failure would sail through every check below it and print a green. That green
    // would mean "I could not look", which is the defect class this whole script exists to close,
    // wearing the script's own face.
    //
    // Exit 2 rather than 1 is the assertion that matters: "wrong" and "unknown" are different
    // answers, and a caller that treats a broken network as a failed check learns to retry until
    // green, which is indistinguishable from fixing.
    [$exit, $output] = landedFixture(<<<'BASH'
git checkout -q -b feat/x
echo one >> f.txt
git commit -qam "the reviewed commit"
git push -q origin feat/x
git checkout -q staging
git merge -q --no-ff -m "Merge pull request #5 from o/feat/x" feat/x
git push -q origin staging

git remote set-url origin "$TMP/this-path-does-not-exist.git"
BASH);

    expect($exit)->toBe(2)
        ->and($output)->toContain('could not reach origin')
        ->and($output)->toContain('meaningless offline')
        // Neither verdict line may be printed: this is not a pass and not a failed check.
        ->and($output)->not->toContain('✓ landed')
        ->and($output)->not->toContain('✗ NOT landed');
});

it('(g) passes, with its own wording, when the branch landed by fast-forward and left no merge commit', function () {
    // A branch can be fully landed with no merge commit at all — a fast-forward, or (the shape
    // this repository actually produces) a branch that merged the target back into itself after
    // its own PR closed. Check 4 has nothing to compare, and the honest answer is neither of its
    // other two: not a stale head, and not "never merged", because origin/staging demonstrably
    // contains the branch.
    //
    // THE VERDICT WORDING IS THE ASSERTION. The ordinary green says "the merge took the reviewed
    // head"; there is no merge here, so saying it would be claiming more than the checks
    // established — the exact reporting habit the ticket behind this script is about. Delete the
    // contained-anyway branch and this arm exits 1 with "no merge of feat/x found".
    [$exit, $output] = landedFixture(<<<'BASH'
git checkout -q -b feat/x
echo one >> f.txt
git commit -qam "the reviewed commit"
git push -q origin feat/x
git checkout -q staging
git merge -q --ff-only feat/x
git push -q origin staging
BASH);

    expect($exit)->toBe(0)
        ->and($output)->toContain('no merge commit of feat/x found on origin/staging')
        ->and($output)->toContain('is contained in origin/staging')
        ->and($output)->toContain('✓ landed')
        ->and($output)->toContain('with no merge commit to check')
        // The claim the ordinary green makes must NOT be made here.
        ->and($output)->not->toContain('the merge took the reviewed head')
        ->and($output)->not->toContain('✗ NOT landed');
});

it('(h) exits 2, not 1, when the branch does not exist on origin', function () {
    // The third route to "could not determine", and it needs its own arm because the ticket's own
    // "Not proposed here" list raises deleting branches at merge — under which the ordinary case
    // for this script becomes a name that origin no longer has. Reporting that as a FAILED CHECK
    // would train the reader that a red from bin/landed is routine, which is how a real red gets
    // waved through. It is unknown, and 2 says so.
    [$exit, $output] = landedFixture(<<<'BASH'
git checkout -q -b feat/x
echo one >> f.txt
git commit -qam "the reviewed commit"
git push -q origin feat/x
git checkout -q staging
git merge -q --no-ff -m "Merge pull request #7 from o/feat/x" feat/x
git push -q origin staging
git push -q origin --delete feat/x
BASH, 'feat/x');

    // THE MESSAGE MOVED, THE ANSWER DID NOT. Once the fetch names its refspec, a branch absent
    // from origin fails at the fetch rather than at the rev-parse guard below it — so the
    // wording is the fetch's specific "no such branch on origin" rather than the guard's. The
    // exit code is 2 either way, and that is the contract; the assertion follows the message to
    // the place it is now printed rather than being loosened to match both.
    expect($exit)->toBe(2)
        ->and($output)->toContain('no such branch on origin')
        ->and($output)->toContain('cannot determine whether it landed')
        // The specific unknown, not the generic one: origin was perfectly reachable here.
        ->and($output)->not->toContain('could not reach origin')
        ->and($output)->not->toContain('✗ NOT landed')
        ->and($output)->not->toContain('✓ landed');
});

it('(i) makes no merge-head claim for a branch cut from a commit that later became a merge parent', function () {
    // THE RUN-3 SHAPE, and the reason check 4 was demoted from detector to explainer. Found by
    // pointing the tool at this repository on 2026-08-20; fifteen mutations against fixtures had
    // not found it. Raw output in docs/handoff/reports/feat-landed-check.md §7.
    //
    // The topology: `feat/x` is cut from commit X on the target. The target then diverges and is
    // reconciled with a `git pull` merge — whose SECOND parent is X, because that is what pull
    // puts there. `feat/x` never merges. Now merge-base(target, branch) == X == that merge's
    // second parent, so any check that identifies "the merge of this branch" by second-parent
    // equality with the merge-base matches a merge that has nothing to do with the branch, and
    // reports the branch's own fork point as "the head the PR merged".
    //
    // THIS IS THE SAME TOPOLOGY AS INSTANCE A. In both, a merge on the target has as its second
    // parent a commit that is an ancestor of the branch and not the branch head. Git records no
    // branch identity in the DAG, so no ancestry test separates "merged, then advanced" from
    // "never merged, and the fork point happens to be a merge parent". Check 4 therefore claims
    // nothing unless the branch is CONTAINED — which is a fact about the graph, not a guess about
    // intent.
    //
    // An unmerged branch is check 1's business, and check 1 already names the outstanding commits.
    // So: exit 1, exactly ONE failed check, and no mismatch wording anywhere in the output.
    [$exit, $output] = landedFixture(<<<'BASH'
# X — pushed to origin/staging from a side branch, so the local staging never contains it.
git checkout -q -b tmp-x
echo x >> f.txt
git commit -qam "commit X, the fork point"
git push -q origin tmp-x:staging
git checkout -q staging
git branch -q -D tmp-x

# feat/x is cut from X, and never merges.
git checkout -q -b feat/x origin/staging
echo b >> f.txt
git commit -qam "branch work that never merged"
git push -q origin feat/x

# Local staging diverges onto its own line…
git checkout -q staging
echo l > local.txt
git add local.txt
git commit -qm "a local commit on staging"

# …and is reconciled the way `git pull` reconciles: HEAD first, the fetched head SECOND.
git merge -q --no-ff -m "Merge branch 'staging' of example.test:o/portal into staging" origin/staging
git push -q origin staging
BASH);

    expect($exit)->toBe(1)
        // Check 1 is the signal, and it is the ONLY failure. The count is the assertion that a
        // re-promoted check 4 cannot slip back in unnoticed.
        ->and($output)->toContain('origin/feat/x has 1 commit(s) that origin/staging does not have')
        ->and($output)->toContain('branch work that never merged')
        ->and($output)->toContain('(1 check(s) failed)')
        // Check 4 says so explicitly rather than staying silent, so a reader is not left to wonder
        // whether it ran.
        ->and($output)->toContain('is not contained in origin/staging')
        ->and($output)->toContain('no merge-head claim is made')
        // AND NOT ONE WORD OF A VERDICT. These three are the shape of the false positive.
        ->and($output)->not->toContain('PR merged')
        ->and($output)->not->toContain('branch head is')
        ->and($output)->not->toContain('commit(s) between them')
        // AND NO HINT EITHER. The merge here says `Merge branch 'staging' …` — it names the
        // TARGET, not this branch. Loosen the hint's `Merge branch '<branch>'` pattern to
        // `Merge branch '`* and it matches, and the hint then prints this branch's own fork
        // point as "the sha the merge took": run 3's false positive, verbatim, arriving through
        // the message channel instead of the topological one. Measured — that mutant survived
        // 10/10 before this line existed.
        ->and($output)->not->toContain('READ FROM A MERGE MESSAGE');
});

it('(j) refuses a STALE remote-tracking ref instead of reporting it as landed', function () {
    // THE STOP. A `--single-branch` or `--depth` clone leaves remote.origin.fetch covering only
    // one branch, and `git fetch --prune origin` then honours that refspec: it returns success,
    // updates nothing for the other branch, and refs/remotes/origin/<branch> keeps whatever it
    // last held. The script printed its ✓ fetch line and compared a ref that had not moved.
    //
    // Measured against the pre-fix script in exactly this fixture: `✓ landed`, EXIT 0, while
    // origin's feat/x carried two commits staging did not have. A FALSE GREEN IN A VERIFICATION
    // TOOL IS WORSE THAN NO TOOL — it converts "I did not check" into "I checked".
    //
    // The fix makes freshness a property of the fetch this script runs rather than of the
    // clone's configuration: the two refs about to be compared are named in the refspec.
    [$exit, $output] = landedFixture(<<<'BASH'
git checkout -q -b feat/x
echo one >> f.txt
git commit -qam "the reviewed commit"
git push -q origin feat/x
git checkout -q staging
git merge -q --no-ff -m "Merge pull request #8 from o/feat/x" feat/x
git push -q origin staging

# What --single-branch leaves behind: a refspec that maps the target and nothing else.
git config remote.origin.fetch "+refs/heads/staging:refs/remotes/origin/staging"

# The branch advances on origin. Pushing an explicit refspec does NOT update the
# remote-tracking ref, because nothing maps it any more — verified in this fixture.
git checkout -q feat/x
echo two >> f.txt
git commit -qam "outstanding after the merge"
echo three >> f.txt
git commit -qam "and one more"
git push -q origin feat/x:refs/heads/feat/x
git checkout -q staging
BASH);

    expect($exit)->toBe(1)
        ->and($output)->toContain('origin/feat/x has 2 commit(s) that origin/staging does not have')
        ->and($output)->toContain('outstanding after the merge')
        ->and($output)->toContain('and one more')
        // The green this arm exists to forbid.
        ->and($output)->not->toContain('✓ landed');
});

it('(k) exits 2 in a shallow clone rather than answering from a truncated graph', function () {
    // A shallow clone gives a FALSE RED and a wrong number: the merge sits below the graft, so
    // `git merge-base --is-ancestor` cannot see containment and a landed branch reports NOT
    // landed, with an outstanding count computed over a truncated graph. Both are answers the
    // script cannot give, so it must say it cannot give them — exit 2, the code that means
    // unknown rather than wrong.
    //
    // `file://` matters: a plain path is a local clone and git ignores --depth for it.
    [$exit, $output] = landedFixture(<<<'BASH'
git checkout -q -b feat/x
echo one >> f.txt
git commit -qam "the reviewed commit"
git push -q origin feat/x
git checkout -q staging
git merge -q --no-ff -m "Merge pull request #9 from o/feat/x" feat/x
git push -q origin staging
echo extra > e.txt
git add e.txt
git commit -qm "the target advances past the merge"
git push -q origin staging

git clone -q --depth 1 --no-single-branch "file://$ORIGIN" "$TMP/shallow"
BASH, 'feat/x staging', 'shallow');

    expect($exit)->toBe(2)
        ->and($output)->toContain('shallow')
        ->and($output)->not->toContain('✓ landed')
        ->and($output)->not->toContain('✗ NOT landed');
});

it('(l) still reports contained-no-merge when the target does carry merges', function () {
    // Arm (g) plants a fast-forward into a target with NO merge commits at all, so a mutant that
    // stops comparing the second parent — `$3 == h` widened to `$3 != ""` — finds nothing to
    // return and arm (g) stays green. Measured: that mutant survived 10/10.
    //
    // This is arm (g) plus one unrelated merge, which is all it takes: the mutant now returns
    // that merge and prints "took the head origin/feat/x points at" naming a merge of some other
    // branch. The comparison, not merely the lookup, is what this arm pins.
    [$exit, $output] = landedFixture(<<<'BASH'
git checkout -q -b feat/x
echo one >> f.txt
git commit -qam "the reviewed commit"
git push -q origin feat/x
git checkout -q staging
git merge -q --ff-only feat/x
git push -q origin staging

git checkout -q -b other/z
echo z > z.txt
git add z.txt
git commit -qm "an unrelated branch"
git checkout -q staging
git merge -q --no-ff -m "Merge pull request #10 from o/other/z" other/z
git push -q origin staging
BASH);

    expect($exit)->toBe(0)
        ->and($output)->toContain('no merge commit of feat/x found on origin/staging')
        ->and($output)->toContain('is contained in origin/staging')
        ->and($output)->toContain('with no merge commit to check')
        // The unrelated merge must not be adopted as this branch's.
        ->and($output)->not->toContain('took the head origin/feat/x points at')
        ->and($output)->not->toContain('Merge pull request #10');
});

it('(n) pins the four exit paths that nothing pinned', function () {
    // Four routes to exit 2 that no arm covered. Each was measured to SURVIVE mutation at 10/10
    // before this test existed: change any of the three `exit 2`s to `exit 1`, or delete the
    // missing-target guard outright, and the suite stayed green. An exit code no test reads is a
    // convention, not a contract — and this script's whole argument is that 2 must never be
    // mistaken for 1 or 0.

    // 1. No branch given. Usage is not an answer about a branch.
    [$exitNone, $outputNone] = landedFixture('', '');
    expect($exitNone)->toBe(2)
        ->and($outputNone)->toContain('no branch given');

    // 2. Not a git repository. There is nothing to be right or wrong about.
    [$exitNoRepo, $outputNoRepo] = landedFixture('mkdir -p "$TMP/notrepo"', 'feat/x', 'notrepo');
    expect($exitNoRepo)->toBe(2)
        ->and($outputNoRepo)->toContain('not a git repository');

    // 3. The TARGET does not exist on origin. A typo in the target is unknown, not "not landed" —
    //    reporting it as a failed check would teach a reader that a red here is routine.
    $plantBranch = <<<'BASH'
git checkout -q -b feat/x
echo one >> f.txt
git commit -qam "the reviewed commit"
git push -q origin feat/x
git checkout -q staging
BASH;

    [$exitNoTarget, $outputNoTarget] = landedFixture($plantBranch, 'feat/x nosuchtarget');
    expect($exitNoTarget)->toBe(2)
        ->and($outputNoTarget)->toContain('no such branch');

    // 4. …and specifically NOT as a failed check. Delete the guard and the script falls through
    //    to a check-1 comparison against a ref that does not resolve, which prints the verdict
    //    line. This is the assertion that turns that mutation red.
    expect($outputNoTarget)->not->toContain('✗ NOT landed')
        ->and($outputNoTarget)->not->toContain('✓ landed');
});

it('(o) the hint refuses a merge of another branch whose name ends in this one', function () {
    // `*"/$BRANCH"` SPANS SLASHES. A shell glob's `*` matches `/` like any other character, so
    // the subject `Merge pull request #11 from o/feat/x` matched when the branch asked about was
    // plain `x`, and the hint offered another branch's merge as a lead about this one. Measured:
    // with the slash-spanning form restored, no arm went red.
    //
    // The branch here is `x`; the only merge on the target is of `feat/x`. A correct comparison
    // splits on " from ", requires a slash-free owner, and compares the branch component whole.
    [$exit, $output] = landedFixture(<<<'BASH'
git checkout -q -b feat/x
echo one >> f.txt
git commit -qam "another branch entirely"
git push -q origin feat/x
git checkout -q staging
git merge -q --no-ff -m "Merge pull request #11 from o/feat/x" feat/x
git push -q origin staging

git checkout -q -b x
echo mine >> f.txt
git commit -qam "the branch actually being asked about"
git push -q origin x
git checkout -q staging
BASH, 'x');

    expect($exit)->toBe(1)
        ->and($output)->toContain('origin/x has 1 commit(s) that origin/staging does not have')
        ->and($output)->toContain('origin/x is not contained in origin/staging')
        // feat/x's merge is not a lead about x. No hint at all.
        ->and($output)->not->toContain('READ FROM A MERGE MESSAGE')
        ->and($output)->not->toContain('Merge pull request #11')
        ->and($output)->toContain('(1 check(s) failed)');
});

it('(p) exits 2 when it cannot tell whether the clone is shallow', function () {
    // The third arm of the shallow guard, and the one that needs a stub: `--is-shallow-repository`
    // is answered by git, so the only way to hand the script an answer that is neither `true` nor
    // `false` is to put a shim ahead of git on PATH. Without this arm the branch survived
    // mutation — deleting it entirely left the suite green at 15/15.
    //
    // AN UNEXPECTED VALUE IS NOT EVIDENCE OF A WHOLE GRAPH. Treating anything-not-true as false
    // is how a guard becomes decoration: a future git that renames the flag, or prints a warning
    // on the same stream, would silently re-open exactly the false red the guard exists to stop.
    [$exit, $output] = landedFixture(<<<'BASH'
git checkout -q -b feat/x
echo one >> f.txt
git commit -qam "the reviewed commit"
git push -q origin feat/x
git checkout -q staging
git merge -q --no-ff -m "Merge pull request #12 from o/feat/x" feat/x
git push -q origin staging

# A pass-through git that answers only the shallow probe, with something unparseable.
mkdir -p "$TMP/shim"
REAL_GIT="$(command -v git)"
cat > "$TMP/shim/git" <<SHIM
#!/bin/sh
if [ "\$1" = "rev-parse" ] && [ "\$2" = "--is-shallow-repository" ]; then
    echo "perhaps"
    exit 0
fi
exec $REAL_GIT "\$@"
SHIM
chmod +x "$TMP/shim/git"
export PATH="$TMP/shim:$PATH"
BASH);

    expect($exit)->toBe(2)
        ->and($output)->toContain('could not determine whether this clone is shallow')
        // The value is echoed, so a reader can see what git actually said.
        ->and($output)->toContain('perhaps')
        // It is unknown, not wrong, and not fine.
        ->and($output)->not->toContain('✓ landed')
        ->and($output)->not->toContain('✗ NOT landed');
});

it('never writes anything but remote-tracking refs — no push, no merge, no checkout, no reset', function () {
    // The docblock claims one mutation. This asserts the claim against the source rather than
    // against the prose, because a later edit that adds a convenience `git push` would leave the
    // docblock reading exactly as it does now.
    //
    // It is a source assertion and it says so: it proves the tokens are absent, not that some
    // future indirection could not write. The arms above prove the behaviour; this pins the shape.
    $script = file_get_contents(dirname(__DIR__, 3).'/bin/landed');

    // Strip comments and the usage heredoc — both legitimately discuss the forbidden verbs.
    $code = preg_replace('/^\s*#.*$/m', '', $script);
    $code = preg_replace("/<<'USAGE'.*?^USAGE$/ms", '', $code);

    // NO MESSAGE ARGUMENT, deliberately: `toContain(...$needles)` has no `$message` parameter, so a
    // second argument becomes a SECOND NEEDLE — and a negated multi-needle toContain passes as soon
    // as ANY one of them is absent, which the message always is. That is a near-vacuous assertion
    // wearing the shape of a strict one. See tests/Feature/Quality/PestNegatedExpectationMessagesTest.php.
    foreach (['git push', 'git merge ', 'git checkout', 'git reset', 'git branch', 'git update-ref'] as $forbidden) {
        expect($code)->not->toContain($forbidden);
    }

    // Non-vacuity: the stripping must not have removed the whole file.
    expect($code)->toContain('git fetch --prune origin')
        ->and($code)->toContain('git merge-base')      // read-only, and the one allowed near-miss
        ->and(strlen($code))->toBeGreaterThan(1500);
});
