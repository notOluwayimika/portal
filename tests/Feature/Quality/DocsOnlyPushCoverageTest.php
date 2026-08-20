<?php

/*
 * Coverage for bin/is-docs-only-push and for the .githooks/pre-push wiring that consults it.
 *
 * WHAT IS AT STAKE HERE IS A SUPPRESSED GATE. Every other lint in this repository fails
 * closed: a matcher that drifts stops matching and prints OK over a live violation, which is
 * bad, but the gate itself still ran. This one is different in kind — a wrong answer here does
 * not weaken a check, it removes fifteen of them. A single character in the prefix test
 * ("docs" for "docs/") is enough to skip the suite over a PHP file, and the only visible
 * consequence is a push that finished quickly.
 *
 * So these PLANT the pushes. Each arm builds a real repository in a temp directory, arranges
 * the exact range, runs the real script, and asserts BOTH the exit code AND the message. The
 * exit code alone does not discriminate: 1 is returned for "not documentation", for "no base"
 * and for "nothing changed", and an arm that checked only the code would pass while the script
 * refused for the wrong reason.
 *
 * THE HOOK ITSELF IS EXERCISED, not just the script — arms (k) through (o). A correct decision
 * that is wired in wrongly is worth nothing, and the case that matters most (a docs-only push
 * to main must still hit the release gate) lives entirely in the wiring. Those arms run the
 * real .githooks/pre-push against a planted stdin, with a STUB bin/quality that announces
 * itself, so "the gate ran" and "the gate was skipped" are directly observable rather than
 * inferred from a timing.
 *
 * NO NETWORK, NO DATABASE. Nothing here clones, fetches or pushes; the fixtures are built
 * under mktemp -d, outside the tree, so a leaked fixture cannot become a committable untracked
 * file.
 *
 * THE FIXTURES ARE CONFIG-ISOLATED, for LandedCheckCoverageTest's reasons and by its method:
 * GIT_CONFIG_GLOBAL and GIT_CONFIG_SYSTEM at /dev/null, HOME redirected into the temp
 * directory, and GIT_CONFIG_COUNT with its GIT_CONFIG_KEY_n / GIT_CONFIG_VALUE_n pairs unset —
 * those outrank every config file, including the two redirected above, and are the one channel
 * the redirection does not close. A developer's signing key, commit template or core.hooksPath
 * cannot reach in and change what these assert.
 *
 * BITE-PROVED. Every arm below was verified to go RED against a stated mutation of
 * bin/is-docs-only-push or of the hook; each mutation and its raw red is recorded in
 * docs/handoff/reports/feat-docs-only-gate.md.
 */

uses()->group('arch');

/**
 * Build a planted repository, run the real bin/is-docs-only-push in it, and return
 * [exitCode, output].
 *
 * $setup is bash, run with `set -e`, in a work repo on branch `staging` with one commit
 * already made. It must leave BASE and HEAD set to the two shas the script is to be given.
 */
function docsOnlyFixture(string $setup): array
{
    return docsOnlyRun($setup, <<<'BASH'
set +e
cd "$TMP/work"
"$ROOT/bin/is-docs-only-push" "${BASE:-}" "${HEAD:-}" 2>&1
echo "___EXIT:$?"
BASH);
}

/**
 * Build a planted repository containing a COPY of the real hook and the real checker, plus a
 * stub bin/quality that prints `___QUALITY_RAN`, and run the hook against planted stdin.
 *
 * $setup must leave REFLINE set to the stdin git would send: one or more lines of
 * `<local_ref> <local_sha> <remote_ref> <remote_sha>`.
 *
 * THE STUB IS THE MEASUREMENT. It exits 0, so an arm that asserts on `___QUALITY_RAN` is
 * asserting that the gate was INVOKED, not that it passed — which is the only thing the
 * wiring can be responsible for.
 */
function docsOnlyHookFixture(string $setup): array
{
    return docsOnlyRun($setup, <<<'BASH'
cd "$TMP/work"
mkdir -p bin .githooks
cp "$ROOT/bin/is-docs-only-push" bin/is-docs-only-push
cp "$ROOT/.githooks/pre-push" .githooks/pre-push
chmod +x bin/is-docs-only-push .githooks/pre-push
# Arm (q) asks what happens when the checker is not there at all.
[ "${REMOVE_CHECKER:-0}" = "1" ] && rm -f bin/is-docs-only-push
cat > bin/quality <<'STUB'
#!/usr/bin/env bash
echo "___QUALITY_RAN"
exit 0
STUB
chmod +x bin/quality

set +e
printf '%s\n' "$REFLINE" | bash .githooks/pre-push origin "$ORIGIN" 2>&1
echo "___EXIT:$?"
BASH);
}

