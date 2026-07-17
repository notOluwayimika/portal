#!/usr/bin/env php
<?php

/**
 * Boundary lint gates (§17.2 + §17.1 rule 4) — the grep-enforceable half of the
 * M1.5a enforcement floor. Companion to bin/ci-authz-lint.php (§17.2 rule 1,
 * commented-out authorization), which already exists and stays separate.
 *
 * Rules:
 *   school-id-fallback-literal  `?? $user->school_id` anywhere in app/
 *                               (Constitution 13). HARD — zero occurrences
 *                               remain, so there is no baseline for it.
 *   school-id-fallback-context  a `$user->school_id` / `->user()->school_id`
 *                               occurrence anywhere in app/ — Constitution 13 is
 *                               application-wide, so the guarded form of the
 *                               fallback is banned everywhere, not just in the
 *                               context primitives. Known temporary exceptions
 *                               are BASELINED (see boundary-lint-baseline.txt):
 *                               they expire when users.school_id is dropped
 *                               (§5.3, §7.1 — Phase 1C contract step).
 *   decimal-money-cast          `decimal:` cast on a money-named attribute
 *                               (Constitution 10). Deliberately app-wide, NOT
 *                               Finance-scoped: the known upcoming money columns
 *                               live on legacy models (e.g. Scholarship, Ph2).
 *                               The name pattern excludes the academic
 *                               score/weight decimals.
 *   fee-table-outside-finance   a `fee_*` table-name literal outside app/Finance
 *                               (Constitution 3): fee_ tables are Finance-owned.
 *                               Known temporary exception baselined —
 *                               ModuleClassificationService reads fee_ tables
 *                               until Ph2's FinanceModuleStatus contract
 *                               (ADR 0030) replaces it.
 *   force-create-finance-tests  `forceCreate(` in Finance tests — bypasses
 *                               MoneyCast. HARD (no Finance tests exist yet).
 *   finance-escape-hatches      withoutGlobalScope / withoutSchoolScope /
 *                               ->hasRole( / auth()->setUser / DB::table( inside
 *                               app/Finance/ (§17.1 rule 4 — method calls, which
 *                               arch tests cannot see). HARD; inert until
 *                               app/Finance exists, live from its first commit.
 *
 * Like the sibling ratchets, the baseline may only shrink: CI fails on any NEW
 * occurrence; removing a baselined line is reported as progress.
 *
 * Usage:
 *   php bin/ci-boundary-lint.php            # check (CI): exit 1 on new findings
 *   php bin/ci-boundary-lint.php generate   # (re)write the baseline
 */
$root = dirname(__DIR__);
$baselinePath = $root.'/boundary-lint-baseline.txt';
$mode = $argv[1] ?? 'check';

/** @return array<int, array{0: string, 1: string}> [[relativePath, line], ...] */
function phpLines(string $dir, string $root): array
{
    if (! is_dir($dir)) {
        return [];
    }
    $out = [];
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($rii as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $rel = ltrim(str_replace($root, '', $file->getPathname()), '/');
        foreach (file($file->getPathname(), FILE_IGNORE_NEW_LINES) as $line) {
            $out[] = [$rel, $line];
        }
    }

    return $out;
}

function isComment(string $line): bool
{
    $t = ltrim($line);

    return str_starts_with($t, '//') || str_starts_with($t, '*') || str_starts_with($t, '/*') || str_starts_with($t, '#');
}

$app = phpLines($root.'/app', $root);
$tests = phpLines($root.'/tests', $root);

$found = [];
$add = function (string $rule, string $rel, string $line) use (&$found) {
    $found[$rule."\t".$rel."\t".trim($line)] = true;
};

