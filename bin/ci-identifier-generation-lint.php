#!/usr/bin/env php
<?php

/**
 * Identifier-generation bypass guard (1.4b). Student.admission_number and
 * Teacher.staff_number are generated in the models' `creating` hook and wrapped
 * atomically by the trait `save()` override. Any write path that skips the model
 * event pipeline — a raw builder insert, or a quiet create that suppresses events
 * — produces a NULL identifier (the columns are nullable) or an unguarded manual
 * one. This is exactly the class of "you must use save()" convention that has
 * failed repeatedly in this codebase (halting-event uuid setters, null-team
 * assignRole), so it is ENFORCED, not documented.
 *
 * Forbidden in app/ (fails CI; the composite UNIQUE index still prevents true
 * duplicates, but nothing else prevents a null identifier):
 *   - DB::table('students'|'teachers')->insert / insertOrIgnore / upsert
 *   - Student|Teacher :: insert / insertOrIgnore / upsert / createQuietly
 *
 * (Instance `->saveQuietly()` on a Student/Teacher variable is not greppable with
 * confidence and is not enforced here; see docs/roadmap.md §"Sequences failure
 * modes" for the residual note. Legitimate needs use a baselined exception.)
 *
 * Usage:
 *   php bin/ci-identifier-generation-lint.php            # check (CI)
 *   php bin/ci-identifier-generation-lint.php generate   # (re)write the baseline
 */
$root = dirname(__DIR__);
$appDir = $root.'/app';
$baselinePath = $root.'/identifier-generation-baseline.txt';
$mode = $argv[1] ?? 'check';

$patterns = [
    "/DB::table\(['\"](students|teachers)['\"]\)\s*->\s*(insert|insertOrIgnore|upsert)\b/",
    "/\b(Student|Teacher)::(insert|insertOrIgnore|upsert|createQuietly)\s*\(/",
];
$isComment = '/^\s*(\/\/|\*|\/\*)/';

$found = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appDir, FilesystemIterator::SKIP_DOTS));
foreach ($rii as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $rel = ltrim(str_replace($root, '', $file->getPathname()), '/');
    foreach (file($file->getPathname(), FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match($isComment, $line)) {
            continue;
        }
        $code = preg_replace('/\/\/.*$/', '', $line);
        foreach ($patterns as $p) {
            if (preg_match($p, $code)) {
                $found[$rel."\t".trim($line)] = true;
                break;
            }
        }
    }
}
$found = array_keys($found);
sort($found);

if ($mode === 'generate') {
    file_put_contents($baselinePath, $found ? implode("\n", $found)."\n" : '');
    fwrite(STDERR, 'identifier-generation-lint: wrote '.count($found)." baseline entries.\n");
    exit(0);
}

$baseline = is_file($baselinePath)
    ? array_values(array_filter(array_map('rtrim', file($baselinePath)), fn ($l) => $l !== ''))
    : [];

$new = array_values(array_diff($found, $baseline));
$fixed = array_values(array_diff($baseline, $found));

if ($new) {
    fwrite(STDERR, "\nidentifier-generation-lint: ".count($new)." write path(s) bypass identifier generation on Student/Teacher (raw/quiet create → NULL identifier). Create through the model (Model::create / save), never a raw insert:\n");
    foreach ($new as $n) {
        fwrite(STDERR, '  '."\u{2717}".' '.str_replace("\t", '  ', $n)."\n");
    }
    exit(1);
}

// SHRINK-LOCK. This lint had NO stale-entry handling at all: a baselined bypass later
// fixed left its entry in place forever and the lint still reported "OK". Audited and
// fixed 2026-07-20 — a planted stale entry was silently ignored and it exited 0.
if ($fixed) {
    fwrite(STDERR, "\nidentifier-generation-lint: ".count($fixed)." baselined bypass(es) removed (good!) — lock it in by removing them from identifier-generation-baseline.txt:\n");
    foreach ($fixed as $f) {
        fwrite(STDERR, '  - '.str_replace("\t", '  ', $f)."\n");
    }
    fwrite(STDERR, "  regenerate: php bin/ci-identifier-generation-lint.php generate\n");
    exit(1);
}

fwrite(STDERR, 'identifier-generation-lint: OK — no bypass of admission/staff number generation ('.count($found)." baselined).\n");
exit(0);
