<?php

/*
 * NOTHING IN THE CODEBASE MAY READ A FILE THAT bin/is-docs-only-push WOULD SKIP.
 *
 * WHY THIS EXISTS, and it is not a hypothetical. The first cut of the docs-only skip rule was
 * by LOCATION — every changed path under `docs/`. It shipped with a claim in its report that
 * no test and no lint in this repository reads a file under `docs/`, and that claim was FALSE.
 * `app/Console/Commands/AuthzObservations.php:22` holds
 * `const CLASSIFICATIONS_PATH = 'docs/runbooks/authz-observation-classifications.json'`,
 * resolves it through `base_path()` at :149 and reads it at :155;
 * `tests/Feature/Rbac/AuthzObservationsCommandTest.php` asserts against the real file and goes
 * from 5 passed to 3 passed 2 failed when it is edited the way
 * `docs/runbooks/authz-observation-review.md` step 3 tells a reviewer to edit it. That push was
 * judged documentation and skipped the gate.
 *
 * THE CLAIM WAS FALSE BECAUSE OF HOW IT WAS CHECKED. It came from `grep -rn "docs/" tests/`,
 * which matches the literal string `docs/` in a file — and the path is a CLASS CONSTANT, so the
 * only file that mattered was invisible at step one:
 * `grep -c "docs/" tests/Feature/Rbac/AuthzObservationsCommandTest.php` prints `0`.
 *
 * So the invariant is not a claim about today's tree any more; it is this test. It enumerates
 * every `base_path(` / `realpath(` / `__DIR__` / `Storage::` / `File::get` / `file_get_contents(`
 * / `fopen(` call site across app, bin, tests, config, database, routes and bootstrap, RESOLVES
 * THE FIRST ARGUMENT — class constants included, which is the whole point — and fails if any of
 * them lands on a path under `docs/` carrying an extension bin/is-docs-only-push would skip.
 *
 * The allowlist is READ OUT OF THE SCRIPT rather than restated here, so the two cannot drift.
 * A path under docs/ that the skip rule REFUSES (the .json above, a .txt drive log, a .pdf
 * capture) is not a violation: the gate runs for those pushes, which is the entire point of the
 * rule being by format.
 *
 * WHAT A GREEN HERE DOES NOT PROVE.
 *
 *   - A path built at RUNTIME is invisible. `base_path('docs/'.$name)`, a path assembled from a
 *     config value, an argument, an environment variable or any variable at all resolves to
 *     nothing here and is silently skipped. The count of unresolved call sites is asserted to be
 *     reported, not to be zero.
 *   - An IDIOM THIS SWEEP DOES NOT NAME is invisible. `include`, `require`, `SplFileObject`,
 *     `finfo`, `readfile`, `Symfony\Component\Finder`, a `Process` running `cat`, a
 *     `resource_path()`/`storage_path()` chain that happens to escape upward — none are scanned.
 *     The seven idioms here are the ones the review named, not a proof of closure.
 *   - NON-PHP READERS ARE INVISIBLE. Only `.php` files are tokenised, so a shell script under
 *     `bin/` that does `cat docs/something.md` is not seen. A grep for that would false-positive
 *     on every prose citation in those same scripts, so it is stated as a limit rather than
 *     approximated badly.
 *   - It says nothing about whether reading a documentation file is a good idea; only about
 *     whether the skip rule would let an edit to one through ungated.
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

        $out[$tokens[$name][1]] = documentationReaderLiteral($tokens[$literal][1]);
    }

    return $out;
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

        if (! is_array($token) || $token[0] !== T_STRING) {
            continue;
        }

        $name = $token[1];
        $open = null;
        $idiom = null;

        if (in_array(strtolower($name), $functions, true)) {
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
                $idiom = strtolower($name);
            }
        } elseif (in_array($name, $statics, true)) {
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
        if (! is_array($t) || $t[0] !== T_STRING || ! in_array(strtolower($t[1]), $functions, true)) {
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

/** A constant on a class named in another file, resolved through the autoloader. */
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

it('nothing in the codebase reads a documentation file the skip rule would let through', function () {
    $repoRoot = dirname(__DIR__, 3);

    $result = documentationReaderScan([
        $repoRoot.'/app',
        $repoRoot.'/bin',
        $repoRoot.'/tests',
        $repoRoot.'/config',
        $repoRoot.'/database',
        $repoRoot.'/routes',
        $repoRoot.'/bootstrap',
    ], $repoRoot);

    // NON-VACUITY FIRST. A sweep that stopped finding call sites would report zero violations
    // and read exactly like a clean tree — the failure mode every lint in this repository has
    // had at least once.
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
