#!/usr/bin/env php
<?php

/**
 * citation-window-measure — the measurement behind `bin/ci-citation-lint.php`'s WINDOW, committed
 * so that whoever inherits the parameter can re-run it instead of taking the number on trust.
 *
 * WHY THIS FILE EXISTS AND IS NOT A SCRATCH SCRIPT. The first version of the citation-lint report
 * DESCRIBED this extraction in prose. A cold review re-implemented it from the prose, reproduced
 * every percentage, every percentile and the CROSSING exactly, and got different DENOMINATORS —
 * 942 against 2,222 matched symbols over 30 days, 982 against 1,514 over 60. The moved populations
 * were identical; the whole gap was delta-0 rows, i.e. an unstated choice about whether files that
 * did not change between the two shas contribute. A measurement that decides a design parameter has
 * to be re-runnable by someone who was not there, so the choice is now made explicitly and BOTH
 * denominators are printed (see `drift`).
 *
 * Nothing in bin/quality runs this. It is an instrument, not a gate.
 *
 * Usage:
 *   php bin/citation-window-measure.php discrimination
 *   php bin/citation-window-measure.php drift <old-sha>
 *   php bin/citation-window-measure.php nearest
 */
$root = dirname(__DIR__);
$mode = $argv[1] ?? '';

/** The same directories bin/ci-citation-lint.php scans. */
const DIRS = ['app', 'tests', 'bin', 'database', 'config', 'routes', 'bootstrap', '.claude/skills'];

/** The corpus: TRACKED files in DIRS with one of these extensions. Untracked files are excluded so
 *  the corpus is a property of the commit rather than of the working copy. */
const EXTENSIONS = ['php', 'md', 'ts', 'tsx'];

/** The window under test — read from the lint so the two cannot drift apart. */
function lintWindow(string $root): int
{
    preg_match('/const WINDOW = (\d+);/', (string) file_get_contents($root.'/bin/ci-citation-lint.php'), $m);

    return (int) ($m[1] ?? 3);
}

/**
 * The declaration extractor. Stated here because every denominator below depends on it: PHP/TS
 * `function|class|interface|trait|enum NAME`, plus SCREAMING_CASE `const NAME`. It does NOT see
 * properties, arrow-function assignments, or TS type aliases — a different extractor moves the
 * percentages, and whether it moves the CROSSING is the thing to re-test.
 *
 * @return array<int, array{0: int, 1: string}> [[lineNumber, name], ...] in file order
 */
function declarations(string $source): array
{
    $out = [];
    foreach (explode("\n", $source) as $i => $line) {
        if (preg_match('/\b(?:function|class|interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)/', $line, $m)) {
            $out[] = [$i + 1, $m[1]];
        } elseif (preg_match('/\bconst\s+([A-Z_][A-Z0-9_]*)/', $line, $m)) {
            $out[] = [$i + 1, $m[1]];
        }
    }

    return $out;
}

/** @return array<int, string> tracked corpus paths, repo-relative */
function corpus(string $root): array
{
    $args = implode(' ', array_map('escapeshellarg', DIRS));
    $out = [];
    foreach (explode("\n", trim((string) shell_exec('git -C '.escapeshellarg($root).' ls-files -- '.$args))) as $p) {
        if ($p !== '' && in_array(pathinfo($p, PATHINFO_EXTENSION), EXTENSIONS, true)) {
            $out[] = $p;
        }
    }
    sort($out);

    return $out;
}

/** Every line on which $name occurs as a whole word. @return array<int, int> */
function occurrences(array $lines, string $name): array
{
    $hits = [];
    foreach ($lines as $i => $l) {
        if (preg_match('/\b'.preg_quote($name, '/').'\b/', $l) === 1) {
            $hits[] = $i + 1;
        }
    }

    return $hits;
}

function percentiles(array $sorted, array $ps): string
{
    $n = count($sorted);
    $out = [];
    foreach ($ps as $p) {
        $out[] = 'p'.$p.'='.($n ? $sorted[(int) floor($p / 100 * ($n - 1))] : 0);
    }

    return implode('  ', $out);
}