function docsOnlyRun(string $setup, string $invocation): array
{
    $root = dirname(__DIR__, 3);
    $tmp = rtrim(shell_exec('mktemp -d') ?? '', "\n");

    if ($tmp === '' || ! is_dir($tmp)) {
        throw new RuntimeException('could not create a temp directory for the docs-only fixture');
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

# These outrank every config file, including the two redirected to /dev/null above.
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
git config user.name "Docs-Only Fixture"
git config commit.gpgsign false
git remote add origin "$ORIGIN"

echo base > f.txt
git add f.txt
git commit -qm "base commit"

%s

%s
BASH,
        escapeshellarg($tmp),
        escapeshellarg($root),
        $setup,
        $invocation,
    );

    try {
        $scriptPath = $tmp.'/fixture.sh';
        file_put_contents($scriptPath, $script);

        $output = [];
        $ignored = 0;
        exec('bash '.escapeshellarg($scriptPath).' 2>&1', $output, $ignored);
        $raw = implode("\n", $output);

        // Strip SGR sequences: the assertions are about words, and the hook colours whole lines.
        $clean = preg_replace('/\033\[[0-9;]*m/', '', $raw);

        $exit = null;
        foreach (explode("\n", $clean) as $line) {
            if (str_starts_with($line, '___EXIT:')) {
                $exit = (int) substr($line, 8);
            }
        }

        if ($exit === null) {
            throw new RuntimeException("the fixture never reached the script under test:\n".$clean);
        }

        return [$exit, $clean];
    } finally {
        exec('rm -rf '.escapeshellarg($tmp));
    }
}

it('(a) says docs-only when the range touches only docs/', function () {
    // The healthy shape, and the control for every arm below: a script that always refused
    // would pass every negative arm here and be worthless.
    //
    // TWO FILES, NOT ONE, DELIBERATELY. The first version of the checker routed `git diff -z`
    // through a command substitution, and bash DISCARDS NUL bytes there — every path arrived
    // concatenated with no separator and the loop read zero fields, so every range reported
    // "changes no files". A single-file arm would still have shown the wrong reason; two
    // files is the shape that makes the truncation visible.
    [$exit, $output] = docsOnlyFixture(<<<'BASH'
BASE=$(git rev-parse HEAD)
mkdir -p docs/handoff/reports docs/handoff/tickets
echo "the report" > docs/handoff/reports/feat-x.md
echo "the ticket" > docs/handoff/tickets/y.md
git add docs
git commit -qm "docs(x): the report and its ticket"
HEAD=$(git rev-parse HEAD)
BASH);

    expect($exit)->toBe(0)
        ->and($output)->toContain('docs/handoff/reports/feat-x.md')
        ->and($output)->toContain('docs/handoff/tickets/y.md')
        ->and($output)->not->toContain('not documentation');
});

it('(b) refuses when the range touches docs/ and one PHP file', function () {
    // The mixed commit is the common real case: a change plus the note explaining it. It is
    // NOT docs-only, and the refusal must name the file that made it so — a bare exit 1 is
    // indistinguishable from the no-base and empty-range refusals.
    [$exit, $output] = docsOnlyFixture(<<<'BASH'
BASE=$(git rev-parse HEAD)
mkdir -p docs app/Support
echo "the report" > docs/report.md
echo "<?php" > app/Support/Thing.php
git add docs app
git commit -qm "feat: the thing and its report"
HEAD=$(git rev-parse HEAD)
BASH);

    expect($exit)->toBe(1)
        ->and($output)->toContain('not documentation: app/Support/Thing.php');
});

it('(c) refuses when the range touches only PHP', function () {
    [$exit, $output] = docsOnlyFixture(<<<'BASH'
BASE=$(git rev-parse HEAD)
mkdir -p app/Support
echo "<?php" > app/Support/Thing.php
git add app
git commit -qm "feat: the thing"
HEAD=$(git rev-parse HEAD)
BASH);

    expect($exit)->toBe(1)
        ->and($output)->toContain('not documentation: app/Support/Thing.php');
});

