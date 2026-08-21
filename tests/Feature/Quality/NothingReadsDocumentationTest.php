<?php

/*
 * A REGRESSION TEST FOR ONE HISTORICAL SHAPE, AND A DRIFT-LOCK ON THE ALLOWLIST.
 *
 * That is what this file is. It was previously headed "the invariant is not a claim about
 * today's tree any more; it is this test", and it has not earned that sentence: six readers of
 * `docs/**.md` could sit in app/ today and this stays green, because three of the shapes below
 * are honestly beyond it. What it does hold is narrower and worth having.
 *
 * 1. IT IS A REGRESSION TEST for the shape that actually got through. A `self::` class constant
 *    holding one string literal, read through `base_path()`:
 *    `app/Console/Commands/AuthzObservations.php:22` → `:149` → `:155`, with a second reader in
 *    `tests/Feature/Rbac/AuthzObservationsCommandTest.php:59`. That shape was invisible to
 *    `grep -rn "docs/"` — the file scores 0 on it — and is caught here. Retyping the constant's
 *    extension to `.md` reds this file and names both readers by path and line.
 *
 * 2. IT IS A DRIFT-LOCK COUPLING `DOC_EXTENSIONS` TO THE KNOWN READER SET, and this is verified,
 *    not asserted. Appending `json` to the allowlist in bin/is-docs-only-push — the single most
 *    likely future edit, and the one that would silently re-open the original hole — reds this
 *    file with:
 *
 *        app/Console/Commands/AuthzObservations.php:149  base_path  →  docs/runbooks/authz-observation-classifications.json
 *        tests/Feature/Rbac/AuthzObservationsCommandTest.php:59  base_path  →  docs/runbooks/authz-observation-classifications.json
 *
 *    The allowlist cannot be widened past a known reader without a red that names it.
 *
 * WHAT IT SWEEPS. Every `.php` file under app, bin, tests, config, database, routes and
 * bootstrap is tokenised with `token_get_all`; every `base_path(` / `realpath(` /
 * `file_get_contents(` / `fopen(` call, every `Storage::` / `File::` static call and every
 * `__DIR__` is collected; the first argument is RESOLVED — string literals, `__DIR__`, and class
 * constants declared as a single literal in the same file or readable through the autoloader —
 * and a resolved path under `docs/` carrying an extension bin/is-docs-only-push would SKIP is a
 * violation. A docs/ path whose extension the rule REFUSES is not: the gate runs for that push.
 *
 * THE HONEST COVERAGE NUMBER, measured on the current tree:
 *
 *     idiom               resolved   unresolved
 *     __DIR__                   70            0
 *     base_path                 18            5
 *     file_get_contents          0           35
 *     File::*                    0           23
 *     Storage::*                10           21     (7 of the 10 resolved are Storage::fake)
 *     fopen                      3            2
 *     ------------------------------------------
 *     total                    101           86     = 187 call sites
 *
 * 70 of those 187 "call sites" are BARE `__DIR__` TOKENS. They are not readers, they always
 * resolve, and they inflate the denominator by 37%. Take them out and 31 of the remaining 117
 * resolve — 26%, or 17% of the full 187, depending which denominator you quote. Either way the
 * headline "187 call sites, 0 violations" describes far more coverage than exists: **in practice
 * this invariant is `base_path` and its 18 resolved sites.** That is the number a reader should
 * carry away.
 *
 * WHAT A GREEN DOES NOT PROVE.
 *
 *   - A path built at RUNTIME is invisible: `base_path('docs/'.$name)`, a config value, an
 *     argument, a property, a heredoc. 86 of 187 sites resolve to nothing and are COUNTED as
 *     unresolved — never as clean. That distinction is itself an arm.
 *   - A CONCATENATED class constant is unresolved, not guessed. `const P = 'docs/notes'.'.md';`
 *     once resolved to `docs/notes` and was reported clean; it is now admitted as unknown.
 *     AuthzObservations::CLASSIFICATIONS_PATH is one refactor from that shape.
 *   - An IDIOM THIS SWEEP DOES NOT NAME is invisible: `include`, `require`, `SplFileObject`,
 *     `readfile`, `finfo`, Symfony `Finder`, a `Process` running `cat`, `app()->basePath()`.
 *   - NON-PHP READERS are invisible: only `.php` files are tokenised, so a shell script under
 *     `bin/` doing `cat docs/something.md` is not seen. A grep for that would false-positive on
 *     every prose citation in those same scripts, so it is stated rather than approximated badly.
 *   - `self::` AND `static::` never resolve through reflection — `class_exists('self')` is false.
 *     They are covered by the same-file `const NAME = 'literal';` scan, which runs first. The
 *     historical case was caught that way.
 *
 * HOW IT FAILS, measured rather than assumed — because a checker's failure DIRECTION is the
 * property that decides whether a green means anything:
 *
 *   - an UNPARSEABLE file still yields its reader and still reds (token_get_all is a lexer, not
 *     a parser): 1 violation, `docs/notes.md`, from a source with an unclosed brace;
 *   - an UNREADABLE directory THROWS — `UnexpectedValueException … Failed to open directory:
 *     Permission denied` — rather than being skipped silently;
 *   - REFORMATTING the `DOC_EXTENSIONS` line in bin/is-docs-only-push (double quotes to single)
 *     errors all 8 tests by name with "no longer carries a DOC_EXTENSIONS line this test can
 *     read", rather than any of them defaulting to a built-in list;
 *   - EMPTYING `DOC_EXTENSIONS` reds 7 of the 8.
 *
 * A ROOT MAY NOT DISAPPEAR SILENTLY. The roots list is a hardcoded literal and nothing keeps it
 * honest: dropping `app/` from it leaves the union at 144 call sites, which clears any
 * total-based floor while the sweep sees none of app/. Each root is therefore checked
 * individually, app/ carries a named floor, and docs/module-blueprint.md's planned app/Finance/
 * restructure is why that matters now rather than later.
 */