/**
 * DISCRIMINATION. For every adjacent declaration pair in a file, how far is the NEXT declaration's
 * name from THIS declaration's line? That distance is the answer to "if a citation names the wrong
 * neighbouring symbol, does a window of N still accept it?".
 */
function discrimination(string $root): void
{
    $gaps = [];
    $files = 0;
    foreach (corpus($root) as $rel) {
        $lines = file($root.'/'.$rel, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            continue;
        }
        $decls = declarations(implode("\n", $lines));
        if (count($decls) < 2) {
            continue;
        }
        $files++;
        for ($k = 0; $k < count($decls) - 1; $k++) {
            $best = PHP_INT_MAX;
            foreach (occurrences($lines, $decls[$k + 1][1]) as $at) {
                $best = min($best, abs($at - $decls[$k][0]));
            }
            if ($best !== PHP_INT_MAX) {
                $gaps[] = $best;
            }
        }
    }
    sort($gaps);
    $n = count($gaps);
    echo "DISCRIMINATION — a citation that names the WRONG neighbouring symbol\n";
    echo '  pairs: '.$n.' over '.$files." files\n";
    foreach ([0, 1, 2, 3, 5, 8, 10, 15, 20, 30, 50] as $N) {
        $pass = count(array_filter($gaps, fn ($g) => $g <= $N));
        printf("    N=%-3d passes %5.1f%%  (%d/%d)\n", $N, 100 * $pass / max($n, 1), $pass, $n);
    }
    echo '  gap percentiles: '.percentiles($gaps, [10, 25, 50, 75, 90])."\n";
}

/**
 * DRIFT. Declarations matched BY NAME between $old and the working tree, reported two ways.
 *
 * A name whose occurrence COUNT changed between the shas is skipped entirely (renamed, deleted or
 * duplicated symbols are not comparable), so the moved figure is a floor rather than a count.
 */
function drift(string $root, string $old): void
{
    $all = [];
    $changedOnly = [];
    $filesChanged = 0;
    $filesSame = 0;

    foreach (corpus($root) as $rel) {
        $oldSrc = shell_exec('git -C '.escapeshellarg($root).' show '.escapeshellarg($old.':'.$rel).' 2>/dev/null');
        if ($oldSrc === null || $oldSrc === '') {
            continue;
        }
        $newSrc = (string) file_get_contents($root.'/'.$rel);
        $unchanged = ($oldSrc === $newSrc);
        $unchanged ? $filesSame++ : $filesChanged++;

        $o = [];
        foreach (declarations($oldSrc) as [$L, $name]) {
            $o[$name][] = $L;
        }
        $n = [];
        foreach (declarations($newSrc) as [$L, $name]) {
            $n[$name][] = $L;
        }

        foreach ($o as $name => $lines) {
            if (! isset($n[$name]) || count($n[$name]) !== count($lines)) {
                continue;
            }
            foreach ($lines as $k => $L) {
                $d = abs($n[$name][$k] - $L);
                $all[] = $d;
                if (! $unchanged) {
                    $changedOnly[] = $d;
                }
            }
        }
    }

    foreach ([['ALL files present at both shas', $all, $filesChanged + $filesSame],
        ['CHANGED files only', $changedOnly, $filesChanged]] as [$label, $set, $fileCount]) {
        sort($set);
        $n = count($set);
        $moved = array_values(array_filter($set, fn ($d) => $d > 0));
        $m = count($moved);
        echo 'DRIFT '.$old.'..worktree — '.$label."\n";
        printf("  %d symbols over %d files; %d moved (%.1f%%)\n", $n, $fileCount, $m, 100 * $m / max($n, 1));
        foreach ([0, 1, 2, 3, 5, 8, 10, 15, 20, 30, 50] as $N) {
            printf("    N=%-3d moved symbol still inside window: %5.1f%%\n",
                $N, 100 * count(array_filter($moved, fn ($d) => $d <= $N)) / max($m, 1));
        }
        echo '  |delta| among MOVED: '.percentiles($moved, [50, 75, 90, 95, 99])."\n";
    }
}

