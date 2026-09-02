<?php

/*
 * Coverage for bin/ci-activity-catalogue-lint.php itself.
 *
 * A lint rule that has never failed has not been tested; it has been written. Every arm here plants
 * a real defect — a real file on disk, or a real edit to the real config — runs the real script, and
 * asserts it is REPORTED. No rule is re-implemented in this file, because a test that re-implements
 * the thing it tests passes when both are wrong together.
 *
 * THE KNOWN NEGATIVE MATTERS MORE HERE THAN THE KNOWN POSITIVE. A gate that is broken-CLOSED refuses
 * everything, and refusing everything is indistinguishable from strictness until somebody bypasses
 * it, then disables it, and you are left with neither the gate nor the knowledge that it is gone.
 * bin/db-exclusive shipped exactly that defect — its matcher matched the invoking shell, so it
 * refused on a free database, always, and a busy-only bite-proof passed it. So the last arm asserts
 * the lint is GREEN over the unmutated tree, and it is the arm to read first if this file goes red
 * as a block.
 *
 * ⚠️ TWO ARMS PLANT INTO THE REAL TREE and two EDIT THE REAL CONFIG, so this is safe only while Pest
 * runs sequentially — the same constraint tests/Arch/SqlClockLintCoverageTest.php carries, and for
 * the same reason: the green arm asserts the lint passes over a tree the other arms have defects in.
 * Verified at this commit: bin/quality:357 (arch) is `pest --group=arch` (step 16) and the suite step
 * is plain `pest` (bin/quality:418 (pest), step 18); `--parallel` appears only on Pint. Every plant and every edit is undone in a `finally`;
 * .gitignore covers the residue a SIGKILL would leave, which is the only outcome that outlives the
 * run and is committable.
 *
 * THE R5 ARMS DO NOT TOUCH THE TREE AT ALL. They copy the real script, byte for byte, into a scratch
 * root and run it there — the script takes its root from `dirname(__DIR__)`, so a copy under
 * <scratch>/bin/ scans <scratch>/app. That is what lets "the scan found nothing" be exercised
 * without emptying app/.
 */

use Illuminate\Support\Str;

uses()->group('arch');

const CATALOGUE_LINT = 'bin/ci-activity-catalogue-lint.php';

/** Run the real lint over the real tree. @return array{0: int, 1: string} */
function runCatalogueLint(): array
{
    $root = dirname(__DIR__, 2);
    $output = [];
    $exit = 0;
    exec('php '.escapeshellarg($root.'/'.CATALOGUE_LINT).' 2>&1', $output, $exit);

    return [$exit, implode("\n", $output)];
}

/**
 * Plant $body at app/ActivityCatalogueLintFixture<rand>.php, run the lint, remove the file.
 *
 * @return array{0: int, 1: string, 2: string} [exit, output, basename]
 */
function lintWithPlantedEmitter(string $body): array
{
    $root = dirname(__DIR__, 2);
    $name = 'ActivityCatalogueLintFixture'.Str::random(8);
    $path = $root.'/app/'.$name.'.php';

    file_put_contents($path, str_replace('__CLASS_NAME__', $name, $body));

    try {
        [$exit, $output] = runCatalogueLint();

        return [$exit, $output, $name];
    } finally {
        @unlink($path);
    }
}

/**
 * Replace $old with $new in a real repository file, run the lint, then restore the file BYTE FOR
 * BYTE from the copy taken before the edit — not by reversing the substitution, which would leave
 * the file subtly wrong if the pattern appeared twice.
 *
 * @return array{0: int, 1: string}
 */
function lintWithEditedFile(string $relative, string $old, string $new): array
{
    $root = dirname(__DIR__, 2);
    $path = $root.'/'.$relative;
    $original = file_get_contents($path);

    expect(str_contains($original, $old))->toBeTrue(
        "the edit for this arm did not apply — `{$old}` is not in {$relative}, so the arm would "
        .'have measured an unmutated tree and passed for the wrong reason'
    );

    file_put_contents($path, str_replace($old, $new, $original, $count));

    try {
        return runCatalogueLint();
    } finally {
        file_put_contents($path, $original);
    }
}

/**
 * Copy the real script into a scratch root and run it there.
 *
 * @param  array<string, string>  $files  repo-relative path => contents, created under the scratch root
 * @return array{0: int, 1: string}
 */
