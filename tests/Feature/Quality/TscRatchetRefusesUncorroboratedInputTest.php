<?php

/*
 * Coverage for bin/ci-tsc-ratchet.php — specifically, that it REFUSES an input that proves nothing.
 *
 * WHAT THIS PINS, AND WHY IT IS NOT THE HAPPY PATH. A CLEAN `tsc --noEmit` AND A `tsc` THAT NEVER
 * RAN PRODUCE THE SAME BYTES: NONE. Measured 2026-09-04 — a clean run exits 0 and writes 0 bytes,
 * a run with errors exits 2 and writes them. So no amount of reading the OUTPUT FILE can separate
 * "no type errors" from "typescript is not installed", and before `--tsc-exit` existed both landed
 * on the same verdict:
 *
 *     tsc-ratchet: type errors DECREASED — 0 < baseline 42 (good!).
 *     …run `generate` and commit tsc-baseline (42 -> 0).
 *
 * AND DOING WHAT THAT MESSAGE SAID WROTE `baseline 0`, ending the ratchet — measured, exit 0, no
 * complaint. A tsc CRASH message produced the byte-identical verdict, so the state most in need of
 * an alarm was the one that read as success. This file exists so the script cannot regress to that
 * shape, and it asserts the REFUSALS rather than the pass, because the refusals are what was
 * missing.
 *
 * ── WHY THE ARMS ASSERT THE MESSAGE AND NOT ONLY THE CODE ──────────────────────────────────────
 *
 * The script has THREE distinct "could not determine" causes and all three exit 2, so an arm
 * checking only the code would pass while the script reported the wrong one — the same reasoning
 * LandedCheckCoverageTest's header gives for its four failure modes.
 *
 * ── AND THE DESTRUCTIVE ARM CHECKS THE FILE, NOT THE EXIT CODE ─────────────────────────────────
 *
 * `generate` WRITES the baseline. An arm asserting only `exit 2` would pass against a script that
 * refused *after* writing. The two `generate` arms below read `tsc-baseline` off disk before and
 * after and compare the CONTENT, because the defect being pinned is a write.
 *
 * NO REAL tsc IS RUN. Every arm hands the script a fixture file, which is the whole point: the
 * script's contract is about what it does with the input it is given.
 */

use Illuminate\Support\Str;

/** Run the real script with the real baseline, and return [exit code, stderr]. */
function tscRatchet(string $outputPath, array $args = []): array
{
    $cmd = 'php '.escapeshellarg(base_path('bin/ci-tsc-ratchet.php')).' '.escapeshellarg($outputPath);

    foreach ($args as $arg) {
        $cmd .= ' '.escapeshellarg($arg);
    }

    $stderr = base_path('storage/framework/testing/tsc-ratchet-'.Str::random(8).'.err');
    exec($cmd.' 2>'.escapeshellarg($stderr).' >/dev/null', $ignored, $code);
    $message = is_file($stderr) ? (string) file_get_contents($stderr) : '';
    @unlink($stderr);

    return [$code, $message];
}

/** Write $contents to a throwaway file and return its path. */
function tscFixture(string $contents): string
{
    $path = sys_get_temp_dir().'/tsc_ratchet_'.Str::random(12).'.txt';
    file_put_contents($path, $contents);

    return $path;
}

it('refuses an empty file when no --tsc-exit is supplied, and names the CALLER as the fix', function () {
    [$code, $message] = tscRatchet(tscFixture(''));

    expect($code)->toBe(2)
        ->and($message)->toContain('COULD NOT DETERMINE')
        ->and($message)->toContain('no --tsc-exit was supplied')
        // The instrument cannot fix its own input, so the message must point at the invocation.
        ->and($message)->toContain('THE CALLER MUST PASS');

    // AND IT MUST NOT CONGRATULATE. This is the exact string that used to be printed here, and it
    // is the half that got followed.
    expect($message)->not->toContain('good!');
});

