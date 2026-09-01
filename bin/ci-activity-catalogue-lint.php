#!/usr/bin/env php
<?php

/**
 * activity-catalogue-lint — the catalogue must describe events that are actually emitted.
 *
 * WHY THIS EXISTS. `fix(activity-log): correct the catalogue to the events actually emitted`
 * (73108ea8) found three declared keys that matched no emitter anywhere in the tree:
 *
 *     declared  permissions.role_assigned / role_revoked   critical + sensitive
 *     emitted   rbac.role_attached / role_detached / permission_attached / permission_detached
 *
 *     declared  auth.login_failed                          warning
 *     emitted   auth.failed_login
 *
 *     declared  authentication -> the config said `auth.password_reset`
 *     emitted   authentication.password_reset
 *
 * All three resolved to the `info` default over roughly 1,800 rows, and two of the three were
 * matched by no sensitive pattern — so privilege grants and password resets were visible to every
 * holder of `activity_log.view`. Nothing went red for the whole period. The resolver arm that was
 * supposed to cover this asserted the DECLARED key against the intended tier and passed throughout,
 * because it never touched a row.
 *
 * That correction was a fix. This is the floor under it: a fourth transposition now fails a gate.
 *
 * THE RULES.
 *
 *   R1  Enumerate every emitted "{log_name}.{event}", statically. Two producers:
 *       the `activity()` helper chain, and Eloquent models carrying Spatie's `LogsActivity`.
 *
 *   R2  `catalogue-key-not-emitted` — every non-wildcard key declared in
 *       config/activity_log_severity.php or config/activity_log_sensitive.php must match at least
 *       one emitter, UNLESS the same config file's `pending_emitters` map declares it with a
 *       non-empty reason. NOT baselined, deliberately: the correction commit made this green, and a
 *       baseline here would admit the next transposition on the day it was written.
 *
 *   R3  `emitter-has-no-tier` — every emitter matching no tier pattern must appear in
 *       config/activity_log_severity.php's `info_exemptions` map with a non-empty reason. Falling
 *       through to `info` is a legitimate answer for most events; it is not a legitimate answer
 *       ARRIVED AT BY NOBODY, which is what produced the three keys above. NOT baselined, same
 *       reason as R2.
 *
 *   R4  `dead-static-log-name` — no model may declare `protected static $logName`. Spatie never
 *       reads it: `LogsActivity::getLogNameToUse()` reads `$this->activitylogOptions->logName` and
 *       otherwise returns `config('activitylog.default_log_name')`, so a model declaring it lands in
 *       `default` while reading as configured. BASELINED, shrink-only — 23 models declare it today
 *       and that cleanup is its own commit with its own ticket
 *       (docs/handoff/tickets/model-log-name-is-declared-as-a-static-property-spatie-never-reads.md).
 *
 *   R5  The lint FAILS WHEN ITS OWN SCAN FINDS NOTHING. See below; this is the non-negotiable one.
 *
 *   R6  `dynamic-event` / `dynamic-log-name` — a non-constant argument to `activity()` or
 *       `->event()` FAILS. It is not skipped. A string literal is fine and a resolvable class
 *       constant is fine; anything else must carry an `@activity-emits` declaration (below).
 *
 * R5 IS THE ONE THAT MAKES THE REST MEAN ANYTHING, and it is here because the sibling instrument
 * did not have it. `bin/ci-tsc-ratchet.php` reads absent input as zero errors and prints
 * "type errors DECREASED (good!)" — an instrument answering a question it was given no input for,
 * which is worse than no instrument, because a green from it stops anyone looking. So:
 *
 *   - zero PHP files scanned            -> exit 2, "could not determine"
 *   - zero emitters found               -> exit 2
 *   - fewer emitters than EMITTER_FLOOR -> exit 2 (a broken regex reds instead of passing)
 *   - either config file unreadable, or
 *     not returning the expected shape  -> exit 2
 *
 * and in every one of those cases NO success line is printed. Exit 2 is "could not determine",
 * distinct from exit 1 "found violations" — the same three-way convention `bin/landed` uses.
 *
 * EMITTER_FLOOR IS A FLOOR, NOT A COUNT. It is deliberately well below the measured total: its job
 * is to catch a scan that has broken, not to pin the tree. A real removal of emitters below the
 * floor should fail and be argued, which is why it is not derived from the tree it measures.
 *
 * `@activity-emits` — THE ESCAPE HATCH FOR R6, AND WHY IT IS NOT A SKIP. Two sites in this tree
 * choose their event at runtime from a set of literals:
 *
 *     app/Listeners/LogRbacChange.php     ->event($action)  from a match over four literals
 *     app/Services/GuardianService.php    ->event($event)   a private helper's parameter
 *
 * Neither is a constant, and neither can be resolved by any honest static reading. The options were
 * to skip them (R6's own objection: silence about what you cannot parse), to fail them permanently
 * (which would force the events to be inlined at every call site, making the code worse to save the
 * lint), or to make the author DECLARE what the site emits, in a form this lint reads and then holds
 * to R2 and R3 like any other emitter:
 *
 *     // @activity-emits rbac.role_attached
 *
 * The declaration is not trusted as documentation — it becomes an emitter, and if the catalogue does
 * not classify it, R3 reds. What it cannot do is check that the site really emits what it claims;
 * that is the residual, and it is why the hatch is deliberately awkward and rare.
 *
 * WHAT A GREEN DOES NOT PROVE.
 *
 *   - That a declared TIER is the right tier. This lint checks that a key is emitted and that an
 *     emitter is classified; it has no opinion on critical-versus-warning.
 *   - That an `@activity-emits` line is true.
 *   - That an emitter is REACHED. A chain inside dead code is still an emitter here.
 *   - That the runtime log name matches the static one. A model's `useLogName()` is read as a
 *     literal; a model computing it would fail R6 rather than being read wrongly.
 *
 * Usage:
 *   php bin/ci-activity-catalogue-lint.php            # check
 *   php bin/ci-activity-catalogue-lint.php generate   # (re)write the R4 baseline
 *
 * Exit: 0 clean · 1 violations · 2 could not determine.
 */