function lintInScratchRoot(array $files): array
{
    $root = dirname(__DIR__, 2);
    $scratch = sys_get_temp_dir().'/activity-catalogue-lint-'.Str::random(10);

    mkdir($scratch.'/bin', 0777, true);
    copy($root.'/'.CATALOGUE_LINT, $scratch.'/'.CATALOGUE_LINT);

    foreach ($files as $rel => $contents) {
        $dir = dirname($scratch.'/'.$rel);
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($scratch.'/'.$rel, $contents);
    }

    try {
        $output = [];
        $exit = 0;
        exec('php '.escapeshellarg($scratch.'/'.CATALOGUE_LINT).' 2>&1', $output, $exit);

        return [$exit, implode("\n", $output)];
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }
}

// ---------------------------------------------------------------------------
// R2 — a declared key nothing emits.
// ---------------------------------------------------------------------------

it('R2 — reports a declared key that no emitter writes, the defect commit 73108ea8 corrected', function () {
    // The exact transposition that shipped: the listener writes `failed_login`, the catalogue said
    // `login_failed`, and ~994 rows resolved to `info` with nothing red anywhere.
    [$exit, $output] = lintWithEditedFile(
        'config/activity_log_severity.php',
        "'auth.failed_login',",
        "'auth.login_failed',",
    );

    expect($exit)->toBe(1)
        ->and($output)->toContain('catalogue-key-not-emitted')
        ->and($output)->toContain('auth.login_failed')
        // The message must name the FILE too — a finding that names only a key is one nobody acts on.
        ->and($output)->toContain('config/activity_log_severity.php');

    // AND the same transposition reds from the other side, which is what makes it unmissable:
    // the real emitter loses its tier at the same moment the declared key loses its emitter.
    expect($output)->toContain('emitter-is-unclassified')
        ->and($output)->toContain('auth.failed_login')
        ->and($output)->toContain('app/Listeners/LogFailedLogin.php');
});

// ---------------------------------------------------------------------------
// R3 — an emitter nothing classifies, and an exemption with no reason.
// ---------------------------------------------------------------------------

it('R3 — reports an emitter that matches no tier and has no exemption', function () {
    [$exit, $output, $name] = lintWithPlantedEmitter(<<<'PHP'
<?php

namespace App;

class __CLASS_NAME__
{
    public function run(): void
    {
        activity('probe')
            ->event('probe_event')
            ->log('planted by ActivityCatalogueLintCoverageTest');
    }
}
PHP);

    expect($exit)->toBe(1)
        ->and($output)->toContain('emitter-is-unclassified')
        ->and($output)->toContain('probe.probe_event')
        ->and($output)->toContain($name);
});

it('R3 — reports an exemption whose reason is empty, which is the wallpaper one layer down', function () {
    [$exit, $output] = lintWithEditedFile(
        'config/activity_log_severity.php',
        "'guardian.attached' => 'a guardian was linked to a student; routine intake',",
        "'guardian.attached' => '',",
    );

    expect($exit)->toBe(1)
        ->and($output)->toContain('exemption-reason-empty')
        ->and($output)->toContain('guardian.attached')
        ->and($output)->toContain('config/activity_log_severity.php');
});

// ---------------------------------------------------------------------------
// R4 — the dead `protected static $logName`, and its shrink-lock.
// ---------------------------------------------------------------------------

it('R4 — reports a NEW model declaring the static $logName Spatie never reads', function () {
    [$exit, $output, $name] = lintWithPlantedEmitter(<<<'PHP'
<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;

class __CLASS_NAME__ extends Model
{
    use LogsActivity;

    protected static $logName = 'academics';
}
PHP);

    expect($exit)->toBe(1)
        ->and($output)->toContain('dead-static-log-name')
        ->and($output)->toContain($name);
});

it('R4 — reports a BASELINED model that has been fixed but left in the baseline', function () {
    // Shrink-lock. Without it a re-introduction passes silently under the stale entry.
    [$exit, $output] = lintWithEditedFile(
        'app/Models/Term.php',
        "    protected static \$logName = 'academics';",
        '    // removed by ActivityCatalogueLintCoverageTest',
    );

    expect($exit)->toBe(1)
        ->and($output)->toContain('fixed (good!)')
        ->and($output)->toContain('app/Models/Term.php')
        ->and($output)->toContain('Term');
});