uses()->group('arch');

/**
 * The extensions bin/is-docs-only-push will skip, read out of the script itself.
 *
 * @return list<string>
 */
function skippableDocumentationExtensions(): array
{
    $script = file_get_contents(dirname(__DIR__, 3).'/bin/is-docs-only-push');

    if (preg_match('/^DOC_EXTENSIONS="([^"]*)"$/m', (string) $script, $m) !== 1) {
        throw new RuntimeException('bin/is-docs-only-push no longer carries a DOC_EXTENSIONS line this test can read');
    }

    return array_values(array_filter(explode(' ', $m[1])));
}

/**
 * Scan PHP sources under $roots for reads of a path bin/is-docs-only-push would skip.
 *
 * @param  list<string>  $roots  absolute directories
 * @return array{violations: list<array{file: string, line: int, idiom: string, path: string}>, callSites: int, unresolved: int}
 */
function documentationReaderScan(array $roots, string $repoRoot): array
{
    $allowed = skippableDocumentationExtensions();
    $violations = [];
    $callSites = 0;
    $unresolved = 0;

    foreach ($roots as $root) {
        if (! is_dir($root)) {
            continue;
        }

        $walker = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($walker as $entry) {
            /** @var SplFileInfo $entry */
            if (! $entry->isFile() || strtolower($entry->getExtension()) !== 'php') {
                continue;
            }

            $file = $entry->getPathname();
            if (str_contains($file, '/vendor/') || str_contains($file, '/node_modules/')) {
                continue;
            }

            $source = (string) file_get_contents($file);
            $tokens = @token_get_all($source);
            $constants = documentationReaderFileConstants($tokens);

            foreach (documentationReaderCallSites($tokens) as $site) {
                $callSites++;
                $value = documentationReaderResolve($site['args'], $file, $constants);

                if ($value === null) {
                    $unresolved++;

                    continue;
                }

                $relative = documentationReaderNormalise($value, $repoRoot);

                if ($relative === null || ! str_starts_with($relative, 'docs/')) {
                    continue;
                }

                $base = basename($relative);
                $ext = str_contains($base, '.') ? strtolower(substr($base, strrpos($base, '.') + 1)) : '';

                if (in_array($ext, $allowed, true)) {
                    $violations[] = [
                        'file' => str_starts_with($file, $repoRoot.'/') ? substr($file, strlen($repoRoot) + 1) : $file,
                        'line' => $site['line'],
                        'idiom' => $site['idiom'],
                        'path' => $relative,
                    ];
                }
            }
        }
    }

    return ['violations' => $violations, 'callSites' => $callSites, 'unresolved' => $unresolved];
}