$root = dirname(__DIR__);
$baselinePath = $root.'/activity-catalogue-lint-baseline.txt';
$mode = $argv[1] ?? 'check';

/** Where emitters are read FROM. tests/ is absent: a test's activity() row is a fixture, not an emitter. */
const SCANNED_DIRS = ['app', 'database', 'routes'];

/**
 * The plausibility floor on the emitter count. MEASURED at 44 distinct keys across 833 files on the
 * branch that added this lint (2026-09-01); set at 25, comfortably below, so ordinary churn does not
 * touch it and a scan that has silently stopped matching does.
 *
 * Deliberately NOT derived from the tree — a floor that recomputes itself from what it measures is
 * the tsc ratchet's defect wearing a different hat. It is a floor, not a count: it does not pin the
 * tree and cannot notice a single emitter disappearing. Its whole job is to make a BROKEN SCAN look
 * different from a clean one.
 */
const EMITTER_FLOOR = 25;

/** Spatie's default when a model declares no useLogName(). Mirrors config/activitylog.php. */
const DEFAULT_LOG_NAME = 'default';

/** The model events Spatie records by default. */
const MODEL_EVENTS = ['created', 'updated', 'deleted'];

/** Why each rule is a defect — printed per finding, so a message names the mechanism. */
const RULE_REASONS = [
    'catalogue-key-not-emitted' => 'is declared in the catalogue but NOTHING emits it — correct the key to the emitted name, or declare it under `pending_emitters` with a reason',
    'emitter-is-unclassified' => 'is emitted but matches no tier — classify it, or declare it under `info_exemptions` in config/activity_log_severity.php with a reason',
    'exemption-reason-empty' => 'is declared with an empty reason — an exemption with no reason is the wallpaper this lint exists to remove',
    'exemption-is-stale' => 'is declared as an exemption but no longer needs one (it is classified, or nothing emits it) — remove the entry, or a real regression passes silently under it',
    'dynamic-event' => 'passes a non-constant event to ->event() — use a string literal or a class constant, or declare the site with `// @activity-emits log_name.event`',
    'dynamic-log-name' => 'passes a non-constant log name to activity() — use a string literal or a class constant',
    'dead-static-log-name' => 'declares `protected static $logName`, which Spatie never reads (LogsActivity::getLogNameToUse reads activitylogOptions->logName) — use `->useLogName()` inside getActivitylogOptions()',
];

// ---------------------------------------------------------------------------
// Tokenizer helpers. token_get_all(), not a regex: R6 requires telling a literal
// from an expression, and a regex that cannot tell them apart would have to pick
// one of the two failure modes R6 forbids.
// ---------------------------------------------------------------------------