// ---------------------------------------------------------------------------
// R6 — a non-constant event argument FAILS; it is not skipped.
// ---------------------------------------------------------------------------

it('R6 — reports a ->event() it cannot resolve, rather than skipping the site', function () {
    [$exit, $output, $name] = lintWithPlantedEmitter(<<<'PHP'
<?php

namespace App;

class __CLASS_NAME__
{
    public function run(string $whatever): void
    {
        activity('probe')
            ->event($whatever)
            ->log('planted by ActivityCatalogueLintCoverageTest');
    }
}
PHP);

    expect($exit)->toBe(1)
        ->and($output)->toContain('dynamic-event')
        ->and($output)->toContain($name);
});

it('R6 — accepts a class constant, so the escape hatch is not the only way through', function () {
    // The counterpart to the arm above. Without it, "dynamic-event fires" is equally consistent
    // with a rule that rejects EVERY event argument, which would be broken-closed.
    [$exit, $output, $name] = lintWithPlantedEmitter(<<<'PHP'
<?php

namespace App;

class __CLASS_NAME__
{
    public const PROBE = 'login';

    public function run(): void
    {
        activity('auth')
            ->event(self::PROBE)
            ->log('planted by ActivityCatalogueLintCoverageTest');
    }
}
PHP);

    // `auth.login` is classified `notice`, so a resolved constant produces NO finding at all.
    expect($output)->not->toContain('dynamic-event')
        ->and($output)->not->toContain($name)
        ->and($exit)->toBe(0);
});

// ---------------------------------------------------------------------------
// R5 — the lint fails when its own scan finds nothing. Never a green.
// ---------------------------------------------------------------------------

it('R5 — exits 2 and prints NO success line when the scan finds no files', function () {
    [$exit, $output] = lintInScratchRoot([]);

    expect($exit)->toBe(2)
        ->and($output)->toContain('COULD NOT DETERMINE')
        ->and($output)->toContain('no PHP files')
        // The assertion that matters: bin/ci-tsc-ratchet.php's defect is printing a SUCCESS line
        // over absent input. Exiting non-zero is not enough; it must not claim anything.
        ->and($output)->not->toContain('OK —');
});

it('R5 — exits 2 when files are present but nothing emits', function () {
    [$exit, $output] = lintInScratchRoot([
        'app/Plain.php' => "<?php\n\nnamespace App;\n\nclass Plain\n{\n    public function noop(): void {}\n}\n",
    ]);

    expect($exit)->toBe(2)
        ->and($output)->toContain('COULD NOT DETERMINE')
        ->and($output)->toContain('found NO emitters')
        ->and($output)->not->toContain('OK —');
});

it('R5 — exits 2 when the emitter count falls below the plausibility floor', function () {
    // One emitter is a scan that has broken, not a codebase that logs one thing.
    [$exit, $output] = lintInScratchRoot([
        'app/One.php' => "<?php\n\nnamespace App;\n\nclass One\n{\n    public function go(): void\n    {\n        activity('probe')->event('one')->log('x');\n    }\n}\n",
    ]);

    expect($exit)->toBe(2)
        ->and($output)->toContain('COULD NOT DETERMINE')
        ->and($output)->toContain('below the floor')
        ->and($output)->not->toContain('OK —');
});

it('R5 — exits 2 when a config file is not the shape it expects', function () {
    [$exit, $output] = lintWithEditedFile(
        'config/activity_log_severity.php',
        "    'critical' => [",
        "    'kritical' => [",
    );

    expect($exit)->toBe(2)
        ->and($output)->toContain('COULD NOT DETERMINE')
        ->and($output)->toContain('activity_log_severity.php')
        ->and($output)->not->toContain('OK —');
});

// ---------------------------------------------------------------------------
// The known negative. Read this one first if the file goes red as a block.
// ---------------------------------------------------------------------------

it('is GREEN over the unmutated tree — the arm a broken-closed gate cannot pass', function () {
    [$exit, $output] = runCatalogueLint();

    expect($exit)->toBe(0, "activity-catalogue-lint is red on a clean tree:\n".$output)
        ->and($output)->toContain('OK —')
        // Non-vacuity: a green from a scan that found nothing is the failure mode R5 exists for,
        // and it would satisfy every assertion above this line.
        ->and($output)->toMatch('/OK — \d+ emitted key\(s\) across \d+ file\(s\)/');
});