it('(d) refuses a multi-commit range whose middle commit is code', function () {
    // THE RANGE IS THE UNIT, NOT THE COMMIT. A push carries everything since the remote's tip,
    // and a checker that looked only at the tip commit would skip the gate here while a PHP
    // file went up underneath two prose commits. That is the whole reason this takes a base
    // and a head rather than a single sha.
    [$exit, $output] = docsOnlyFixture(<<<'BASH'
BASE=$(git rev-parse HEAD)
mkdir -p docs app/Support

echo "one" > docs/one.md
git add docs
git commit -qm "docs: the first note"

echo "<?php" > app/Support/Thing.php
git add app
git commit -qm "feat: the thing"

echo "two" > docs/two.md
git add docs
git commit -qm "docs: the second note"
HEAD=$(git rev-parse HEAD)
BASH);

    expect($exit)->toBe(1)
        ->and($output)->toContain('not documentation: app/Support/Thing.php')
        // …and the two prose files are still reported as judged, so the list a reader sees is
        // the list the verdict was taken over.
        ->and($output)->toContain('docs/one.md')
        ->and($output)->toContain('docs/two.md');
});

it('(e) refuses docsomething.php at the repository root — a prefix match is not a directory', function () {
    // THIS ARM IS THE POINT OF THE WHOLE FILE. `docs*` is the glob anyone writes first and it
    // matches this file, so the naive rule skips the suite over a PHP change. The prefix under
    // test is `docs/` WITH THE SLASH, and this is the only thing that tells the two apart.
    [$exit, $output] = docsOnlyFixture(<<<'BASH'
BASE=$(git rev-parse HEAD)
mkdir -p docs
echo "the report" > docs/report.md
echo "<?php // not documentation" > docsomething.php
git add docs docsomething.php
git commit -qm "docs: the report, and a file whose name begins with docs"
HEAD=$(git rev-parse HEAD)
BASH);

    expect($exit)->toBe(1)
        ->and($output)->toContain('not documentation: docsomething.php');
});

it('(f) refuses a file renamed out of docs/', function () {
    // A MOVE HAS TWO ENDS AND BOTH MUST BE JUDGED. With git's rename detection on,
    // `--name-only` reports a rename as its DESTINATION only: `docs/moved.md` -> `moved.md`
    // presents as the single path `moved.md` (caught here), but the mirror image
    // `thing.php` -> `docs/thing.md` presents as the single path `docs/thing.md` and would be
    // judged docs-only while a PHP file was deleted. --no-renames splits every move into a
    // delete and an add, so neither direction can hide a non-docs path.
    [$exit, $output] = docsOnlyFixture(<<<'BASH'
mkdir -p docs
echo "moved" > docs/moved.md
git add docs
git commit -qm "docs: a file that will move"
BASE=$(git rev-parse HEAD)

git mv docs/moved.md moved.md
git commit -qm "chore: move it out of docs"
HEAD=$(git rev-parse HEAD)
BASH);

    expect($exit)->toBe(1)
        ->and($output)->toContain('not documentation: moved.md')
        // Both ends present: the delete under docs/ and the add outside it.
        ->and($output)->toContain('docs/moved.md');
});

it('(g) refuses when the base is all zeros, however docs-only the contents are', function () {
    // A NEW BRANCH HAS NO BASE. git sends all zeros for a ref that does not exist on the
    // remote, so there is nothing to diff against — and every commit beneath the first push was
    // never gated by this hook at all. Without this rule, a branch whose newest commit happens
    // to be prose pushes its entire unverified history past the gate.
    [$exit, $output] = docsOnlyFixture(<<<'BASH'
mkdir -p docs
echo "the report" > docs/report.md
git add docs
git commit -qm "docs: the report"
BASE=0000000000000000000000000000000000000000
HEAD=$(git rev-parse HEAD)
BASH);

    expect($exit)->toBe(1)
        ->and($output)->toContain('base is all zeros');
});

it('(h) cannot determine anything from a sha that is not in the repository', function () {
    // 2, NOT 1 — "wrong" and "unknown" are different answers, for bin/landed's reason. The
    // CALLER collapses them, because both mean "run the gate"; the script must not, because a
    // green on a question it could not answer is the entire defect class this guards.
    [$exit, $output] = docsOnlyFixture(<<<'BASH'
BASE=deadbeefdeadbeefdeadbeefdeadbeefdeadbeef
mkdir -p docs
echo "the report" > docs/report.md
git add docs
git commit -qm "docs: the report"
HEAD=$(git rev-parse HEAD)
BASH);

    expect($exit)->toBe(2)
        ->and($output)->toContain('is not a commit in this repository');
});