/**
 * Normalise token_get_all output to [id|null, text, line] and drop nothing, so offsets stay usable.
 *
 * @return array<int, array{0: int|null, 1: string, 2: int}>
 */
function tokenize(string $src): array
{
    $out = [];
    foreach (token_get_all($src) as $t) {
        $out[] = is_array($t) ? [$t[0], $t[1], $t[2]] : [null, $t, 0];
    }

    // A single-character token carries no line number; inherit the previous one so findings can
    // cite a line even when the defect sits on punctuation.
    $line = 1;
    foreach ($out as $i => $t) {
        if ($t[2] > 0) {
            $line = $t[2];
        } else {
            $out[$i][2] = $line;
        }
    }

    return $out;
}

/** @param array<int, array{0: int|null, 1: string, 2: int}> $tokens */
function isSkippable(array $tokens, int $i): bool
{
    $id = $tokens[$i][0] ?? null;

    return $id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT;
}

/**
 * Index of the next significant token at or after $i, or null.
 *
 * @param  array<int, array{0: int|null, 1: string, 2: int}>  $tokens
 */
function nextSignificant(array $tokens, int $i): ?int
{
    for ($n = count($tokens); $i < $n; $i++) {
        if (! isSkippable($tokens, $i)) {
            return $i;
        }
    }

    return null;
}

/**
 * Index of the previous significant token at or before $i, or null.
 *
 * @param  array<int, array{0: int|null, 1: string, 2: int}>  $tokens
 */
function prevSignificant(array $tokens, int $i): ?int
{
    for (; $i >= 0; $i--) {
        if (! isSkippable($tokens, $i)) {
            return $i;
        }
    }

    return null;
}

/**
 * Index of the `)` matching the `(` at $open.
 *
 * @param  array<int, array{0: int|null, 1: string, 2: int}>  $tokens
 */
function matchParen(array $tokens, int $open): ?int
{
    $depth = 0;
    for ($i = $open, $n = count($tokens); $i < $n; $i++) {
        $text = $tokens[$i][1];
        if ($tokens[$i][0] === null && ($text === '(' || $text === '[' || $text === '{')) {
            $depth++;
        } elseif ($tokens[$i][0] === null && ($text === ')' || $text === ']' || $text === '}')) {
            $depth--;
            if ($depth === 0) {
                return $i;
            }
        } elseif ($tokens[$i][0] === T_CURLY_OPEN || $tokens[$i][0] === T_DOLLAR_OPEN_CURLY_BRACES) {
            $depth++;
        }
    }

    return null;
}

/**
 * Significant tokens strictly between $open and its matching close.
 *
 * @param  array<int, array{0: int|null, 1: string, 2: int}>  $tokens
 * @return array<int, array{0: int|null, 1: string, 2: int}>
 */
function argTokens(array $tokens, int $open, int $close): array
{
    $out = [];
    for ($i = $open + 1; $i < $close; $i++) {
        if (! isSkippable($tokens, $i)) {
            $out[] = $tokens[$i];
        }
    }

    return $out;
}

/**
 * Every `const NAME = 'literal';` and `NAME = 'literal'` class constant in a file, by short name.
 *
 * Short-name keyed on purpose: `self::AWARDED`, `static::AWARDED` and `AwardStudentDiscount::AWARDED`
 * all resolve to the same declaration when the citation is in the declaring file, and a cross-file
 * constant is left UNRESOLVED rather than guessed at.
 *
 * @param  array<int, array{0: int|null, 1: string, 2: int}>  $tokens
 * @return array<string, string>
 */
function constantsIn(array $tokens): array
{
    $out = [];
    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        if ($tokens[$i][0] !== T_CONST) {
            continue;
        }
        $name = nextSignificant($tokens, $i + 1);
        if ($name === null || $tokens[$name][0] !== T_STRING) {
            continue;
        }
        $eq = nextSignificant($tokens, $name + 1);
        if ($eq === null || $tokens[$eq][1] !== '=') {
            continue;
        }
        $value = nextSignificant($tokens, $eq + 1);
        if ($value === null || $tokens[$value][0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }
        $after = nextSignificant($tokens, $value + 1);
        if ($after === null || $tokens[$after][1] !== ';') {
            continue;  // an expression, not a plain literal — leave it unresolved
        }
        $out[$tokens[$name][1]] = trim($tokens[$value][1], "'\"");
    }

    return $out;
}

