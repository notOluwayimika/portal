#!/usr/bin/env php
<?php

/**
 * TypeScript error-count ratchet.
 *
 * The project has a backlog of pre-existing `tsc --noEmit` errors that are paid
 * down over time. To keep the gate real without fixing them all at once, we
 * freeze the current count in a committed baseline (tsc-baseline) and fail
 * when the count *increases* — i.e. a change adds new type errors. The trend, not
 * the absolute number, is what this guards.
 *
 * ── WHY IT DEMANDS `--tsc-exit`, AND WHY THE OUTPUT FILE ALONE CANNOT BE TRUSTED ────────────────
 *
 * A CLEAN `tsc --noEmit` AND A `tsc` THAT NEVER RAN PRODUCE THE SAME BYTES: NONE.
 * Measured 2026-09-04 — a clean run exits 0 and writes 0 bytes; a run with errors exits 2 and
 * writes the errors. So counting `error TS` in the file cannot separate "no type errors" from
 * "typescript was not installed", "the script name was wrong", or "the shell redirect failed".
 *
 * Before this flag existed, both produced `type errors DECREASED — 0 < baseline 42 (good!)` and
 * told the operator to run `generate`. DOING THAT WROTE `baseline 0` AND ENDED THE RATCHET —
 * measured, exit 0, no complaint. The failure was a correct exit code wearing a congratulation,
 * and the remedy it printed was the thing that broke it. A tsc CRASH message produced the byte-
 * identical verdict, so the state most in need of an alarm was the one that read as success.
 *
 * THE EVIDENCE THEREFORE CANNOT COME FROM THE FILE; IT COMES FROM THE EXIT CODE, which the caller
 * must pass. This is the same property `bin/ci-test-ratchet.php` gets for free and by a different
 * route: its input is XML, so a tool that never ran cannot produce something that parses. tsc's
 * output has no envelope, so the corroboration is supplied rather than inferred.
 *
 * ── THE FIVE STATES ─────────────────────────────────────────────────────────────────────────────
 *
 *   tsc exit 0, count 0          a corroborated clean tree — OK, and it SAYS it is corroborated
 *   tsc exit non-zero, count > 0 the ordinary case — compared against the baseline
 *   tsc exit non-zero, count 0   THE TOOL FAILED. Not zero errors. Refused, exit 2.
 *   tsc exit 0, count > 0        incoherent — tsc exits non-zero whenever it reports an error, so
 *                                the caller is not passing the real status. Refused, exit 2.
 *   no --tsc-exit                cannot be determined. Refused, exit 2, naming the CALLER as the
 *                                thing to fix, because the ratchet cannot fix its own input.
 *
 * Exit codes follow the house convention: 0 pass · 1 the ratchet was violated · 2 could not
 * determine. `bin/quality:498 (check)` states it for the activity-catalogue
 * lint and names this script's old shape as the counter-example it was written against.
 *
 * Usage:
 *   pnpm run types:check > tsc-output.txt 2>&1; tsc_exit=$?
 *   php bin/ci-tsc-ratchet.php tsc-output.txt --tsc-exit=$tsc_exit
 *   php bin/ci-tsc-ratchet.php tsc-output.txt --tsc-exit=$tsc_exit generate   # (re)write the baseline
 *
 * NOTE THE ABSENCE OF `|| true`. It used to be in this block, and it is what discarded the status
 * the ratchet now requires. `bin/quality` runs under `set -uo pipefail` and NOT `set -e`, so it
 * was never needed to prevent an abort — it only ever destroyed evidence.
 */
$tscExit = null;
$positional = [];

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--tsc-exit=(\d+)$/', $arg, $m)) {
        $tscExit = (int) $m[1];

        continue;
    }

    $positional[] = $arg;
}

$outputPath = $positional[0] ?? 'php://stdin';
$mode = $positional[1] ?? 'check';
$baselinePath = dirname(__DIR__).'/tsc-baseline';

$output = @file_get_contents($outputPath);
if ($output === false) {
    fwrite(STDERR, "tsc-ratchet: cannot read tsc output at {$outputPath}\n");
    exit(2);
}

$count = preg_match_all('/error TS\d+:/', $output);