it('refuses when tsc exited non-zero and the output holds no type errors — the crash case', function () {
    // THE ARM THAT MATTERS MOST: before --tsc-exit this state was indistinguishable from success.
    $crash = tscFixture("error: Cannot find module 'typescript'\nRequire stack:\n- /usr/local/bin/tsc\n");

    [$code, $message] = tscRatchet($crash, ['--tsc-exit=2']);

    expect($code)->toBe(2)
        ->and($message)->toContain('COULD NOT DETERMINE')
        ->and($message)->toContain('THE TOOL FAILED')
        // It says the baseline is safe, because the operator's next instinct is to "fix" it.
        ->and($message)->toContain('the baseline is untouched')
        ->and($message)->not->toContain('good!');

    // An EMPTY file with the same status is the same refusal — a crash that wrote nothing at all.
    [$emptyCode, $emptyMessage] = tscRatchet(tscFixture(''), ['--tsc-exit=2']);
    expect($emptyCode)->toBe(2)->and($emptyMessage)->toContain('THE TOOL FAILED');
});

it('refuses a status that disagrees with the output it is handed', function () {
    // tsc exits non-zero whenever it reports an error, so exit 0 over real errors means the caller
    // passed a literal rather than $?. Refused, because that is this defect wearing a flag.
    [$code, $message] = tscRatchet(
        tscFixture("resources/js/a.ts(1,1): error TS2322: Type 'string' is not assignable.\n"),
        ['--tsc-exit=0'],
    );

    expect($code)->toBe(2)
        ->and($message)->toContain('COULD NOT DETERMINE')
        ->and($message)->toContain('Pass the real $? rather than a literal 0');
});

it('GENERATE refuses uncorroborated input, and the baseline file is UNCHANGED ON DISK', function () {
    // THE PATH THAT ENDED THE RATCHET WHEN IT WAS MEASURED. Asserted on the FILE, not the exit
    // code: a script that refused after writing would pass an exit-code-only arm.
    $baselinePath = base_path('tsc-baseline');
    $before = file_get_contents($baselinePath);

    expect($before)->not->toBe('');

    foreach ([[], ['--tsc-exit=2']] as $args) {
        [$code, $message] = tscRatchet(tscFixture(''), [...$args, 'generate']);

        expect($code)->toBe(2)
            ->and($message)->toContain('COULD NOT DETERMINE');

        expect(file_get_contents($baselinePath))->toBe(
            $before,
            'ci-tsc-ratchet.php WROTE tsc-baseline on input it refused. The refusal must come '
            .'before the write, or the destructive path is still reachable.'
        );
    }
});

it('accepts a corroborated run, and still compares against the baseline', function () {
    // THE ACCEPTING SIDE, or every arm above is satisfied by a script that refuses everything —
    // "a gate that cannot recognise success is as broken as one that cannot recognise absence".
    $baseline = (int) trim((string) file_get_contents(base_path('tsc-baseline')));

    $atBaseline = tscFixture(str_repeat("a.ts(1,1): error TS2322: nope.\n", $baseline));
    [$code, $message] = tscRatchet($atBaseline, ['--tsc-exit=2']);

    expect($code)->toBe(0)
        ->and($message)->toContain("OK ({$baseline} == baseline {$baseline})");

    // And one more error is still refused, so the ratchet itself is intact rather than disarmed by
    // the corroboration check in front of it.
    $overBaseline = tscFixture(str_repeat("a.ts(1,1): error TS2322: nope.\n", $baseline + 1));
    [$overCode, $overMessage] = tscRatchet($overBaseline, ['--tsc-exit=2']);

    expect($overCode)->toBe(1)
        ->and($overMessage)->toContain('INCREASED');
});

it('the decrease message no longer congratulates on evidence that cannot support it', function () {
    // M2's half of the fix. A decrease still exits 1 — a stale-high baseline is where new errors
    // hide — but the sentence now states WHY it is believable, and `(good!)` is gone.
    [$code, $message] = tscRatchet(tscFixture(''), ['--tsc-exit=0']);

    expect($code)->toBe(1)
        ->and($message)->toContain('type errors decreased')
        ->and($message)->toContain('tsc exited')
        ->and($message)->toContain('rather than a tsc that failed to run')
        ->and($message)->not->toContain('good!');
});

it('bin/quality passes the status, so the gate is actually called under the new convention', function () {
    // A CALLING-CONVENTION CHANGE IS TWO FILES OR IT IS NOTHING. If bin/quality still used
    // `|| true`, every arm above would pass while the real gate refused on every run.
    $quality = (string) file_get_contents(base_path('bin/quality'));

    expect($quality)->toContain('tsc_exit=$?')
        ->and($quality)->toContain('--tsc-exit=$tsc_exit');

    // And the line that destroyed the evidence is gone from the tsc step.
    expect($quality)->not->toContain('pnpm run types:check >/tmp/quality-tsc.txt 2>&1 || true');
});