/**
 * Resolve a call's argument list to a literal string.
 *
 * Returns ['ok', value] · ['empty', null] when there are no arguments · ['dynamic', null] when the
 * argument is an expression this lint refuses to guess at.
 *
 * @param  array<int, array{0: int|null, 1: string, 2: int}>  $args
 * @param  array<string, string>  $constants
 * @return array{0: string, 1: string|null}
 */
function resolveLiteral(array $args, array $constants): array
{
    if ($args === []) {
        return ['empty', null];
    }

    if (count($args) === 1 && $args[0][0] === T_CONSTANT_ENCAPSED_STRING) {
        return ['ok', trim($args[0][1], "'\"")];
    }

    // Foo::BAR / self::BAR / static::BAR — three tokens, the middle one `::`.
    if (count($args) === 3
        && $args[1][0] === T_DOUBLE_COLON
        && $args[2][0] === T_STRING
        && isset($constants[$args[2][1]])
    ) {
        return ['ok', $constants[$args[2][1]]];
    }

    return ['dynamic', null];
}

/**
 * `@activity-emits log_name.event` declarations, by the line they appear on.
 *
 * Read from the raw source rather than from tokens so a `//` line comment, a `*` docblock line and
 * a bare annotation all work the same way.
 *
 * @return array<int, list<string>> line number => keys declared on or above it
 */
function emitsAnnotations(string $src): array
{
    $out = [];
    foreach (explode("\n", $src) as $i => $line) {
        if (preg_match_all('/@activity-emits\s+([A-Za-z0-9_.*]+)/', $line, $m)) {
            $out[$i + 1] = $m[1];
        }
    }

    return $out;
}

/**
 * How far above a dynamic site an `@activity-emits` line may sit and still govern it.
 *
 * Twenty-five, which is long enough to reach the method docblock above a `match` expression and
 * short enough that an annotation in a NEIGHBOURING method cannot silently cover a site it was
 * never written for.
 */
const ANNOTATION_REACH = 25;

/**
 * The `@activity-emits` keys governing a site on $line: any annotation within the preceding
 * ANNOTATION_REACH lines, or on the line itself.
 *
 * @param  array<int, list<string>>  $annotations
 * @return list<string>
 */
function annotationsFor(array $annotations, int $line): array
{
    $out = [];
    for ($l = $line - ANNOTATION_REACH; $l <= $line; $l++) {
        foreach ($annotations[$l] ?? [] as $key) {
            $out[] = $key;
        }
    }

    return $out;
}

// ---------------------------------------------------------------------------
// The scan.
// ---------------------------------------------------------------------------

/** @return list<string> repo-relative paths */
function scannedFiles(string $root): array
{
    $out = [];
    foreach (SCANNED_DIRS as $dir) {
        $base = $root.'/'.$dir;
        if (! is_dir($base)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $out[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
    }
    sort($out);

    return $out;
}

/**
 * @param  array<int, array{0: int|null, 1: string, 2: int}>  $tokens
 * @return bool whether the class body uses Spatie's LogsActivity trait
 */
function usesLogsActivityTrait(array $tokens): bool
{
    // BRACE DEPTH, not just the name. `use Spatie\Activitylog\Traits\LogsActivity;` at the top of a
    // file is an IMPORT and says nothing about the class — app/Finance/Actions/AwardStudentDiscount.php
    // imports it to write `{@see LogsActivity}` in a docblock and is not a model at all. Only a
    // `use LogsActivity;` inside a class body applies the trait, so only depth > 0 counts.
    $depth = 0;
    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        $id = $tokens[$i][0];
        $text = $tokens[$i][1];

        if ($id === null && $text === '{') {
            $depth++;

            continue;
        }
        if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
            $depth++;

            continue;
        }
        if ($id === null && $text === '}') {
            $depth--;

            continue;
        }
        if ($id !== T_USE || $depth === 0) {
            continue;
        }

        for ($j = $i + 1; $j < $n; $j++) {
            $t = $tokens[$j][1];
            if ($t === ';' || $t === '{') {
                break;
            }
            if ($tokens[$j][0] === T_STRING && $t === 'LogsActivity') {
                return true;
            }
        }
    }

    return false;
}

/**
 * `protected static $logName` declarations, as [line, className].
 *
 * @param  array<int, array{0: int|null, 1: string, 2: int}>  $tokens
 * @return list<array{0: int, 1: string}>
 */