foreach ($app as [$rel, $line]) {
    if (isComment($line)) {
        continue;
    }

    // school-id-fallback-literal — Constitution 13, anywhere in app/.
    if (str_contains($line, '?? $user->school_id') || str_contains($line, '??$user->school_id')) {
        $add('school-id-fallback-literal', $rel, $line);
    }

    // school-id-fallback-context — guarded fallback reads, app-wide (Constitution 13).
    if (preg_match('/(\$user->school_id|->user\(\)->school_id)/', $line)) {
        $add('school-id-fallback-context', $rel, $line);
    }

    // decimal-money-cast — Constitution 10, app-wide by design.
    if (preg_match('/[\'"]\w*(amount|fee|price|balance|kobo|minor|money|debit|credit)\w*[\'"]\s*=>\s*[\'"]decimal/i', $line)) {
        $add('decimal-money-cast', $rel, $line);
    }

    // fee-table-outside-finance — Constitution 3.
    if (! str_starts_with($rel, 'app/Finance/') && preg_match('/[\'"]fee_\w+[\'"]/', $line)) {
        $add('fee-table-outside-finance', $rel, $line);
    }

    // finance-escape-hatches — §17.1 rule 4, method calls inside app/Finance/.
    if (str_starts_with($rel, 'app/Finance/')
        && preg_match('/(withoutGlobalScopes?\(|withoutSchoolScope\(|->hasRole\(|auth\(\)->setUser\(|DB::table\()/', $line)) {
        $add('finance-escape-hatches', $rel, $line);
    }

    // halting-event-arrow-fn — Laravel's creating/updating/saving/deleting events
    // are HALTING (dispatched via until()): a listener returning a non-null value
    // silently stops the rest of the chain. An arrow fn `fn(...) => expr` always
    // returns `expr`, so registering one for a halting event is a latent
    // chain-halt (this is exactly how AddUuid halted BelongsToSchool's auto-fill).
    // Register halting-event listeners with a block closure that returns nothing.
    if (preg_match('/(static::|->)(creating|updating|saving|deleting)\(\s*fn\b/', $line)) {
        $add('halting-event-arrow-fn', $rel, $line);
    }
}

foreach ($tests as [$rel, $line]) {
    if (isComment($line)) {
        continue;
    }

    // force-create-finance-tests — MoneyCast bypass in Finance tests.
    if (preg_match('#tests/.*Finance#i', $rel) && str_contains($line, 'forceCreate(')) {
        $add('force-create-finance-tests', $rel, $line);
    }
}

$found = array_keys($found);
sort($found);

if ($mode === 'generate') {
    $header = <<<'TXT'
# boundary-lint baseline — intentional, TEMPORARY exceptions. May only shrink.
#
# school-id-fallback-context entries expire when users.school_id is dropped
#   (§5.3/§7.1 — after the rbac.single_source_access parity gate; ActiveSchool's
#   guarded fallback and ActivitySchoolResolver's user fallback go with it).
#   NOTE on the SuperAdmin/AdminController entry: that one is a legacy-column
#   MAINTENANCE WRITE (keeping the retained expand/contract users.school_id
#   pointing at a School the user can access), NOT a context-read fallback —
#   a rule true-positive on the column's existence rather than a Constitution 13
#   violation in logic. Same expiry (the users.school_id drop); when burning down
#   this baseline, delete that code with the column — there is no fallback logic
#   to remove there.
# fee-table-outside-finance entries expire when Ph2's FinanceModuleStatus
#   contract (ADR 0030) replaces ModuleClassificationService's direct fee_* reads.
# halting-event-arrow-fn entries are pre-existing per-model `creating(fn => uuid)`
#   setters. They are currently HARMLESS (each is the last creating hook in its
#   model, so it halts nothing — proven by BelongsToSchoolConformanceTest), but
#   they are latent chain-halts. Convert each to a block closure opportunistically;
#   the baseline may only shrink. The live defect (AddUuid, which DID halt
#   BelongsToSchool) is fixed, not baselined.

TXT;
    file_put_contents($baselinePath, $header.($found ? implode("\n", $found)."\n" : ''));
    fwrite(STDERR, 'boundary-lint: wrote '.count($found)." baseline entries to boundary-lint-baseline.txt\n");
    exit(0);
}

$baseline = is_file($baselinePath)
    ? array_values(array_filter(array_map('rtrim', file($baselinePath)), fn ($l) => $l !== '' && ! str_starts_with($l, '#')))
    : [];

$new = array_values(array_diff($found, $baseline));
$fixed = array_values(array_diff($baseline, $found));

if ($fixed) {
    fwrite(STDERR, "\nboundary-lint: ".count($fixed)." baselined exception(s) removed (good) — update boundary-lint-baseline.txt:\n");
    foreach ($fixed as $f) {
        fwrite(STDERR, '  - '.str_replace("\t", '  ', $f)."\n");
    }
}

if ($new) {
    fwrite(STDERR, "\nboundary-lint: ".count($new)." NEW boundary violation(s):\n");
    foreach ($new as $n) {
        fwrite(STDERR, '  '."\u{2717}".' '.str_replace("\t", '  ', $n)."\n");
    }
    exit(1);
}

fwrite(STDERR, 'boundary-lint: OK — no new boundary violations ('.count($found)." known temporary exceptions).\n");
exit(0);