/**
 * `const NAME = 'literal';` declared anywhere in this file, keyed by NAME.
 *
 * KEYED BY THE BARE NAME, not by class, deliberately: it must resolve `self::X`, `static::X`
 * and `SomeClass::X` alike, including for a class that is not autoloadable — which is exactly
 * the shape the planted fixture below has, and exactly the shape the real defect had.
 *
 * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
 * @return array<string, string>
 */
function documentationReaderFileConstants(array $tokens): array
{
    $out = [];
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_CONST) {
            continue;
        }

        $name = documentationReaderNext($tokens, $i);
        $equals = $name === null ? null : documentationReaderNext($tokens, $name);
        $literal = $equals === null ? null : documentationReaderNext($tokens, $equals);

        if ($name === null || $equals === null || $literal === null) {
            continue;
        }

        if (! is_array($tokens[$name]) || $tokens[$name][0] !== T_STRING) {
            continue;
        }
        if ($tokens[$equals] !== '=') {
            continue;
        }
        if (! is_array($tokens[$literal]) || $tokens[$literal][0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }

        // THE LITERAL MUST BE THE WHOLE VALUE. This used to take the first
        // T_CONSTANT_ENCAPSED_STRING after `=` and stop, never looking at what followed — so
        // `const P = 'docs/notes'.'.md';` resolved to `docs/notes` and
        // `const P = 'docs/'.'notes.md';` resolved to `docs/`. Both are REAL READERS, both were
        // reported resolved and clean, and neither appeared in the unresolved tally this file
        // offers as its own honesty measure. That is precisely what
        // documentationReaderResolve()'s docblock forbids: a partly guessed path is worse than
        // an admitted unknown. Requiring `;` or `,` next makes a concatenated constant
        // unresolvable, which is the honest answer.
        //
        // AuthzObservations::CLASSIFICATIONS_PATH is one refactor away from this shape. Had the
        // historical path been written as a concatenation, this test would have missed it
        // exactly as the grep did.
        $terminator = documentationReaderNext($tokens, $literal);

        if ($terminator === null || ($tokens[$terminator] !== ';' && $tokens[$terminator] !== ',')) {
            continue;
        }

        $out[$tokens[$name][1]] = documentationReaderLiteral($tokens[$literal][1]);
    }

    return $out;
}

/**
 * The bare function name a token denotes, or null if it is not a function-name token.
 *
 * `\base_path('docs/x.md')` tokenises as ONE T_NAME_FULLY_QUALIFIED token, not as `\` followed
 * by T_STRING — so a collector that required T_STRING never saw a fully-qualified global call at
 * its own site. Matching on the TRAILING SEGMENT is what makes `\base_path` and `base_path` the
 * same idiom here. The cost of that choice, stated rather than hidden: a namespaced function
 * genuinely named `Some\Ns\base_path` would also match. There is none in this repository, and
 * a false POSITIVE in this direction produces a red to investigate, not a silent skip.
 */
function documentationReaderFunctionName(mixed $token): ?string
{
    if (! is_array($token)) {
        return null;
    }

    if ($token[0] === T_STRING) {
        return strtolower($token[1]);
    }

    if ($token[0] === T_NAME_FULLY_QUALIFIED) {
        $name = ltrim($token[1], '\\');

        return strtolower(str_contains($name, '\\') ? substr($name, strrpos($name, '\\') + 1) : $name);
    }

    return null;
}

/** Index of the next token that is not whitespace or a comment. */
function documentationReaderNext(array $tokens, int $from): ?int
{
    $count = count($tokens);

    for ($i = $from + 1; $i < $count; $i++) {
        if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        return $i;
    }

    return null;
}

/**
 * Every call site of one of the seven named idioms, with the tokens of its FIRST argument.
 *
 * @return list<array{idiom: string, line: int, args: list<mixed>}>
 */