it('(i) refuses to call a range that changes nothing docs-only', function () {
    // VACUOUS TRUTH IS NOT A SKIP. "every file is under docs/" holds over no files, and a
    // suppressed gate justified by an empty set is the false-green shape this repository has
    // paid for repeatedly. Refused explicitly, and by its own message.
    [$exit, $output] = docsOnlyFixture(<<<'BASH'
BASE=$(git rev-parse HEAD)
HEAD=$(git rev-parse HEAD)
BASH);

    expect($exit)->toBe(1)
        ->and($output)->toContain('changes no files');
});

it('(j) reads a docs/ path containing a non-ASCII byte', function () {
    // WITHOUT -z, `git diff --name-only` QUOTES and octal-escapes such a path — it would arrive
    // as the literal `"docs/a — b.md"`, quotes and all, which still begins with a quote rather
    // than with `docs/` and would be judged not-documentation. The failure direction is safe
    // but the reason is wrong, and the same escaping is what bin/lint-changed.sh:24-29 records
    // silently dropping files. This pins the -z.
    [$exit, $output] = docsOnlyFixture(<<<'BASH'
BASE=$(git rev-parse HEAD)
mkdir -p docs
echo "em dash" > "docs/a — b.md"
git add docs
git commit -qm "docs: a path with a non-ASCII byte"
HEAD=$(git rev-parse HEAD)
BASH);

    expect($exit)->toBe(0)
        ->and($output)->toContain('docs/a — b.md')
        ->and($output)->not->toContain('not documentation');
});

it('(k) the hook skips bin/quality on a docs-only push, and says so in words bin/quality never prints', function () {
    // THE WIRING, not the decision. The stub bin/quality announces itself and exits 0, so its
    // ABSENCE from the output is the measurement that the gate was not invoked.
    [$exit, $output] = docsOnlyHookFixture(<<<'BASH'
BASE=$(git rev-parse HEAD)
mkdir -p docs
echo "the report" > docs/report.md
git add docs
git commit -qm "docs: the report"
HEAD=$(git rev-parse HEAD)
REFLINE="refs/heads/feat/x $HEAD refs/heads/feat/x $BASE"
BASH);

    expect($exit)->toBe(0)
        ->and($output)->toContain('DOCS-ONLY PUSH')
        ->and($output)->toContain('bin/quality was NOT RUN')
        ->and($output)->toContain('docs/report.md')
        // A SKIPPED GREEN MUST NOT READ LIKE A FULL GREEN. bin/quality's success line is
        // `✓ quality: PASS — per-push floor…`; nothing here may share it, so that grepping a
        // scrollback a week later separates the two kinds of green.
        ->and($output)->not->toContain('quality: PASS')
        ->and($output)->not->toContain('running bin/quality')
        // …and the gate itself never ran.
        ->and($output)->not->toContain('___QUALITY_RAN');
});

it('(l) the hook runs the full gate when the same push touches one PHP file', function () {
    [$exit, $output] = docsOnlyHookFixture(<<<'BASH'
BASE=$(git rev-parse HEAD)
mkdir -p docs app/Support
echo "the report" > docs/report.md
echo "<?php" > app/Support/Thing.php
git add docs app
git commit -qm "feat: the thing and its report"
HEAD=$(git rev-parse HEAD)
REFLINE="refs/heads/feat/x $HEAD refs/heads/feat/x $BASE"
BASH);

    expect($exit)->toBe(0)
        ->and($output)->toContain('running bin/quality')
        ->and($output)->toContain('___QUALITY_RAN')
        ->and($output)->not->toContain('DOCS-ONLY PUSH');
});

