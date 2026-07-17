#!/usr/bin/env php
<?php

/**
 * S7 runtime-zero gate — the permanent regression protection for the removal of
 * the two legacy School-access sources: `users.school_id` and the `school_user`
 * pivot (Constitution §13 / §7.1; ADR 0042). Access must derive solely from
 * `model_has_roles` (the single source). This lint scans app/ for EXECUTABLE
 * references to either legacy source; the existing ones are frozen in a committed
 * baseline and CI fails only when a NEW one is introduced.
 *
 * The baseline is expected to be NON-EMPTY today and to ratchet to ZERO as the
 * S7 repoints land, at which point the schema-drop migration removes the columns
 * and this baseline reaches 0. **Expiry: the S7 `users.school_id` + `school_user`
 * column drop.** When the baseline hits 0, the gate becomes an absolute "never
 * again" guard.
 *
 * Coverage note: this catches READS of `->school_id` on a user variable, the
 * `school_user` table name, and raw `users.school_id`. The two remaining WRITES
 * (SuperAdmin\AdminController forceFill, TeacherService User::create) are tracked
 * by boundary-lint (`school-id-fallback-context` / maintenance-write) and the S7
 * dependency graph in docs/roadmap.md — see there for the full accounting.
 *
 * Usage:
 *   php bin/ci-runtime-zero-lint.php            # check (CI): exit 1 on a NEW ref
 *   php bin/ci-runtime-zero-lint.php generate   # (re)write the baseline
 */
$root = dirname(__DIR__);
$appDir = $root.'/app';
$baselinePath = $root.'/runtime-zero-baseline.txt';
$mode = $argv[1] ?? 'check';

// Executable (non-comment) references to a legacy access source. Kept precise so
// legitimate other-model `->school_id` (Student, Curriculum, …) is not flagged —
// only a USER's school_id and the school_user pivot are the removal targets.
$patterns = [
    '/school_user/',                             // the pivot table (any usage)
    '/->user\(\)->school_id/',                   // auth()/request()->user()->school_id
    '/\$user->school_id/',                        // $user->school_id read/compare
    '/\busers\.school_id\b/',                     // raw SQL / column reference
];

// app/Models/User.php only: inside the User model, `$this->school_id` IS
// users.school_id (in other BelongsToSchool models it is legitimately their own).
$userModelPattern = '/\$this->school_id/';

$isComment = '/^\s*(\/\/|\*|\/\*)/';

$found = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appDir, FilesystemIterator::SKIP_DOTS));
foreach ($rii as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $rel = ltrim(str_replace($root, '', $file->getPathname()), '/');
    $isUserModel = str_ends_with($rel, 'app/Models/User.php');
    foreach (file($file->getPathname(), FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match($isComment, $line)) {
            continue; // full-line comments are not executable references
        }
        // Strip a trailing line comment so a mention in a `// …` tail does not
        // count as an executable reference (e.g. an explanatory note).
        $code = preg_replace('/\/\/.*$/', '', $line);

        $active = $patterns;
        if ($isUserModel) {
            $active[] = $userModelPattern;
        }

        foreach ($active as $p) {
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
    fwrite(STDERR, 'runtime-zero-lint: wrote '.count($found)." baseline entries to runtime-zero-baseline.txt\n");
    exit(0);
}

$baseline = is_file($baselinePath)
    ? array_values(array_filter(array_map('rtrim', file($baselinePath)), fn ($l) => $l !== ''))
    : [];

$new = array_values(array_diff($found, $baseline));
$fixed = array_values(array_diff($baseline, $found));

if ($fixed) {
    fwrite(STDERR, "\nruntime-zero-lint: ".count($fixed)." baselined legacy-access reference(s) removed (good) — update runtime-zero-baseline.txt:\n");
    foreach ($fixed as $f) {
        fwrite(STDERR, '  - '.str_replace("\t", '  ', $f)."\n");
    }
}

if ($new) {
    fwrite(STDERR, "\nruntime-zero-lint: ".count($new)." NEW reference(s) to a legacy access source (users.school_id / school_user). Access derives from model_has_roles only:\n");
    foreach ($new as $n) {
        fwrite(STDERR, '  '."\u{2717}".' '.str_replace("\t", '  ', $n)."\n");
    }
    exit(1);
}

$n = count($found);
$msg = $n === 0
    ? 'runtime-zero-lint: OK — ZERO legacy access references (S7 complete; safe to drop the columns).'
    : "runtime-zero-lint: OK — no NEW legacy access references ({$n} known, ratcheting to 0 at the S7 column drop).";
fwrite(STDERR, $msg."\n");
exit(0);