/**
 * NEAREST-PRECEDING. The rule under test is: a citation is ALSO compliant when the symbol it names
 * is the nearest declaration at or above the cited line. Two numbers matter and both are printed.
 *
 *   WRONG-SYMBOL   the adversary from discrimination(), moved off the declaration line and onto
 *                  every line of that declaration's body: does naming the NEXT declaration pass?
 *   GIVEN UP       of the (body line, enclosing declaration) pairs — the citations the rule is FOR —
 *                  what fraction does the window ALREADY accept? The complement is the region the
 *                  nearest-preceding rule newly accepts, i.e. what verification is given up.
 */
function nearest(string $root): void
{
    $window = lintWindow($root);
    $wrongWindow = 0;
    $wrongNearest = 0;
    $wrongTotal = 0;
    $rightWindow = 0;
    $rightTotal = 0;
    $bodyLengths = [];

    foreach (corpus($root) as $rel) {
        $lines = file($root.'/'.$rel, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            continue;
        }
        $decls = declarations(implode("\n", $lines));
        if (count($decls) < 2) {
            continue;
        }

        for ($k = 0; $k < count($decls) - 1; $k++) {
            [$L, $name] = $decls[$k];
            $next = $decls[$k + 1];
            $bodyEnd = $next[0] - 1;
            $bodyLengths[] = max(0, $bodyEnd - $L);

            $occNext = occurrences($lines, $next[1]);
            $occSelf = occurrences($lines, $name);

            for ($line = $L; $line <= $bodyEnd; $line++) {
                // the wrong-symbol adversary: a citation inside THIS declaration's body naming the
                // NEXT declaration
                $wrongTotal++;
                $inWindow = false;
                foreach ($occNext as $at) {
                    if (abs($at - $line) <= $window) {
                        $inWindow = true;
                        break;
                    }
                }
                if ($inWindow) {
                    $wrongWindow++;
                }
                // under nearest-preceding the adversary passes only if the NEXT declaration is
                // itself the nearest declaration at or above this line, which inside this body it
                // never is — printed rather than asserted
                if ($inWindow || $next[0] <= $line) {
                    $wrongNearest++;
                }

                // the citations the rule is FOR: this body line, naming its enclosing declaration
                $rightTotal++;
                foreach ($occSelf as $at) {
                    if (abs($at - $line) <= $window) {
                        $rightWindow++;
                        break;
                    }
                }
            }
        }
    }

    sort($bodyLengths);
    echo 'NEAREST-PRECEDING (window = '.$window.")\n";
    printf("  wrong-symbol adversary (body line naming the NEXT declaration), %d pairs\n", $wrongTotal);
    printf("    window only        passes %5.2f%%  (%d)\n", 100 * $wrongWindow / max($wrongTotal, 1), $wrongWindow);
    printf("    window OR nearest  passes %5.2f%%  (%d)\n", 100 * $wrongNearest / max($wrongTotal, 1), $wrongNearest);
    printf("  citations the rule is FOR (body line naming its enclosing declaration), %d pairs\n", $rightTotal);
    printf("    window only        accepts %5.2f%%\n", 100 * $rightWindow / max($rightTotal, 1));
    printf("    window OR nearest  accepts 100.00%%  (by construction)\n");
    printf("    region newly accepted: %5.2f%% of body lines\n", 100 - 100 * $rightWindow / max($rightTotal, 1));
    echo '  declaration body length: '.percentiles($bodyLengths, [50, 75, 90, 95, 99])."\n";
}

match ($mode) {
    'discrimination' => discrimination($root),
    'drift' => drift($root, $argv[2] ?? 'HEAD~1'),
    'nearest' => nearest($root),
    default => fwrite(STDERR, "usage: php bin/citation-window-measure.php discrimination|drift <sha>|nearest\n"),
};