function deadStaticLogNames(array $tokens): array
{
    $out = [];
    $class = '?';
    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        if ($tokens[$i][0] === T_CLASS) {
            $name = nextSignificant($tokens, $i + 1);
            if ($name !== null && $tokens[$name][0] === T_STRING) {
                $class = $tokens[$name][1];
            }
        }
        if ($tokens[$i][0] !== T_STATIC) {
            continue;
        }
        $var = nextSignificant($tokens, $i + 1);
        // `protected static string $logName` — step over an optional type.
        if ($var !== null && $tokens[$var][0] === T_STRING) {
            $var = nextSignificant($tokens, $var + 1);
        }
        if ($var !== null && $tokens[$var][0] === T_VARIABLE && $tokens[$var][1] === '$logName') {
            $out[] = [$tokens[$var][2], $class];
        }
    }

    return $out;
}

$files = scannedFiles($root);

// R5, first arm: an empty scan is not a clean tree.
if ($files === []) {
    fwrite(STDERR, 'activity-catalogue-lint: COULD NOT DETERMINE — the scan found no PHP files under '.implode(', ', SCANNED_DIRS).".\n");
    fwrite(STDERR, "  An instrument that answers a question it was given no input for is worse than no instrument.\n");
    exit(2);
}

/** @var array<string, list<string>> emitted key => list of "path:line" sites */
$emitters = [];
/** @var array<string, int> violation key => count */
$found = [];

$addEmitter = function (string $key, string $site) use (&$emitters): void {
    $emitters[$key][] = $site;
};

$addFinding = function (string $rule, string $rel, string $subject) use (&$found): void {
    $key = $rule."\t".$rel."\t".$subject;
    $found[$key] = ($found[$key] ?? 0) + 1;
};

foreach ($files as $rel) {
    $src = @file_get_contents($root.'/'.$rel);
    if ($src === false) {
        fwrite(STDERR, "activity-catalogue-lint: COULD NOT DETERMINE — could not read {$rel}.\n");
        exit(2);
    }

    $tokens = tokenize($src);
    $constants = constantsIn($tokens);
    $annotations = emitsAnnotations($src);

    // ---- R1a: activity() helper chains -------------------------------------
    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        if ($tokens[$i][0] !== T_STRING || $tokens[$i][1] !== 'activity') {
            continue;
        }

        // Not a call to the helper: `function activity(`, `$this->activity(`, `Foo::activity(`.
        $prev = prevSignificant($tokens, $i - 1);
        if ($prev !== null && in_array($tokens[$prev][0], [T_FUNCTION, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW], true)) {
            continue;
        }

        $open = nextSignificant($tokens, $i + 1);
        if ($open === null || $tokens[$open][1] !== '(') {
            continue;
        }
        $close = matchParen($tokens, $open);
        if ($close === null) {
            continue;
        }

        $line = $tokens[$i][2];
        $site = $rel.':'.$line;

        // Walk the fluent chain.
        $chain = [];
        $cursor = $close;
        while (true) {
            $arrow = nextSignificant($tokens, $cursor + 1);
            if ($arrow === null
                || ! in_array($tokens[$arrow][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                break;
            }
            $method = nextSignificant($tokens, $arrow + 1);
            if ($method === null || $tokens[$method][0] !== T_STRING) {
                break;
            }
            $mOpen = nextSignificant($tokens, $method + 1);
            if ($mOpen === null || $tokens[$mOpen][1] !== '(') {
                break;
            }
            $mClose = matchParen($tokens, $mOpen);
            if ($mClose === null) {
                break;
            }
            $chain[$tokens[$method][1]] = argTokens($tokens, $mOpen, $mClose);
            $cursor = $mClose;
        }

        // `activity()->withoutLogs(...)` SUPPRESSES logging; it is not an emitter. Everything else
        // is, INCLUDING a chain with no ->log() in it — two sites in this tree assign the builder
        // and call ->log() on the variable later (AwardStudentDiscount, StudentRecordAccessLog),
        // and requiring ->log() in the chain would silently drop both.
        if (array_key_exists('withoutLogs', $chain)) {
            continue;
        }

        [$logStatus, $logName] = resolveLiteral(argTokens($tokens, $open, $close), $constants);
        [$evStatus, $event] = array_key_exists('event', $chain)
            ? resolveLiteral($chain['event'], $constants)
            : ['empty', null];

        $declared = annotationsFor($annotations, $line);

        if ($logStatus === 'dynamic' || $evStatus === 'dynamic') {
            if ($declared === []) {
                // R6: FAIL, never skip. A site this lint cannot read is a hole exactly its own size.
                $addFinding(
                    $logStatus === 'dynamic' ? 'dynamic-log-name' : 'dynamic-event',
                    $rel,
                    'line '.$line,
                );

                continue;
            }
            foreach ($declared as $key) {
                $addEmitter($key, $site);
            }

            continue;
        }

        if ($logStatus === 'empty') {
            $logName = DEFAULT_LOG_NAME;
        }

        // No ->event() at all: the row carries a NULL event, and the read path keys it as
        // `unknown` (ActivityPatternMatcher::key). Enumerated under the same name so the catalogue
        // has something to classify rather than a silent hole.
        $addEmitter($logName.'.'.($evStatus === 'empty' ? 'unknown' : $event), $site);
    }

    // ---- R1b: models carrying LogsActivity ---------------------------------
    if (usesLogsActivityTrait($tokens)) {
        $logName = DEFAULT_LOG_NAME;
        $dynamic = false;
        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            if ($tokens[$i][0] !== T_STRING || $tokens[$i][1] !== 'useLogName') {
                continue;
            }
            $open = nextSignificant($tokens, $i + 1);
            if ($open === null || $tokens[$open][1] !== '(') {
                continue;
            }
            $close = matchParen($tokens, $open);
            if ($close === null) {
                continue;
            }
            [$status, $value] = resolveLiteral(argTokens($tokens, $open, $close), $constants);
            if ($status === 'ok') {
                $logName = $value;
            } elseif ($status === 'dynamic') {
                $dynamic = true;
                $addFinding('dynamic-log-name', $rel, 'useLogName() on line '.$tokens[$i][2]);
            }
        }

        if (! $dynamic) {
            foreach (MODEL_EVENTS as $event) {
                $addEmitter($logName.'.'.$event, $rel);
            }
        }
    }

    // ---- R4: the dead static property --------------------------------------
    foreach (deadStaticLogNames($tokens) as [$line, $class]) {
        $addFinding('dead-static-log-name', $rel, $class);
    }
}