it('(m) a docs-only push to main still hits the release gate', function () {
    // THE ARM THIS CHANGE IS MOST DANGEROUS WITHOUT. refs/heads/main is the release path and
    // the quality-promote stamp; a docs-only release is still a release. There is no stamp in
    // this fixture, so the correct outcome is the existing refusal at .githooks/pre-push:41-70
    // — reached BEFORE the docs-only block, and unchanged by it.
    [$exit, $output] = docsOnlyHookFixture(<<<'BASH'
BASE=$(git rev-parse HEAD)
mkdir -p docs
echo "the report" > docs/report.md
git add docs
git commit -qm "docs: the report"
HEAD=$(git rev-parse HEAD)
REFLINE="refs/heads/main $HEAD refs/heads/main $BASE"
BASH);

    expect($exit)->toBe(1)
        ->and($output)->toContain('push to main blocked')
        ->and($output)->toContain('no release-gate pass')
        ->and($output)->not->toContain('DOCS-ONLY PUSH')
        ->and($output)->not->toContain('___QUALITY_RAN');
});

it('(n) one non-docs ref in a multi-ref push runs the full gate for all of them', function () {
    // git can send several refs on one stdin and they land together. Skipping because the
    // FIRST range was prose would leave the second ungated, so the push is docs-only only if
    // every range is.
    [$exit, $output] = docsOnlyHookFixture(<<<'BASH'
BASE=$(git rev-parse HEAD)

mkdir -p docs
echo "the report" > docs/report.md
git add docs
git commit -qm "docs: the report"
DOCS_HEAD=$(git rev-parse HEAD)

git checkout -q -b feat/code "$BASE"
mkdir -p app/Support
echo "<?php" > app/Support/Thing.php
git add app
git commit -qm "feat: the thing"
CODE_HEAD=$(git rev-parse HEAD)

REFLINE="refs/heads/feat/docs $DOCS_HEAD refs/heads/feat/docs $BASE
refs/heads/feat/code $CODE_HEAD refs/heads/feat/code $BASE"
BASH);

    expect($exit)->toBe(0)
        ->and($output)->toContain('___QUALITY_RAN')
        ->and($output)->not->toContain('DOCS-ONLY PUSH');
});

it('(o) the first push of a new branch runs the full gate even though its commit is prose', function () {
    [$exit, $output] = docsOnlyHookFixture(<<<'BASH'
mkdir -p docs
echo "the report" > docs/report.md
git add docs
git commit -qm "docs: the report"
HEAD=$(git rev-parse HEAD)
REFLINE="refs/heads/feat/x $HEAD refs/heads/feat/x 0000000000000000000000000000000000000000"
BASH);

    expect($exit)->toBe(0)
        ->and($output)->toContain('___QUALITY_RAN')
        ->and($output)->not->toContain('DOCS-ONLY PUSH');
});

it('(p) a docs-only push to main runs the full gate even WITH a valid release stamp', function () {
    // ARM (m) DOES NOT PROVE THIS AND MUST NOT BE READ AS PROVING IT. Without a stamp the
    // release gate refuses at .githooks/pre-push:48 and the docs-only block is never reached,
    // so (m) would stay green with the refs/heads/main exclusion deleted. WITH a stamp the
    // release gate passes and execution falls through — and that is the only path on which the
    // exclusion does any work. This is the arm that bites it.
    [$exit, $output] = docsOnlyHookFixture(<<<'BASH'
BASE=$(git rev-parse HEAD)
mkdir -p docs
echo "the report" > docs/report.md
git add docs
git commit -qm "docs: the report"
HEAD=$(git rev-parse HEAD)
echo "$HEAD" > .quality-promote-ok
REFLINE="refs/heads/main $HEAD refs/heads/main $BASE"
BASH);

    expect($exit)->toBe(0)
        ->and($output)->toContain('release gate verified')
        ->and($output)->toContain('___QUALITY_RAN')
        ->and($output)->not->toContain('DOCS-ONLY PUSH');
});

it('(q) the hook runs the full gate when the checker is not present at all', function () {
    // A checkout predating this script, or a partial clone. "I could not ask" is not "yes":
    // the fall-through is to run everything, and the hook must not error out either.
    [$exit, $output] = docsOnlyHookFixture(<<<'BASH'
REMOVE_CHECKER=1
BASE=$(git rev-parse HEAD)
mkdir -p docs
echo "the report" > docs/report.md
git add docs
git commit -qm "docs: the report"
HEAD=$(git rev-parse HEAD)
REFLINE="refs/heads/feat/x $HEAD refs/heads/feat/x $BASE"
BASH);

    expect($exit)->toBe(0)
        ->and($output)->toContain('___QUALITY_RAN')
        ->and($output)->not->toContain('DOCS-ONLY PUSH');
});
