<?php

/**
 * money-lint — the UI money-correctness gate (the frontend equivalent of a DB trigger).
 *
 * The money architecture is integer minor units end to end precisely because JS numbers
 * are floats. Two rules keep the UI from reintroducing the float-money bug the backend
 * exists to prevent — enforced statically and PERMANENTLY, on every future frontend change:
 *
 *   money-format-outside-formatnaira
 *     `Intl.NumberFormat` / `.toLocaleString(` used to render money. All money is displayed
 *     via formatNaira() (resources/js/lib/format.ts — the ONE exempt file, where the single
 *     Intl call lives). Enforced hard inside the Finance UI (every number there is money),
 *     and by money-identifier heuristic anywhere else (a stray `amount_minor.toLocaleString()`).
 *
 *   money-arithmetic-in-ui
 *     Monetary arithmetic in JavaScript — summing amounts, computing balances/outstanding/
 *     totals client-side. The API returns every figure already computed; the UI only displays.
 *     Best-effort heuristic (money-identifier adjacent to an operator, or a reduce() in the
 *     Finance UI), but standing. The ONLY sanctioned money arithmetic is the integer helpers
 *     in resources/js/lib/format.ts (formatNaira display, nairaToMinor input, sumMinor total —
 *     all exact integer minor-unit ops); that file is exempt from BOTH rules and is the single
 *     reviewed money boundary. Callers use the named helper; ad-hoc +/reduce stays banned.
 *
 * Like the sibling lints, the baseline may only shrink: CI fails on any NEW occurrence;
 * removing a baselined line is reported as progress.
 *
 * Usage:
 *   php bin/ci-money-lint.php            # check (CI): exit 1 on new findings
 *   php bin/ci-money-lint.php generate   # (re)write the baseline
 */
$root = dirname(__DIR__);
$baselinePath = $root.'/money-lint-baseline.txt';
$mode = $argv[1] ?? 'check';

// The ONE file allowed to touch Intl.NumberFormat/toLocaleString for money.
const FORMATNAIRA_HOME = 'resources/js/lib/format.ts';

// Finance UI paths — every displayed number here is money, so the format ban is total.
function isFinanceUi(string $rel): bool
{
    return str_starts_with($rel, 'resources/js/pages/admin/finance/')
        || str_starts_with($rel, 'resources/js/components/finance/');
}

/** @return array<int, array{0: string, 1: string}> [[relativePath, line], ...] */
function scriptLines(string $dir, string $root): array
{
    if (! is_dir($dir)) {
        return [];
    }
    $out = [];
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($rii as $file) {
        if (! in_array($file->getExtension(), ['ts', 'tsx'], true)) {
            continue;
        }
        $rel = ltrim(str_replace($root, '', $file->getPathname()), '/');
        // Skip wayfinder-generated route actions (machine output, not hand-written UI).
        if (str_starts_with($rel, 'resources/js/actions/')) {
            continue;
        }
        foreach (file($file->getPathname(), FILE_IGNORE_NEW_LINES) as $line) {
            $out[] = [$rel, $line];
        }
    }

    return $out;
}

function isComment(string $line): bool
{
    $t = ltrim($line);

    return str_starts_with($t, '//') || str_starts_with($t, '*') || str_starts_with($t, '/*');
}

$lines = scriptLines($root.'/resources/js', $root);

$found = [];
$add = function (string $rule, string $rel, string $line) use (&$found) {
    $found[$rule."\t".$rel."\t".trim($line)] = true;
};

foreach ($lines as [$rel, $line]) {
    if (isComment($line)) {
        continue;
    }

    $usesIntlFormatter = str_contains($line, 'Intl.NumberFormat') || preg_match('/\.toLocaleString\s*\(/', $line);

    // money-format-outside-formatnaira. Inside the Finance UI the ban is total (all
    // numbers are money); elsewhere it fires only when a money identifier is on the line,
    // catching a stray money render without flagging legitimate count/date formatting.
    if ($usesIntlFormatter && $rel !== FORMATNAIRA_HOME) {
        $moneyIdentifierOnLine = (bool) preg_match('/(amount_minor|available_credit|\bbalance_minor\b|\.amount\b)/', $line);
        if (isFinanceUi($rel) || $moneyIdentifierOnLine) {
            $add('money-format-outside-formatnaira', $rel, $line);
        }
    }

    // money-arithmetic-in-ui. A money identifier adjacent to an arithmetic operator, or a
    // reduce() inside the Finance UI (almost always a client-side sum). Skip type/interface
    // lines (`amount_minor: number`) — those declare shape, they do not compute — and the
    // money-boundary file itself, where the sanctioned integer helpers (sumMinor et al.) live.
    $isTypeDecl = (bool) preg_match('/:\s*(number|Money|string)\b/', $line);
    if (! $isTypeDecl && $rel !== FORMATNAIRA_HOME) {
        $moneyMath = preg_match('/amount_minor\s*[+\-*\/]/', $line)
            || preg_match('/[+\-*]\s*[\w.$\[\]]*amount_minor/', $line)
            || preg_match('/(available_credit|balance_minor)\s*[+\-*\/]/', $line);
        $financeReduce = isFinanceUi($rel) && preg_match('/\.reduce\s*\(/', $line);
        if ($moneyMath || $financeReduce) {
            $add('money-arithmetic-in-ui', $rel, $line);
        }
    }
}

$found = array_keys($found);
sort($found);

if ($mode === 'generate') {
    $header = <<<'TXT'
# money-lint baseline — intentional exceptions to the UI money rules. May only shrink.
#
# Ideally EMPTY: the Finance UI is new and routes all money through formatNaira(), and no
# pre-existing code renders a money identifier via toLocaleString/Intl. An entry here is a
# deliberate, reviewed exception (e.g. a genuine non-money reduce() that happens to sit in
# a Finance UI file) — never a money render or a money sum.

TXT;
    file_put_contents($baselinePath, $header.($found ? implode("\n", $found)."\n" : ''));
    fwrite(STDERR, 'money-lint: wrote '.count($found)." baseline entries to money-lint-baseline.txt\n");
    exit(0);
}

$baseline = is_file($baselinePath)
    ? array_values(array_filter(array_map('rtrim', file($baselinePath)), fn ($l) => $l !== '' && ! str_starts_with($l, '#')))
    : [];

$new = array_values(array_diff($found, $baseline));
$fixed = array_values(array_diff($baseline, $found));

if ($new !== []) {
    fwrite(STDERR, "\nmoney-lint: ".count($new)." NEW money-rule violation(s) — render money via formatNaira(), never compute money in JS:\n");
    foreach ($new as $n) {
        fwrite(STDERR, '  '."\u{2717}".' '.str_replace("\t", '  ', $n)."\n");
    }
    exit(1);
}

// SHRINK-LOCK: a baselined entry that no longer occurs must be removed from the baseline,
// or a future re-introduction would pass silently.
if ($fixed !== []) {
    fwrite(STDERR, "\nmoney-lint: ".count($fixed)." baselined exception(s) removed (good!) — lock it in by removing them from money-lint-baseline.txt:\n");
    foreach ($fixed as $f) {
        fwrite(STDERR, '  - '.str_replace("\t", '  ', $f)."\n");
    }
    fwrite(STDERR, "  regenerate: php bin/ci-money-lint.php generate\n");
    exit(1);
}

fwrite(STDERR, 'money-lint: OK — no money-rule violations ('.count($found)." known exception(s)).\n");
exit(0);