// R5, second and third arms.
if ($emitters === []) {
    fwrite(STDERR, 'activity-catalogue-lint: COULD NOT DETERMINE — scanned '.count($files)." file(s) and found NO emitters.\n");
    fwrite(STDERR, "  Either every emitter has been removed, or the scan is broken. Both need a human.\n");
    exit(2);
}

$emitterCount = count($emitters);
if ($emitterCount < EMITTER_FLOOR) {
    fwrite(STDERR, "activity-catalogue-lint: COULD NOT DETERMINE — found {$emitterCount} distinct emitter(s), below the floor of ".EMITTER_FLOOR.".\n");
    fwrite(STDERR, "  The floor exists so a scan that has stopped matching reds instead of reporting a clean tree.\n");
    fwrite(STDERR, "  If emitters were genuinely removed, lower EMITTER_FLOOR in this file and say why.\n");
    exit(2);
}

// ---------------------------------------------------------------------------
// The catalogue.
// ---------------------------------------------------------------------------

/**
 * Load a config file by including it, and refuse to proceed on anything that is not the expected
 * shape. R5 again: a config that silently reads as `[]` would make every key vacuously fine.
 *
 * @param  list<string>  $requiredKeys
 * @return array<string, mixed>
 */
function loadConfig(string $path, array $requiredKeys): array
{
    if (! is_file($path) || ! is_readable($path)) {
        fwrite(STDERR, 'activity-catalogue-lint: COULD NOT DETERMINE — cannot read '.basename($path)."\n");
        exit(2);
    }
    $value = require $path;
    if (! is_array($value) || $value === []) {
        fwrite(STDERR, 'activity-catalogue-lint: COULD NOT DETERMINE — '.basename($path)." did not return a non-empty array.\n");
        exit(2);
    }
    foreach ($requiredKeys as $key) {
        if (! array_key_exists($key, $value)) {
            fwrite(STDERR, 'activity-catalogue-lint: COULD NOT DETERMINE — '.basename($path)." has no `{$key}` key.\n");
            exit(2);
        }
    }

    return $value;
}

$severityPath = $root.'/config/activity_log_severity.php';
$sensitivePath = $root.'/config/activity_log_sensitive.php';

$severity = loadConfig($severityPath, ['critical', 'warning', 'notice']);
$sensitive = loadConfig($sensitivePath, ['entries', 'fields']);

/** The three tiers the resolver actually iterates — ActivitySeverityService::TIERS. */
const TIERS = ['critical', 'warning', 'notice'];