function documentationReaderCallSites(array $tokens): array
{
    $functions = ['base_path', 'realpath', 'file_get_contents', 'fopen'];
    $statics = ['Storage', 'File'];
    $sites = [];
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        // __DIR__ is an idiom in its own right: it is the head of a path expression, and the
        // resolver below turns it into this file's directory so `__DIR__.'/../docs/x.md'`
        // resolves rather than being written off as unresolvable.
        if (is_array($token) && $token[0] === T_DIR) {
            $sites[] = ['idiom' => '__DIR__', 'line' => $token[2], 'args' => documentationReaderExpression($tokens, $i)];

            continue;
        }

        $callName = documentationReaderFunctionName($token);

        if ($callName === null) {
            continue;
        }

        $name = is_array($token) ? $token[1] : '';
        $open = null;
        $idiom = null;

        if (in_array($callName, $functions, true)) {
            $next = documentationReaderNext($tokens, $i);
            // A method named the same thing ($this->realpath()) is not the function; the token
            // before must not be `->`, `::` or `function`.
            $prev = null;
            for ($j = $i - 1; $j >= 0; $j--) {
                if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $prev = $tokens[$j];
                break;
            }
            $prevId = is_array($prev) ? $prev[0] : null;
            if (in_array($prevId, [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
                continue;
            }

            if ($next !== null && $tokens[$next] === '(') {
                $open = $next;
                $idiom = $callName;
            }
        } elseif ($token[0] === T_STRING && in_array($name, $statics, true)) {
            $colon = documentationReaderNext($tokens, $i);
            $method = $colon === null ? null : documentationReaderNext($tokens, $colon);
            $paren = $method === null ? null : documentationReaderNext($tokens, $method);

            if ($colon !== null && is_array($tokens[$colon]) && $tokens[$colon][0] === T_DOUBLE_COLON
                && $method !== null && is_array($tokens[$method]) && $tokens[$method][0] === T_STRING
                && $paren !== null && $tokens[$paren] === '(') {
                $open = $paren;
                $idiom = $name.'::'.$tokens[$method][1];
            }
        }

        if ($open === null || $idiom === null) {
            continue;
        }

        $args = documentationReaderFirstArgument($tokens, $open);

        // `file_get_contents(base_path(self::X))` is TWO call sites, and the outer one's argument
        // is the inner CALL, not a path. Counting it as unresolved would inflate the unresolved
        // tally with wrappers that were in fact fully resolved one level down, and that tally is
        // the honest measure of what this sweep cannot see. The inner site is scanned in its own
        // right, so the wrapper is skipped rather than mis-counted.
        if (documentationReaderWrapsAnotherSite($args, $functions)) {
            continue;
        }

        $sites[] = [
            'idiom' => $idiom,
            'line' => $token[2],
            'args' => $args,
        ];
    }

    return $sites;
}

/** Is this argument expression just another of the scanned calls, wrapped? */
function documentationReaderWrapsAnotherSite(array $args, array $functions): bool
{
    foreach ($args as $index => $t) {
        if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $inner = documentationReaderFunctionName($t);

        if ($inner === null || ! in_array($inner, $functions, true)) {
            return false;
        }

        $next = documentationReaderNext($args, $index);

        return $next !== null && $args[$next] === '(';
    }

    return false;
}

/** Tokens of the first argument of the call whose `(` is at $open. */
function documentationReaderFirstArgument(array $tokens, int $open): array
{
    $depth = 0;
    $args = [];
    $count = count($tokens);

    for ($i = $open; $i < $count; $i++) {
        $t = $tokens[$i];

        if ($t === '(' || $t === '[' || $t === '{') {
            $depth++;
            if ($depth === 1) {
                continue;
            }
        } elseif ($t === ')' || $t === ']' || $t === '}') {
            $depth--;
            if ($depth === 0) {
                break;
            }
        } elseif ($t === ',' && $depth === 1) {
            break;
        }

        if ($depth >= 1) {
            $args[] = $t;
        }
    }

    return $args;
}

/** The concatenation expression a bare __DIR__ sits at the head of. */
function documentationReaderExpression(array $tokens, int $from): array
{
    $out = [$tokens[$from]];
    $count = count($tokens);

    for ($i = $from + 1; $i < $count; $i++) {
        $t = $tokens[$i];

        if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        if ($t === '.' || (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING)) {
            $out[] = $t;

            continue;
        }
        break;
    }

    return $out;
}

/** Unquote a PHP string literal token. */
function documentationReaderLiteral(string $raw): string
{
    $quote = $raw[0] ?? "'";
    $inner = substr($raw, 1, -1);

    if ($quote === "'") {
        return str_replace(['\\\\', "\\'"], ['\\', "'"], $inner);
    }

    return str_replace(['\\\\', '\\"', '\\n', '\\t'], ['\\', '"', "\n", "\t"], $inner);
}

/**
 * Resolve an argument expression to a literal path, or null when it cannot be.
 *
 * Resolvable parts: string literals, `__DIR__`, and class constants whose value is declared as
 * a literal in the same file or is readable through reflection. A variable, a function call or
 * an unresolvable constant makes the whole expression unresolvable — deliberately: a partly
 * guessed path is worse than an admitted unknown.
 *
 * @param  array<string, string>  $fileConstants
 */
function documentationReaderResolve(array $args, string $file, array $fileConstants): ?string
{
    $value = '';
    $count = count($args);
    $i = 0;

    while ($i < $count) {
        $t = $args[$i];

        if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $i++;

            continue;
        }

        if ($t === '.') {
            $i++;

            continue;
        }

        if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
            $value .= documentationReaderLiteral($t[1]);
            $i++;

            continue;
        }

        if (is_array($t) && $t[0] === T_DIR) {
            $value .= dirname($file);
            $i++;

            continue;
        }

        // A class-constant fetch: <class-ish> :: NAME
        $next = $i + 1;
        while ($next < $count && is_array($args[$next]) && in_array($args[$next][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $next++;
        }
        $after = $next + 1;
        while ($after < $count && is_array($args[$after]) && in_array($args[$after][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $after++;
        }

        $isClassish = is_array($t) && in_array($t[0], [T_STRING, T_STATIC, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true);
        $hasColon = $next < $count && is_array($args[$next]) && $args[$next][0] === T_DOUBLE_COLON;
        $hasName = $after < $count && is_array($args[$after]) && $args[$after][0] === T_STRING;

        if ($isClassish && $hasColon && $hasName) {
            $constName = $args[$after][1];

            // SAME-FILE `const NAME = 'literal'` FIRST. It is what makes an unautoloadable class
            // resolvable, and it is the shape the real defect had.
            if (array_key_exists($constName, $fileConstants)) {
                $value .= $fileConstants[$constName];
                $i = $after + 1;

                continue;
            }

            $resolved = documentationReaderReflectConstant($t[1], $constName, $file);

            if ($resolved !== null) {
                $value .= $resolved;
                $i = $after + 1;

                continue;
            }
        }

        return null;
    }

    return $value === '' ? null : $value;
}

/**
 * A constant on a class named in ANOTHER file, resolved through the autoloader.
 *
 * `self::X` AND `static::X` NEVER GET HERE USEFULLY. They arrive as the literal tokens `self`
 * and `static`, and `class_exists('self')` is false, so this returns null for both. They are
 * covered — and the historical AuthzObservations case was caught — by the SAME-FILE
 * `const NAME = 'literal';` scan in documentationReaderResolve(), which runs first. Recorded
 * here because the code path otherwise reads as though reflection handled them, and it does not.
 */
function documentationReaderReflectConstant(string $classish, string $constant, string $file): ?string
{
    $candidates = [$classish, '\\'.$classish];

    // Map a short name through the file's `use` statements.
    $source = (string) file_get_contents($file);
    if (preg_match_all('/^use\s+([^;]+);/m', $source, $uses)) {
        foreach ($uses[1] as $used) {
            $used = trim($used);
            $alias = str_contains($used, ' as ') ? trim(substr($used, strrpos($used, ' as ') + 4)) : substr($used, strrpos($used, '\\') === false ? 0 : strrpos($used, '\\') + 1);
            if ($alias === $classish) {
                $candidates[] = str_contains($used, ' as ') ? trim(substr($used, 0, strpos($used, ' as '))) : $used;
            }
        }
    }

    foreach ($candidates as $candidate) {
        $candidate = ltrim($candidate, '\\');
        if ($candidate === '' || ! class_exists($candidate) && ! interface_exists($candidate)) {
            continue;
        }
        if (! defined($candidate.'::'.$constant)) {
            continue;
        }
        $value = constant($candidate.'::'.$constant);

        if (is_string($value)) {
            return $value;
        }
    }

    return null;
}

/** Reduce a resolved value to a repo-relative path, or null when it points outside the repo. */
function documentationReaderNormalise(string $value, string $repoRoot): ?string
{
    if (str_starts_with($value, $repoRoot.'/')) {
        $value = substr($value, strlen($repoRoot) + 1);
    } elseif (str_starts_with($value, '/')) {
        return null;
    }

    $out = [];
    foreach (explode('/', $value) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($out);

            continue;
        }
        $out[] = $segment;
    }

    return implode('/', $out);
}

/** Write a throwaway PHP source tree and scan it. */
function documentationReaderFixture(string $source): array
{
    $tmp = rtrim((string) shell_exec('mktemp -d'), "\n");

    if ($tmp === '' || ! is_dir($tmp)) {
        throw new RuntimeException('could not create a temp directory for the reader fixture');
    }

    try {
        file_put_contents($tmp.'/Fixture.php', $source);

        return documentationReaderScan([$tmp], dirname(__DIR__, 3));
    } finally {
        exec('rm -rf '.escapeshellarg($tmp));
    }
}

/**
 * The roots this sweep covers. A HARDCODED LITERAL that nothing keeps honest — which is why the
 * arm below checks each one individually rather than trusting a total.
 *
 * @return array<string, string> name => absolute path
 */
function documentationReaderRoots(string $repoRoot): array
{
    $names = ['app', 'bin', 'tests', 'config', 'database', 'routes', 'bootstrap'];

    return array_combine($names, array_map(fn ($n) => $repoRoot.'/'.$n, $names));
}

it('nothing in the codebase reads a documentation file the skip rule would let through', function () {
    $repoRoot = dirname(__DIR__, 3);
    $roots = documentationReaderRoots($repoRoot);

    // A ROOT MAY NOT DISAPPEAR SILENTLY. documentationReaderScan() skips a root that is not a
    // directory, because a fixture may pass one that does not exist — but for the REAL sweep a
    // missing root is total blindness over that subtree, and it must not be a `continue`.
    // Measured: with app/ dropped from this list the union still reports 144 call sites, which
    // clears any total-based floor, and the sweep sees none of app/. docs/module-blueprint.md
    // plans an app/Finance/ restructure, so the list moving is a matter of when.
    foreach ($roots as $name => $path) {
        expect(is_dir($path))->toBeTrue("the sweep names a root that is not a directory: {$name}");
    }

    $result = documentationReaderScan(array_values($roots), $repoRoot);

    // NON-VACUITY, PER ROOT AND NOT AS A TOTAL. A union floor is cleared by the other roots
    // while one of them contributes nothing — the shape every lint in this repository has had at
    // least once, one level up. `config/` and `database/` legitimately measure 0 today, so the
    // floor is asserted only where there is something to count, and app/ — the root that matters
    // most and the largest single non-test contributor — carries a named number.
    $perRoot = [];
    foreach ($roots as $name => $path) {
        $perRoot[$name] = documentationReaderScan([$path], $repoRoot)['callSites'];
    }

    // NAMED, not indexed. A root removed from the list must fail as a sentence a reader can act
    // on, not as an "Undefined array key" from deep inside the framework's error handler.
    expect(array_key_exists('app', $perRoot))->toBeTrue('app/ is not among the roots this sweep covers');
    expect($perRoot['app'])->toBeGreaterThan(20, 'app/ contributes almost nothing — the sweep has gone blind over it');

    foreach (['bin', 'tests', 'routes', 'bootstrap'] as $name) {
        expect($perRoot[$name])->toBeGreaterThan(0, "{$name}/ contributes no call sites at all");
    }

    expect($result['callSites'])->toBeGreaterThan(50);

    $rendered = implode("\n", array_map(
        fn ($v) => "  {$v['file']}:{$v['line']}  {$v['idiom']}  →  {$v['path']}",
        $result['violations']
    ));

    expect($result['violations'])->toBe([],
        "A file under docs/ with an extension bin/is-docs-only-push SKIPS is read by code, so a push\n"
        ."editing it would not run the gate. Either move the file out of docs/, or give it an\n"
        ."extension the skip rule refuses (the .json at docs/runbooks/ is the worked example):\n\n"
        .$rendered
    );
});

it('resolves a class constant — the shape the original grep could not see', function () {
    // THE BITE. The path lives in a constant, exactly as
    // app/Console/Commands/AuthzObservations.php:22 holds it, and the class is not autoloadable,
    // exactly as a fixture in a temp directory is not. If the constant resolution regresses, this
    // arm goes green-by-blindness — so it asserts the violation is FOUND, and names the path.
    $result = documentationReaderFixture(<<<'PHP'
<?php

class Fixture
{
    public const NOTES_PATH = 'docs/handoff/notes.md';

    public function read(): string
    {
        return (string) file_get_contents(base_path(self::NOTES_PATH));
    }
}
PHP);

    expect($result['violations'])->toHaveCount(1)
        ->and($result['violations'][0]['path'])->toBe('docs/handoff/notes.md')
        ->and($result['violations'][0]['idiom'])->toBe('base_path');
});

it('catches an inline literal too, and a __DIR__ path that climbs into docs/', function () {
    $inline = documentationReaderFixture(<<<'PHP'
<?php
$a = file_get_contents(base_path('docs/handoff/inline.md'));
PHP);

    expect($inline['violations'])->toHaveCount(1)
        ->and($inline['violations'][0]['path'])->toBe('docs/handoff/inline.md');

    // __DIR__ resolves to the fixture's own directory, which is OUTSIDE the repository, so a
    // climb from there normalises to null rather than to a docs/ path. What this proves is that
    // the __DIR__ idiom is COLLECTED and RESOLVED rather than silently written off — the
    // unresolved counter must not have gone up.
    $dir = documentationReaderFixture(<<<'PHP'
<?php
$b = file_get_contents(__DIR__.'/../docs/handoff/climbed.md');
PHP);

    expect($dir['callSites'])->toBeGreaterThan(0)
        ->and($dir['unresolved'])->toBe(0);
});

it('does NOT flag a docs/ path whose extension the skip rule already refuses', function () {
    // This is the real `docs/runbooks/authz-observation-classifications.json` in miniature. It IS
    // read by code, and that is fine: the skip rule refuses .json, so a push editing it runs the
    // full gate. Flagging it would make the invariant demand something the rule does not need.
    $result = documentationReaderFixture(<<<'PHP'
<?php

class Fixture
{
    public const DATA_PATH = 'docs/runbooks/classifications.json';

    public function read(): string
    {
        return (string) file_get_contents(base_path(self::DATA_PATH));
    }
}
PHP);

    expect($result['violations'])->toBe([]);

    // …AND NOT BECAUSE THE SITE WAS MISSED. The same fixture with one character changed — the
    // extension — is flagged. The verdict turns on the format and on nothing else.
    $twin = documentationReaderFixture(<<<'PHP'
<?php

class Fixture
{
    public const DATA_PATH = 'docs/runbooks/classifications.md';

    public function read(): string
    {
        return (string) file_get_contents(base_path(self::DATA_PATH));
    }
}
PHP);

    expect($twin['violations'])->toHaveCount(1)
        ->and($twin['violations'][0]['path'])->toBe('docs/runbooks/classifications.md');
});

it('admits what it could not resolve rather than passing it', function () {
    // A runtime-assembled path is invisible to this sweep, and the docblock says so. What must
    // NOT happen is it being counted as resolved-and-clean.
    $result = documentationReaderFixture(<<<'PHP'
<?php
$name = 'notes.md';
$c = file_get_contents(base_path('docs/handoff/'.$name));
PHP);

    expect($result['violations'])->toBe([])
        ->and($result['unresolved'])->toBeGreaterThan(0);
});

it('leaves a CONCATENATED class constant unresolved instead of resolving its first fragment', function () {
    // MEASURED BEFORE THE FIX, and it is the sharpest failure this file can have: not a missed
    // reader, but a reader reported RESOLVED AND CLEAN, absent from the unresolved tally that
    // this file offers as its own honesty measure.
    //
    //   const P = 'docs/notes'.'.md'    →  resolved 'docs/notes'  unresolved=0  violations=0
    //   const P = 'docs/' . 'notes.md'  →  resolved 'docs/'       unresolved=0  violations=0
    //
    // Both are real readers of docs/notes.md. Neither is visible anywhere in the output.
    $tail = documentationReaderFixture(<<<'PHP'
<?php

class Fixture
{
    public const P = 'docs/notes'.'.md';

    public function read(): string
    {
        return (string) file_get_contents(base_path(self::P));
    }
}
PHP);

    $head = documentationReaderFixture(<<<'PHP'
<?php

class Fixture
{
    public const P = 'docs/' . 'notes.md';

    public function read(): string
    {
        return (string) file_get_contents(base_path(self::P));
    }
}
PHP);

    // Still not a violation — the path is not KNOWN, and inventing one would be the same defect
    // pointing the other way. What must be true is that it is ADMITTED.
    expect($tail['violations'])->toBe([])
        ->and($tail['unresolved'])->toBeGreaterThan(0)
        ->and($head['violations'])->toBe([])
        ->and($head['unresolved'])->toBeGreaterThan(0);

    // THE CONTROL, and it is what stops this arm passing because resolution broke entirely: the
    // same constant written as one literal still resolves and still reds.
    $plain = documentationReaderFixture(<<<'PHP'
<?php

class Fixture
{
    public const P = 'docs/notes.md';

    public function read(): string
    {
        return (string) file_get_contents(base_path(self::P));
    }
}
PHP);

    expect($plain['violations'])->toHaveCount(1)
        ->and($plain['violations'][0]['path'])->toBe('docs/notes.md')
        ->and($plain['unresolved'])->toBe(0);
});

it('collects a fully-qualified global call at its own site', function () {
    // `\base_path('docs/x.md')` is ONE T_NAME_FULLY_QUALIFIED token, not `\` plus T_STRING, so a
    // collector requiring T_STRING never saw it. Before the fix the enclosing file_get_contents
    // still landed it in the unresolved half rather than the clean half — the safe direction by
    // accident, not by design. Now it is collected at its own site and RESOLVED, which is the
    // difference between "we could not tell" and "we found it".
    $result = documentationReaderFixture(<<<'PHP'
<?php
$a = file_get_contents(\base_path('docs/x.md'));
PHP);

    expect($result['violations'])->toHaveCount(1)
        ->and($result['violations'][0]['path'])->toBe('docs/x.md')
        ->and($result['violations'][0]['idiom'])->toBe('base_path');
});

it('puts every specimen it cannot see in the UNRESOLVED half, never in the clean half', function () {
    // THE SIX PLANTED PROBES, and the acceptance criterion is NOT that all six go red. Three of
    // them this sweep genuinely cannot follow, and the docblock says so. What it may never do is
    // count one of them as resolved-and-clean — that is the only outcome that turns a blind spot
    // into a false assurance.
    $specimens = [
        // seen, resolved, red — the two the fixes above added, plus the plain control
        'fully-qualified \base_path' => ["<?php\n\$a = file_get_contents(\\base_path('docs/x.md'));", 'violation'],
        'plain literal control' => ["<?php\n\$a = file_get_contents(base_path('docs/x.md'));", 'violation'],

        // seen, honestly unresolvable
        'self:: constant by concatenation' => ["<?php\nclass F { const P = 'docs/'.'x.md'; public function r() { return file_get_contents(base_path(self::P)); } }", 'unresolved'],
        'app()->basePath()' => ["<?php\n\$a = file_get_contents(app()->basePath('docs/x.md'));", 'unresolved'],
        'heredoc argument' => ["<?php\n\$a = file_get_contents(base_path(<<<'T'\ndocs/x.md\nT));", 'unresolved'],
        'property \$this->p' => ["<?php\nclass F { private \$p = 'docs/x.md'; public function r() { return file_get_contents(base_path(\$this->p)); } }", 'unresolved'],
    ];

    foreach ($specimens as $label => [$source, $expected]) {
        $result = documentationReaderFixture($source);

        if ($expected === 'violation') {
            expect($result['violations'])->toHaveCount(1, "{$label}: expected a violation");

            continue;
        }

        expect($result['violations'])->toBe([], "{$label}: must not be reported as a violation on a guessed path")
            ->and($result['unresolved'])->toBeGreaterThan(0, "{$label}: was counted CLEAN — a blind spot presented as an assurance");
    }
});
