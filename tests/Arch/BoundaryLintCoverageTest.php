<?php

/*
 * Coverage for bin/ci-boundary-lint.php itself.
 *
 * ArchitectureBoundaryTest's header records why this file has to exist: §17.1 rule 4 concerns METHOD
 * CALLS, which arch tests cannot see, so it is enforced by a grep lint instead. Nothing was enforcing
 * the enforcer — and the soft-delete hole is what that costs. `withTrashed()` IS
 * `withoutGlobalScope(SoftDeletingScope::class)`, so the BEHAVIOUR was forbidden from the first
 * Finance commit, but the TOKEN was not in the pattern and `Student::withTrashed()` inside
 * app/Finance passed all thirteen quality steps.
 *
 * A lint rule that has never failed has not been tested; it has been written. So these plant a real
 * violating file on disk, run the real script, and assert it is REPORTED — no regex is re-implemented
 * here, because a test that re-implements the thing it tests passes when both are wrong together.
 */

use Illuminate\Support\Str;

uses()->group('arch');

/**
 * Write $body to a temp PHP file under app/Finance/, run the real lint, delete the file, and return
 * [exitCode, stderr+stdout, basename]. The finally is load-bearing: a leaked fixture would fail
 * bin/quality step 6 for everyone afterwards, and it would fail it for a reason that has nothing to
 * do with their change. `.gitignore` covers the residue the finally cannot — a SIGKILL or a crashed
 * host leaves an UNTRACKED file, which is the one outcome that outlives the run and is committable.
 *
 * ⚠️ THIS PLANTS INTO THE REAL TREE, SO IT IS SAFE ONLY WHILE PEST RUNS SEQUENTIALLY. Verified at
 * this commit: `bin/quality:265 (arch)` is `pest --group=arch` (step 14) and `:293` is plain
 * `pest` (step 16); `--parallel` appears only on Pint (`composer.json:67,70`). Both line numbers and both
 * step numbers move whenever a step is added — they are re-derived, not carried, and the citation
 * lint moved them last. If you are adding `--parallel` to either, THIS FILE
 * BREAKS FIRST — test 3 asserts the lint is GREEN over the tree while tests 1 and 2 have violations
 * planted in it, so the three tests contradict each other the moment they overlap in time. Give the
 * lint a root argument and point the fixtures at a temp dir before you parallelise, or exclude this
 * file from the parallel run.
 */
function lintWithFinanceFixture(string $body): array
{
    $root = dirname(__DIR__, 2);
    $path = $root.'/app/Finance/BoundaryLintFixture'.Str::random(8).'.php';

    file_put_contents($path, $body);

    try {
        $output = [];
        $exit = 0;
        exec('php '.escapeshellarg($root.'/bin/ci-boundary-lint.php').' 2>&1', $output, $exit);

        return [$exit, implode("\n", $output), basename($path)];
    } finally {
        @unlink($path);
    }
}

it('reports Student::withTrashed() inside app/Finance — the hole the token-grep left open', function () {
    [$exit, $output, $file] = lintWithFinanceFixture(<<<'PHP'
<?php

namespace App\Finance;

use App\Models\Student;

final class BoundaryLintFixture
{
    public function leak(): mixed
    {
        return Student::withTrashed()->get();
    }
}
PHP);

    // Exit 1 AND named. An exit code alone would also be produced by an unrelated new violation
    // somewhere else in the tree, so the assertion names the rule and the file.
    expect($exit)->toBe(1)
        ->and($output)->toContain('finance-escape-hatches')
        ->and($output)->toContain($file)
        ->and($output)->toContain('withTrashed');
});

it('reports withoutTrashed() and a bare SoftDeletingScope reference by the same rule', function () {
    // Arm A — `withoutTrashed()`, the inverse call on the same scope. No other token in the pattern
    // matches this line, so deleting `withoutTrashed\(` alone makes this arm red.
    [$exitA, $outputA, $fileA] = lintWithFinanceFixture(<<<'PHP'
<?php

namespace App\Finance;

use App\Models\Student;

final class BoundaryLintFixture
{
    public function leak(): mixed
    {
        return Student::query()->withoutTrashed()->get();
    }
}
PHP);

    // Arm B — a BARE `SoftDeletingScope` reference, and the bareness is the entire point.
    //
    // This arm previously read `withoutGlobalScope(SoftDeletingScope::class)`, which the pattern's
    // ORIGINAL `withoutGlobalScopes?\(` token already matched before the soft-delete tokens were
    // added — so it passed with all three new tokens deleted and the `SoftDeletingScope` token had
    // ZERO coverage. A test that is satisfied by a rule other than the one it names is worse than no
    // test: it reports as coverage.
    //
    // So this fixture writes `withoutGlobalScope(` NOWHERE and `withTrashed(` NOWHERE. The only
    // banned token anywhere in it is the class name itself, on the `use` line and on the constant —
    // which is exactly the shape that reaches the scope by an alias: hold the class, hand it to
    // something else, and the call site that peels the scope off lives in another file the grep is
    // not looking at. Delete `|SoftDeletingScope` from the pattern and this arm, alone, goes red.
    [$exitB, $outputB, $fileB] = lintWithFinanceFixture(<<<'PHP'
<?php

namespace App\Finance;

use Illuminate\Database\Eloquent\SoftDeletingScope;

final class BoundaryLintFixture
{
    private const SCOPE = SoftDeletingScope::class;

    public function scope(): string
    {
        return self::SCOPE;
    }
}
PHP);

    expect($exitA)->toBe(1)
        ->and($outputA)->toContain('finance-escape-hatches')
        ->and($outputA)->toContain($fileA)
        ->and($outputA)->toContain('withoutTrashed');

    expect($exitB)->toBe(1)
        ->and($outputB)->toContain('finance-escape-hatches')
        ->and($outputB)->toContain($fileB)
        ->and($outputB)->toContain('SoftDeletingScope');
});

it('does not report the same call OUTSIDE app/Finance, and is clean on the tree as it stands', function () {
    // The negative arm, and it is the one that keeps the rule from being "always red". The lint is
    // Finance-scoped by construction; app/Models/StudentCurriculum.php:97 uses withTrashed() today
    // and must stay legal. Running the lint with no fixture planted asserts exactly that: the tree
    // contains a real withTrashed() call and the lint is green.
    $root = dirname(__DIR__, 2);
    $output = [];
    $exit = 0;
    exec('php '.escapeshellarg($root.'/bin/ci-boundary-lint.php').' 2>&1', $output, $exit);

    expect($exit)->toBe(0)
        ->and(implode("\n", $output))->toContain('no new boundary violations');

    // …and that the call really is out there, so the assertion above is not vacuous.
    expect(file_get_contents($root.'/app/Models/StudentCurriculum.php'))->toContain('withTrashed()');
});