/** Same wildcard semantics as App\Services\ActivityLog\ActivityPatternMatcher::matches(). */
function patternMatches(string $pattern, string $key): bool
{
    return (bool) preg_match('/^'.str_replace('\*', '.*', preg_quote($pattern, '/')).'$/i', $key);
}

/**
 * Every declared key, as pattern => "configFile (tierOrList)".
 *
 * @return array<string, string>
 */
$declared = [];
foreach (TIERS as $tier) {
    foreach ((array) ($severity[$tier] ?? []) as $pattern) {
        if (is_string($pattern)) {
            $declared[$pattern] = 'config/activity_log_severity.php ('.$tier.')';
        }
    }
}
foreach ((array) ($sensitive['entries'] ?? []) as $pattern) {
    if (is_string($pattern)) {
        $declared[$pattern] = 'config/activity_log_sensitive.php (entries)';
    }
}

/**
 * The two declared-ahead-of-an-emitter maps, merged. Each is `key => reason`; an empty reason is a
 * finding in itself, because an exemption nobody had to justify is the wallpaper one layer down.
 *
 * @return array{0: array<string, mixed>, 1: array<string, string>} [reasons, sourceFile]
 */
$pending = [];
$pendingSource = [];
foreach ([[$severity, 'config/activity_log_severity.php'], [$sensitive, 'config/activity_log_sensitive.php']] as [$cfg, $file]) {
    foreach ((array) ($cfg['pending_emitters'] ?? []) as $key => $reason) {
        $pending[$key] = $reason;
        $pendingSource[$key] = $file;
    }
}

$infoExemptions = (array) ($severity['info_exemptions'] ?? []);

// ---- R2: a declared key nothing emits -------------------------------------
foreach ($declared as $pattern => $where) {
    if (str_contains($pattern, '*')) {
        continue;  // a wildcard is a policy, not a claim that a specific event exists
    }
    if (isset($emitters[$pattern])) {
        continue;
    }
    if (array_key_exists($pattern, $pending)) {
        continue;  // declared ahead of an emitter that is owed; the reason is checked below
    }
    $addFinding('catalogue-key-not-emitted', $where, $pattern);
}

// ---- reasons on both exemption maps ---------------------------------------
foreach ($pending as $key => $reason) {
    if (! is_string($reason) || trim($reason) === '') {
        $addFinding('exemption-reason-empty', $pendingSource[$key].' (pending_emitters)', $key);
    }
}
foreach ($infoExemptions as $key => $reason) {
    if (! is_string($reason) || trim($reason) === '') {
        $addFinding('exemption-reason-empty', 'config/activity_log_severity.php (info_exemptions)', (string) $key);
    }
}

// ---- R3: an emitter no tier classifies ------------------------------------
$classified = function (string $key) use ($severity): bool {
    foreach (TIERS as $tier) {
        foreach ((array) ($severity[$tier] ?? []) as $pattern) {
            if (is_string($pattern) && patternMatches($pattern, $key)) {
                return true;
            }
        }
    }

    return false;
};

foreach ($emitters as $key => $sites) {
    if ($classified($key)) {
        continue;
    }
    if (array_key_exists($key, $infoExemptions)) {
        continue;
    }
    $addFinding('emitter-is-unclassified', $sites[0], $key);
}

// ---- an exemption that is no longer needed --------------------------------
// Shrink-lock, in the same spirit as the sibling ratchets: an entry left behind after the thing it
// forgave is gone would silently forgive a re-introduction.
foreach ($infoExemptions as $key => $reason) {
    $key = (string) $key;
    if (! isset($emitters[$key]) || $classified($key)) {
        $addFinding('exemption-is-stale', 'config/activity_log_severity.php (info_exemptions)', $key);
    }
}
foreach ($pending as $key => $reason) {
    if (isset($emitters[$key])) {
        $addFinding('exemption-is-stale', $pendingSource[$key].' (pending_emitters)', (string) $key);
    }
}

ksort($found);

// ---------------------------------------------------------------------------
// The R4 baseline, and the report.
// ---------------------------------------------------------------------------

/** Only R4 is baselined. R2 and R3 are green today and a baseline there would admit the next one. */
const BASELINED_RULES = ['dead-static-log-name'];

$baselineable = [];
$immediate = [];
foreach ($found as $key => $count) {
    [$rule] = explode("\t", $key);
    if (in_array($rule, BASELINED_RULES, true)) {
        $baselineable[$key] = $count;
    } else {
        $immediate[$key] = $count;
    }
}

