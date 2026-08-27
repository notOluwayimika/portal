#!/usr/bin/env php
<?php

use Tests\Feature\Rbac\SomeTest;

/**
 * A dev-only namespace must not be imported from production code.
 *
 * ── WHAT THIS REFUSES ────────────────────────────────────────────────────────────────────────────
 * `composer.json` splits its autoload map in two. `autoload` ships (`App\`, `Database\Factories\`,
 * `Database\Seeders\`); `autoload-dev` does not (`Tests\`). A `use Tests\…;` inside `app/` or
 * `database/seeders/` is therefore a reference to a class that will not exist under
 * `composer install --no-dev`.
 *
 * BOTH SETS ARE DERIVED FROM composer.json, never hardcoded. A second dev-only namespace added later
 * is covered without anyone remembering this file exists — the same "compare the set, not a
 * remembered instance" discipline as the version-tripwire siblings rule in CLAUDE.md.
 *
 * ── WHY A LINT AND NOT A CONVENTION ──────────────────────────────────────────────────────────────
 * Nobody types this. Pint's `fully_qualified_strict_types` fixer rewrites a fully-qualified name in a
 * REAL docblock into a short name plus an import:
 *
 *     /** … {@see SomeTest} … *\/   ->   use Tests\Feature\Rbac\SomeTest;
 *
 * A plain `/* *\/` block comment is left alone. So the difference between a violation and a
 * non-violation is ONE ASTERISK, invisible at review, and decided by a formatter rather than by an
 * author — and Pint's output names only the fixer, never the dependency it created. Citing a test
 * from production code is a reasonable thing to write; that is exactly why the rule has to be
 * mechanical.
 *
 * ── WHY IT IS LOUD ───────────────────────────────────────────────────────────────────────────────
 * The only thing that reacted before was the arch pass, and it reacted by dying: `pest --group=arch`
 * exited 255 with ZERO bytes on stdout, stderr, `--log-junit` and the PHP error log, because the
 * fatal ("Cannot redeclare …" — the test file loaded twice, once by Pest and once by the autoloader
 * resolving the import) sat in an output buffer Pest never flushed. It cost four bisection rounds and
 * two wrong conclusions to read. A control that enforces a boundary by dying silently is
 * indistinguishable from one that does not enforce it, and is worse, because it makes the next
 * occurrence more expensive rather than less.
 *
 * NO BASELINE, and none is needed: the population was measured at exactly one before this landed, and
 * that one is fixed in the same change. A rule that ships red would hand the next person a cleanup.
 *
 * @see docs/handoff/tickets/a-dev-only-import-in-production-code-passes-every-floor-gate.md
 */
$root = dirname(__DIR__);

$composer = json_decode((string) file_get_contents($root.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);

$prodPaths = array_values($composer['autoload']['psr-4'] ?? []);
$devNamespaces = array_keys($composer['autoload-dev']['psr-4'] ?? []);

if ($prodPaths === [] || $devNamespaces === []) {
    fwrite(STDERR, "dev-namespace-lint: composer.json has no autoload or autoload-dev psr-4 map — nothing to compare.\n");
    fwrite(STDERR, "  This is a VOID result, not a pass: the rule derives both sets from that map.\n");
    exit(1);
}

$violations = [];

foreach ($prodPaths as $relative) {
    $dir = $root.'/'.rtrim($relative, '/');

    if (! is_dir($dir)) {
        continue;
    }

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES);

        foreach ($lines as $i => $line) {
            // A real import statement only — `use function`/`use const` included, since either would
            // resolve the same absent namespace. Docblock mentions and prose are NOT violations:
            // naming a test in a comment is legitimate, and it is the promotion to an import that
            // creates the dependency.
            if (! preg_match('/^\s*use\s+(?:function\s+|const\s+)?\\\\?([^;\s]+)\s*;/', $line, $m)) {
                continue;
            }

            foreach ($devNamespaces as $ns) {
                if (str_starts_with($m[1], $ns)) {
                    $violations[] = [
                        'file' => ltrim(str_replace($root, '', $file->getPathname()), '/'),
                        'line' => $i + 1,
                        'symbol' => $m[1],
                        'namespace' => $ns,
                    ];
                    break;
                }
            }
        }
    }
}

if ($violations !== []) {
    usort($violations, fn ($a, $b) => [$a['file'], $a['line']] <=> [$b['file'], $b['line']]);

    fwrite(STDERR, "\ndev-namespace-lint: ".count($violations)." import(s) of a DEV-ONLY namespace from production code:\n");

    foreach ($violations as $v) {
        fwrite(STDERR, "  ✗ {$v['file']}:{$v['line']}  use {$v['symbol']};\n");
        fwrite(STDERR, "      `{$v['namespace']}` is declared under composer.json autoload-DEV, so this class does not\n");
        fwrite(STDERR, "      exist under `composer install --no-dev`. Anything that resolves the name fatals there.\n");
    }

    fwrite(STDERR, "\n  Usually nobody typed this: Pint's fully_qualified_strict_types promotes a fully-qualified\n");
    fwrite(STDERR, "  name in a `/**` docblock into an import. Write the reference WITHOUT a leading backslash,\n");
    fwrite(STDERR, "  or as a plain path in prose, and the fixer leaves it alone.\n");
    fwrite(STDERR, '  Why this rule exists: docs/handoff/tickets/a-dev-only-import-in-production-code-passes-every-floor-gate.md'."\n");

    exit(1);
}

fwrite(STDERR, 'dev-namespace-lint: OK — no dev-only imports in production paths ('
    .implode(', ', $prodPaths).' vs '.implode(', ', $devNamespaces).").\n");
exit(0);
