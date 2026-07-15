#!/usr/bin/env php
<?php

/**
 * TypeScript error-count ratchet.
 *
 * The project has a backlog of pre-existing `tsc --noEmit` errors that are paid
 * down over time. To keep CI a real gate without fixing them all at once, we
 * freeze the current count in a committed baseline (tsc-baseline) and fail CI
 * when the count *increases* — i.e. a PR adds new type errors. The trend, not
 * the absolute number, is what this guards.
 *
 * Usage:
 *   pnpm run types:check > tsc-output.txt 2>&1 || true
 *   php bin/ci-tsc-ratchet.php tsc-output.txt            # check (CI): exit 1 if count increased
 *   php bin/ci-tsc-ratchet.php tsc-output.txt generate   # (re)write the baseline
 */
$outputPath = $argv[1] ?? 'php://stdin';
$mode = $argv[2] ?? 'check';
$baselinePath = dirname(__DIR__).'/tsc-baseline';

$output = @file_get_contents($outputPath);
if ($output === false) {
    fwrite(STDERR, "tsc-ratchet: cannot read tsc output at {$outputPath}\n");
    exit(2);
}

$count = preg_match_all('/error TS\d+:/', $output);

if ($mode === 'generate') {
    file_put_contents($baselinePath, $count."\n");
    fwrite(STDERR, "tsc-ratchet: wrote baseline {$count}\n");
    exit(0);
}

$baseline = is_file($baselinePath) ? (int) trim((string) file_get_contents($baselinePath)) : 0;

if ($count > $baseline) {
    fwrite(STDERR, "\ntsc-ratchet: type errors INCREASED — {$count} > baseline {$baseline}.\n");
    fwrite(STDERR, "Fix the new type error(s). If the increase is genuinely intended, run\n");
    fwrite(STDERR, "  php bin/ci-tsc-ratchet.php tsc-output.txt generate\nand commit tsc-baseline.\n");
    exit(1);
}

if ($count < $baseline) {
    fwrite(STDERR, "\ntsc-ratchet: type errors decreased ({$count} < {$baseline}) — please lower tsc-baseline to {$count}.\n");
}

fwrite(STDERR, "tsc-ratchet: OK ({$count} <= baseline {$baseline}).\n");
exit(0);
