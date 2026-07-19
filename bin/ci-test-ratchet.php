#!/usr/bin/env php
<?php

/**
 * Test-failure ratchet.
 *
 * The suite is not yet fully green (pre-existing authz/isolation/validation
 * debt is fixed incrementally by later Finance Phase 1 slices). To keep CI a
 * real gate in the meantime, we freeze the currently-failing tests in a
 * committed baseline and fail CI only when a *new* failure appears — i.e. a
 * regression. As slices repair the debt, entries are removed and the baseline
 * shrinks toward zero.
 *
 * Usage:
 *   php bin/ci-test-ratchet.php <junit.xml>            # check (CI): exit 1 on a new failure
 *   php bin/ci-test-ratchet.php <junit.xml> generate   # (re)write the baseline
 */
$junitPath = $argv[1] ?? 'junit.xml';
$mode = $argv[2] ?? 'check';
$baselinePath = dirname(__DIR__).'/tests/ratchet-baseline.txt';

if (! is_file($junitPath)) {
    fwrite(STDERR, "ratchet: JUnit report not found at {$junitPath}\n");
    exit(2);
}

$xml = @simplexml_load_file($junitPath);
if ($xml === false) {
    fwrite(STDERR, "ratchet: could not parse JUnit report at {$junitPath}\n");
    exit(2);
}

$failing = [];
foreach ($xml->xpath('//testcase') as $tc) {
    if (isset($tc->failure) || isset($tc->error)) {
        $id = trim((string) $tc['file']);
        if ($id !== '') {
            $failing[$id] = true;
        }
    }
}
$failing = array_keys($failing);
sort($failing);

if ($mode === 'generate') {
    file_put_contents($baselinePath, $failing ? implode("\n", $failing)."\n" : '');
    fwrite(STDERR, 'ratchet: wrote '.count($failing)." baseline entries to tests/ratchet-baseline.txt\n");
    exit(0);
}

$baseline = is_file($baselinePath)
    ? array_values(array_filter(array_map('trim', file($baselinePath))))
    : [];

$new = array_values(array_diff($failing, $baseline));
$fixed = array_values(array_diff($baseline, $failing));

// Baselines only SHRINK — enforced, not remembered. A test that starts passing but
// stays baselined leaves slack: the suite could later regress back to failing and
// this gate would stay green. Matches ci-authz-lint / ci-boundary-lint /
// ci-runtime-zero-lint, which all exit 1 when a baselined entry is fixed.
if ($fixed) {
    fwrite(STDERR, "\nratchet: ".count($fixed)." baselined test(s) now PASS (good!) — lock it in by removing them from tests/ratchet-baseline.txt:\n");
    foreach ($fixed as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

if ($new) {
    fwrite(STDERR, "\nratchet: ".count($new)." NEW test failure(s) not in the baseline (regression):\n");
    foreach ($new as $n) {
        fwrite(STDERR, "  \u{2717} {$n}\n");
    }
    fwrite(STDERR, "\nFix the regression, or — if the failure is intentional — add it to tests/ratchet-baseline.txt.\n");
    exit(1);
}

fwrite(STDERR, "\nratchet: OK — no new failures beyond the baseline (".count($failing)." known-failing).\n");
exit(0);