if ($mode === 'generate') {
    $header = <<<'TXT'
# activity-catalogue-lint baseline — models declaring the dead `protected static $logName`.
# MAY ONLY SHRINK.
#
# Key: rule \t path \t class \t COUNT.
#
# ONLY rule `dead-static-log-name` is baselined. `catalogue-key-not-emitted` and
# `emitter-is-unclassified` are NOT, deliberately: the correction commit made them green, and a
# baseline there would admit the next transposition on the day somebody wrote it.
#
# Spatie never reads `protected static $logName` — LogsActivity::getLogNameToUse() reads
# `$this->activitylogOptions->logName` and otherwise returns config('activitylog.default_log_name'),
# so every model here writes to `default` while reading as configured. Burn one down by moving the
# name into `->useLogName()` inside getActivitylogOptions(), then regenerate:
#
#   php bin/ci-activity-catalogue-lint.php generate
#
# Ticket: docs/handoff/tickets/model-log-name-is-declared-as-a-static-property-spatie-never-reads.md

TXT;
    $body = '';
    foreach ($baselineable as $key => $count) {
        $body .= $key."\t".$count."\n";
    }
    file_put_contents($baselinePath, $header.$body);
    fwrite(STDERR, 'activity-catalogue-lint: wrote '.count($baselineable).' baseline entries to '.basename($baselinePath)."\n");
    exit(0);
}

$baseline = [];
if (is_file($baselinePath)) {
    foreach (file($baselinePath) as $raw) {
        $raw = rtrim($raw, "\r\n");
        if ($raw === '' || str_starts_with($raw, '#')) {
            continue;
        }
        $parts = explode("\t", $raw);
        $count = (int) array_pop($parts);
        $baseline[implode("\t", $parts)] = $count;
    }
}

$new = $immediate;
$grown = [];
$fixed = [];

foreach ($baselineable as $key => $count) {
    if (! isset($baseline[$key])) {
        $new[$key] = $count;
    } elseif ($count > $baseline[$key]) {
        $grown[$key] = [$baseline[$key], $count];
    }
}
foreach ($baseline as $key => $count) {
    $now = $baselineable[$key] ?? 0;
    if ($now < $count) {
        $fixed[$key] = [$count, $now];
    }
}

$render = function (string $key): string {
    [$rule, $where, $subject] = array_pad(explode("\t", $key), 3, '');

    return $where.'  '.$subject.'  ['.$rule.']';
};

if ($new !== [] || $grown !== []) {
    fwrite(STDERR, "\nactivity-catalogue-lint: ".(count($new) + count($grown))." catalogue violation(s) — the catalogue must describe events that are actually emitted:\n");
    foreach ($new as $key => $count) {
        [$rule] = explode("\t", $key);
        fwrite(STDERR, '  '."\u{2717}".' '.$render($key).($count > 1 ? '  x'.$count : '')."\n");
        fwrite(STDERR, '      '.RULE_REASONS[$rule]."\n");
    }
    foreach ($grown as $key => [$was, $now]) {
        [$rule] = explode("\t", $key);
        fwrite(STDERR, '  '."\u{2717}".' '.$render($key).'  baselined '.$was.', now '.$now."\n");
        fwrite(STDERR, '      '.RULE_REASONS[$rule]."\n");
    }
    fwrite(STDERR, "\n  Scanned ".count($files).' file(s); '.$emitterCount." distinct emitted key(s).\n");
    fwrite(STDERR, "  Why this rule exists: three declared keys matched no emitter for months and ~1,800 rows\n");
    fwrite(STDERR, "  resolved to `info`, two of them unmasked. See commit 73108ea8.\n");
    exit(1);
}

if ($fixed !== []) {
    fwrite(STDERR, "\nactivity-catalogue-lint: ".count($fixed)." baselined model(s) fixed (good!) — lock it in by regenerating the baseline:\n");
    foreach ($fixed as $key => [$was, $now]) {
        fwrite(STDERR, '  - '.$render($key).'  baselined '.$was.', now '.$now."\n");
    }
    fwrite(STDERR, "  regenerate: php bin/ci-activity-catalogue-lint.php generate\n");
    exit(1);
}

fwrite(STDERR, 'activity-catalogue-lint: OK — '.$emitterCount.' emitted key(s) across '.count($files).' file(s), '.count($declared).' declared pattern(s), '.count($baselineable)." baselined model(s).\n");
exit(0);
