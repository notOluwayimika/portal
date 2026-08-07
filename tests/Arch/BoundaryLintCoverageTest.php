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
 * [exitCode, stderr+stdout]. The finally is load-bearing: a leaked fixture would fail bin/quality
 * step 6 for everyone afterwards, and it would fail it for a reason that has nothing to do with
 * their change.
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

it('reports withoutTrashed() and SoftDeletingScope by the same rule', function () {
    // The other two spellings of the same escape. `withoutTrashed()` is the inverse call on the same
    // scope, and naming SoftDeletingScope directly is how you would reach it via
    // withoutGlobalScope(...) without writing the banned token.
    [$exitA, $outputA] = lintWithFinanceFixture(<<<'PHP'
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

    [$exitB, $outputB] = lintWithFinanceFixture(<<<'PHP'
<?php

namespace App\Finance;

use App\Models\Student;
use Illuminate\Database\Eloquent\SoftDeletingScope;

final class BoundaryLintFixture
{
    public function leak(): mixed
    {
        return Student::query()->withoutGlobalScope(SoftDeletingScope::class)->get();
    }
}
PHP);

    expect($exitA)->toBe(1)->and($outputA)->toContain('finance-escape-hatches')
        ->and($exitB)->toBe(1)->and($outputB)->toContain('finance-escape-hatches');
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
