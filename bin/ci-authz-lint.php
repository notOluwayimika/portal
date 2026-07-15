#!/usr/bin/env php
<?php

/**
 * Commented-out authorization guard (Constitution rule 15 — the most
 * load-bearing rule in the plan: this codebase acquired ~50 disabled
 * authorization checks because nothing stopped them).
 *
 * Scans app/ for commented-out authorization checks (abort_unless / ->can(
 * / $this->authorize / Gate:: …). The existing occurrences are frozen in a
 * committed baseline; CI fails only when a NEW one is introduced. As the
 * legacy checks are restored (slice 1.2c), their baseline entries are removed
 * and the count ratchets toward zero.
 *
 * Usage:
 *   php bin/ci-authz-lint.php            # check (CI): exit 1 on a new commented check
 *   php bin/ci-authz-lint.php generate   # (re)write the baseline
 */
$root = dirname(__DIR__);
$appDir = $root.'/app';
$baselinePath = $root.'/authz-lint-baseline.txt';
$mode = $argv[1] ?? 'check';

// A commented line ( // ... ) that contains a *call* to an authorization
// construct. Requiring the "(" avoids flagging prose that merely mentions
// Gate:: / authorize / can while still catching commented-out checks, which
// are always calls: abort_unless(...), $this->authorize(...), ->can(...), etc.
$authz = '/^\s*\/\/.*(->can\(|->cannot\(|->authorize\(|\$this->authorize\(|Gate::\w+\(|abort_unless\(|abort_if\()/';

$found = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appDir, FilesystemIterator::SKIP_DOTS));
foreach ($rii as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $rel = ltrim(str_replace($root, '', $file->getPathname()), '/');
    foreach (file($file->getPathname(), FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match($authz, $line)) {
            $found[$rel."\t".trim($line)] = true;
        }
    }
}
$found = array_keys($found);
sort($found);

if ($mode === 'generate') {
    file_put_contents($baselinePath, $found ? implode("\n", $found)."\n" : '');
    fwrite(STDERR, 'authz-lint: wrote '.count($found)." baseline entries to authz-lint-baseline.txt\n");
    exit(0);
}

$baseline = is_file($baselinePath)
    ? array_values(array_filter(array_map('rtrim', file($baselinePath)), fn ($l) => $l !== ''))
    : [];

$new = array_values(array_diff($found, $baseline));
$fixed = array_values(array_diff($baseline, $found));

if ($fixed) {
    fwrite(STDERR, "\nauthz-lint: ".count($fixed)." baselined commented-check(s) removed (good) — update authz-lint-baseline.txt:\n");
    foreach ($fixed as $f) {
        fwrite(STDERR, '  - '.str_replace("\t", '  ', $f)."\n");
    }
}

if ($new) {
    fwrite(STDERR, "\nauthz-lint: ".count($new)." NEW commented-out authorization check(s) — do not comment out authorization:\n");
    foreach ($new as $n) {
        fwrite(STDERR, '  '."\u{2717}".' '.str_replace("\t", '  ', $n)."\n");
    }
    exit(1);
}

fwrite(STDERR, 'authz-lint: OK — no new commented-out authorization checks ('.count($found)." known).\n");
exit(0);
