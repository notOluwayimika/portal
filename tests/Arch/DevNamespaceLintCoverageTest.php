<?php

/*
 * Coverage for bin/ci-dev-namespace-lint.php itself.
 *
 * A lint rule that has never failed has not been tested; it has been written. So these plant a real
 * violating file on disk, run the real script, and assert what it reports — no pattern is
 * re-implemented here, because a test that re-implements the thing it tests passes when both are
 * wrong together.
 *
 * WHY THE LINT EXISTS: a `use Tests\…;` inside app/ or database/seeders/ names a class that does not
 * exist under `composer install --no-dev`, and nothing in the floor refused it — boundary lint,
 * authz lint and Larastan all pass over one. The only thing that reacted was the arch pass, and it
 * reacted by exiting 255 with zero bytes on every stream, because the fatal sat in an output buffer
 * Pest never flushed. Four bisection rounds to read one line.
 *
 * ⚠️ THESE PLANT INTO THE REAL TREE, so they are safe only while Pest runs sequentially — the same
 * constraint BoundaryLintCoverageTest documents at length, and for the same reason: arm 4 asserts the
 * lint is GREEN over the tree while arms 1-3 have files planted in it, so they contradict each other
 * the moment they overlap in time.
 */

use Illuminate\Support\Str;

uses()->group('arch');

/**
 * Plant $body at $relativePath, run the REAL lint, remove the file, return [exitCode, output].
 *
 * The finally is load-bearing: a leaked fixture fails the lint for everyone afterwards, for a reason
 * that has nothing to do with their change.
 */
function devNsLintWithFixture(string $relativePath, string $body): array
{
    $root = dirname(__DIR__, 2);
    $full = $root.'/'.$relativePath;

    @mkdir(dirname($full), 0777, true);
    file_put_contents($full, $body);

    try {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open('php bin/ci-dev-namespace-lint.php', $descriptors, $pipes, $root);
        $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        return [$code, $out];
    } finally {
        @unlink($full);
    }
}

it('reports a dev-only import from a production path, naming the file and the symbol', function () {
    $name = 'DevNsLintFixture'.Str::random(8);

    [$code, $out] = devNsLintWithFixture("app/{$name}.php", <<<PHP
    <?php

    namespace App;

    use Tests\\Feature\\Rbac\\ForcingMigrationsDoNotStripLaterGrantsTest;

    class {$name} {}
    PHP);

    expect($code)->toBe(1)
        ->and($out)->toContain($name.'.php')
        ->and($out)->toContain('ForcingMigrationsDoNotStripLaterGrantsTest')
        // It must say WHY, not merely that: --no-dev is the consequence an author needs to hear.
        ->and($out)->toContain('--no-dev');
});

it('does NOT report the same import from tests/, which is where it is legitimate', function () {
    $name = 'DevNsLintFixture'.Str::random(8);

    // THE ARM THAT STOPS THE LINT PASSING BY REFUSING EVERYTHING. tests/ is not a production path, so
    // an identical import there is correct code and must stay silent — otherwise the rule would red
    // over the entire suite and be baselined into uselessness on its first run.
    [$code, $out] = devNsLintWithFixture("tests/Feature/{$name}.php", <<<PHP
    <?php

    namespace Tests\\Feature;

    use Tests\\Feature\\Rbac\\ForcingMigrationsDoNotStripLaterGrantsTest;

    class {$name} {}
    PHP);

    expect($code)->toBe(0)->and($out)->not->toContain($name);
});

it('does NOT report a production import from a production path', function () {
    $name = 'DevNsLintFixture'.Str::random(8);

    // The second half of "not vacuous": a production file importing a PRODUCTION namespace is the
    // overwhelmingly common case and must not trip. Without this, a rule matching every `use` would
    // pass arm 1.
    [$code, $out] = devNsLintWithFixture("app/{$name}.php", <<<PHP
    <?php

    namespace App;

    use App\\Enums\\Permission;

    class {$name} {}
    PHP);

    expect($code)->toBe(0)->and($out)->not->toContain($name);
});

it('does NOT report a dev-only name that is merely MENTIONED rather than imported', function () {
    $name = 'DevNsLintFixture'.Str::random(8);

    // Citing a test from production code in prose or a comment is legitimate and common — it is the
    // PROMOTION to an import that creates the dependency. A rule that grepped for the token `Tests\`
    // would red on this and push authors toward deleting useful references.
    [$code, $out] = devNsLintWithFixture("app/{$name}.php", <<<PHP
    <?php

    namespace App;

    /**
     * The invariant is pinned by tests/Feature/Rbac/ForcingMigrationsDoNotStripLaterGrantsTest.php
     * and by Tests\\Feature\\Rbac in general.
     */
    class {$name} {}
    PHP);

    expect($code)->toBe(0)->and($out)->not->toContain($name);
});

it('is clean on the tree as it stands', function () {
    // The shrink-lock equivalent. The population was measured at exactly one before this rule landed
    // and that one is fixed in the same change, which is why there is no baseline: a rule that shipped
    // red would hand the next person a cleanup and invite exactly the baselining this file prevents.
    $root = dirname(__DIR__, 2);
    exec('cd '.escapeshellarg($root).' && php bin/ci-dev-namespace-lint.php 2>&1', $lines, $code);

    expect($code)->toBe(0, "dev-namespace-lint is not clean:\n".implode("\n", $lines));
});
