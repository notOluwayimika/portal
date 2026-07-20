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

// A commented line ( // ... ) that contains a *call* to a guard construct.
//
// NOTE ON SCOPE — this regex is NOT authorization-only, and that matters. It matches
// any commented `abort_unless(` / `abort_if(` / `abort(`, so commented-out
// PRECONDITION guards are caught too, not just ability checks. (An earlier report
// claimed preconditions were a blind spot; they are not — the reason the
// resetPassword precondition survived was that the BASELINE grandfathered it, which
// the shrink-lock below now stops from becoming permanent.)
//
// Deliberately NOT broadened further to commented `throw`/`if` control flow: guard
// helpers are a narrow, high-signal shape, whereas commented conditionals are mostly
// dead code and debugging leftovers. A general "no commented control flow" lint would
// drown the real signal in false positives, and a lint people learn to ignore is worse
// than no lint. Bare `abort(` is included because a commented one is essentially
// always a disabled guard.
//
// Requiring the "(" avoids flagging prose that merely mentions Gate::/authorize/can
// while still catching commented-out checks, which are always calls.
$authz = '/^\s*\/\/.*(->can\(|->cannot\(|->authorize\(|\$this->authorize\(|Gate::\w+\(|abort_unless\(|abort_if\(|abort\()/';

$found = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appDir, FilesystemIterator::SKIP_DOTS));
foreach ($rii as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $rel = ltrim(str_replace($root, '', $file->getPathname()), '/');
    $seen = [];
    foreach (file($file->getPathname(), FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match($authz, $line)) {
            // Keyed by file + text + OCCURRENCE ORDINAL — not file + text alone.
            //
            // Keying on the text alone silently DEDUPED duplicates: five commented
            // guards in CurriculumSubjectController collapsed to three baseline
            // entries, because `// abort_unless($this->isReviewer($user), 403);`
            // appeared twice and `// abort_unless(` twice. That was not merely an
            // undercount, it was a BYPASS: commenting out a NEW guard whose text
            // matched an already-baselined line produced no new entry, so the lint
            // passed on a brand-new disabled check.
            //
            // The ordinal (#1, #2, …) distinguishes duplicates while staying stable
            // under edits that move code around — a line NUMBER would make every
            // unrelated insertion above a guard look like a new violation.
            $text = trim($line);
            $n = ($seen[$text] = ($seen[$text] ?? 0) + 1);
            $found[$rel."\t".$text.($n > 1 ? "\t#".$n : '')] = true;
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

if ($new) {
    fwrite(STDERR, "\nauthz-lint: ".count($new)." NEW commented-out authorization check(s) — do not comment out authorization:\n");
    foreach ($new as $n) {
        fwrite(STDERR, '  '."\u{2717}".' '.str_replace("\t", '  ', $n)."\n");
    }
    exit(1);
}

// SHRINK-LOCK. This previously only WARNED and still exited 0, so the baseline
// could sit above the true count indefinitely — slack a future regression can hide
// in, and exactly the pattern that let the tsc baseline absorb a live bug. It now
// FAILS, matching the tests and tsc ratchets and making docs/testing.md's claim
// that "every ratchet ENFORCES it" true of this one too.
if ($fixed) {
    fwrite(STDERR, "\nauthz-lint: ".count($fixed)." baselined commented-check(s) removed (good!) — lock it in by removing them from authz-lint-baseline.txt:\n");
    foreach ($fixed as $f) {
        fwrite(STDERR, '  - '.str_replace("\t", '  ', $f)."\n");
    }
    fwrite(STDERR, "  regenerate: php bin/ci-authz-lint.php generate\n");
    exit(1);
}

fwrite(STDERR, 'authz-lint: OK — no new commented-out authorization checks ('.count($found)." known).\n");
exit(0);