// ── CORROBORATION, BEFORE ANY VERDICT AND BEFORE `generate` ─────────────────────────────────────
//
// This runs ahead of both paths deliberately. `generate` is the destructive one — it WRITES the
// baseline — and it is the path the old failure message told the operator to take. It must be
// impossible to reach on evidence that cannot support it.
if ($tscExit === null) {
    fwrite(STDERR, "\ntsc-ratchet: COULD NOT DETERMINE — no --tsc-exit was supplied.\n");
    fwrite(STDERR, "A clean tsc run and a tsc that never ran both produce an empty file, so this\n");
    fwrite(STDERR, "script cannot tell them apart from the output alone. THE CALLER MUST PASS tsc's\n");
    fwrite(STDERR, "exit status:\n");
    fwrite(STDERR, "  pnpm run types:check > tsc-output.txt 2>&1; tsc_exit=\$?\n");
    fwrite(STDERR, "  php bin/ci-tsc-ratchet.php tsc-output.txt --tsc-exit=\$tsc_exit\n");
    exit(2);
}

if ($tscExit !== 0 && $count === 0) {
    fwrite(STDERR, "\ntsc-ratchet: COULD NOT DETERMINE — tsc exited {$tscExit} and reported no type errors.\n");
    fwrite(STDERR, "THE TOOL FAILED; THIS IS NOT A CLEAN TREE. A crash, a missing typescript, a wrong\n");
    fwrite(STDERR, "script name and a broken redirect all land here, and every one of them used to read\n");
    fwrite(STDERR, "as zero errors. Fix the tsc invocation, then re-run — the baseline is untouched.\n");
    fwrite(STDERR, "First line of the output was:\n  ".(trim(strtok($output, "\n")) ?: '(the file was empty)')."\n");
    exit(2);
}

if ($tscExit === 0 && $count > 0) {
    fwrite(STDERR, "\ntsc-ratchet: COULD NOT DETERMINE — tsc exited 0 while the output holds {$count} type error(s).\n");
    fwrite(STDERR, "tsc exits non-zero whenever it reports an error, so these two disagree and the\n");
    fwrite(STDERR, "status is not the one tsc returned. Pass the real \$? rather than a literal 0.\n");
    exit(2);
}

if ($mode === 'generate') {
    file_put_contents($baselinePath, $count."\n");
    fwrite(STDERR, "tsc-ratchet: wrote baseline {$count} (corroborated: tsc exited {$tscExit})\n");
    exit(0);
}

$baseline = is_file($baselinePath) ? (int) trim((string) file_get_contents($baselinePath)) : 0;

if ($count > $baseline) {
    fwrite(STDERR, "\ntsc-ratchet: type errors INCREASED — {$count} > baseline {$baseline}.\n");
    fwrite(STDERR, "Fix the new type error(s). If the increase is genuinely intended, run\n");
    fwrite(STDERR, "  php bin/ci-tsc-ratchet.php tsc-output.txt --tsc-exit=\$tsc_exit generate\nand commit tsc-baseline.\n");
    exit(1);
}

// A DECREASE STILL FAILS, so the floor cannot silently drift loose — a stale-high baseline is a
// place for new errors to hide in. Same shape as ci-citation-lint and ci-runtime-zero-lint, which
// both exit 1 when a baselined entry is fixed.
if ($count < $baseline) {
    fwrite(STDERR, "\ntsc-ratchet: type errors decreased — {$count} < baseline {$baseline}, and tsc exited\n");
    fwrite(STDERR, "{$tscExit}, so this is a real improvement rather than a tsc that failed to run.\n");
    fwrite(STDERR, "Lock it in so the floor cannot drift back up:\n");
    fwrite(STDERR, "  php bin/ci-tsc-ratchet.php tsc-output.txt --tsc-exit=\$tsc_exit generate\n");
    fwrite(STDERR, "and commit tsc-baseline ({$baseline} -> {$count}).\n");
    exit(1);
}

fwrite(STDERR, $count === 0
    ? "tsc-ratchet: OK (0 == baseline 0) — corroborated clean, tsc exited 0.\n"
    : "tsc-ratchet: OK ({$count} == baseline {$baseline}).\n");
exit(0);
