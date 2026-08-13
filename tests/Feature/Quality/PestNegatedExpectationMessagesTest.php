<?php

/*
 * THE GATE: a custom failure message written under `->not->` never reaches the reader.
 *
 * THE RULE, IN PROSE. Pest's `->not->` is not a matcher — it is a proxy. OppositeExpectation::__call
 * (vendor/pestphp/pest/src/Expectations/OppositeExpectation.php:770-784) runs the POSITIVE assertion
 * and, when that assertion SUCCEEDS — which is exactly when the negated one has failed — it discards
 * the exception and calls `throwExpectationFailedException($name, $arguments)`. That method (`:811-825`)
 * runs `Exporter::shortenedExport()` over EVERY argument, the author's message included, and composes
 * a generic sentence:
 *
 *     Expecting '' not to be '' 'The [opening_balance] entry d…T url.'.
 *
 * So the message is neither the failure description nor printed whole. It is an exported value,
 * truncated in the middle. The matcher declares `string $message` and accepts it without complaint,
 * which is what makes this survive review: the code looks exactly like the working positive form.
 *
 * This test walks tests/ and fails on any `->not-><matcher>(…)` call that supplies an argument
 * landing in a parameter named `message`. It ships with ZERO exemptions and no baseline.
 *
 * THE DISCRIMINATOR IS THE VENDOR'S OWN SIGNATURE, which is the only reason there are no exemptions.
 * It does not guess from the shape of an argument; it reflects Pest\Mixins\Expectation and asks
 * whether the argument at that position is the `$message` parameter. `toContain(...$needles)` has no
 * such parameter, so `->not->toContain('a', 'b')` — two legitimate needles — is not a violation and
 * needs no allowlist to stay green. That case is live in the repository
 * (tests/Feature/Finance/SchemaConventionsTest.php:196) and is correct code.
 *
 * THE RULE IS THE DEFINITION, AND THIS SENTENCE IS THE POINT OF THE PARAGRAPH. Over ONE unchanged
 * tree this defect has been counted three times and come back 4, then 139, then 17 — and every move
 * was a change to the RULE, never a finding about the repository. 4 was a single-line grep that could
 * not see a wrapped call. 139 was this rule with a bug that counted a call's opening parenthesis as an
 * argument, so every `->not->toBeNull()` in the repo — correct code, no message — read as carrying
 * one. 17 was the rule stated correctly. A future widening WILL find more, and that will not mean the
 * repository got worse. Anyone reading a number here as a fact about the tree has read a property of
 * the discriminator instead.
 *
 * WHAT THIS GATE CANNOT SEE, and it is not hypothetical. The rule catches a message passed where a
 * `$message` PARAMETER exists. It cannot catch a message passed as an extra NEEDLE to a variadic
 * matcher, because a prose sentence is a syntactically legal needle. The worked example was found by
 * a human reading the file, not by any rule — tests/Feature/Rbac/SuperAdminBypassExclusionTest.php,
 * which read:
 *
 *     expect($granted)->not->toContain($ability, "precondition: super_admin must not HOLD {$ability}");
 *
 * `toContain` is variadic and has no `$message` parameter, so nothing was discarded. The sentence WAS
 * an assertion: the arm claimed that the permission collection contained neither `$ability` nor the
 * English sentence "precondition: super_admin must not HOLD …". The second half is trivially true.
 *
 * READ THE WARNING PRECISELY, because the two defects are not the same size. Everything else this
 * gate is about is a LOST MESSAGE — the assertion still holds, only the diagnostic is gone. THIS one
 * is a VACUOUS ASSERTION: an `->not->toContain` over several needles fails only if the subject
 * contains one of them, so an extra needle that is never present cannot make the arm fail, and it
 * masks nothing about whether the real needle was checked. The arm guarded ADR 0040 — that a
 * super_admin holds no checker ability — and it is the second class the reader is being warned about
 * here, not the first.
 *
 * A PROSE HEURISTIC WAS CONSIDERED AND REJECTED. Flagging needles that are multi-word, or that carry
 * a `{$interpolation}`, would catch the example above. It is rejected on principle rather than on
 * difficulty: any such threshold would be tuned against the ~300 `->not->` calls in today's tree
 * until it separated them the way a human already had — which is fitting the discriminator to the
 * sample, the precise failure the "the rule is the definition" paragraph exists to warn about. A rule
 * that is right by construction over the sample tells you nothing about the next file. So the hole
 * stays open and stays NAMED. An honest hole beats a rule that cannot be wrong.
 *
 * IT READS SOURCE AS TEXT, and the precedent is already here twice — ApprovalsQueueFeedCoverageTest
 * and NotificationDeepLinkRouteTest — for the same reason each of them gives: the alternative is
 * nothing. It is a TEST rather than a bin/quality step because step 15 runs the suite, so the gate
 * runs on every push either way, and growing the gate script for something a test enforces would put
 * one rule in two places.
 */

use Pest\Mixins\Expectation;

/**
 * Every matcher Pest declares that takes a `$message`, and at which position.
 *
 * Derived by reflection, never listed here: a hardcoded list would be a second copy of the vendor's
 * signatures and would silently stop matching the day Pest adds a matcher or moves a parameter.
 *
 * @return array<string, int>
 */
function pnem_message_parameter_index(): array
{
    $index = [];

    foreach ((new ReflectionClass(Expectation::class))->getMethods() as $method) {
        foreach ($method->getParameters() as $position => $parameter) {
            // A VARIADIC `$message` would not be a message — it would be one of several values the
            // matcher folds together — so the name alone is not the test.
            if ($parameter->getName() === 'message' && ! $parameter->isVariadic()) {
                $index[$method->getName()] = $position;
            }
        }
    }

    return $index;
}

/** Index of the next token that is neither whitespace nor a comment, or null past the end. */
function pnem_next(array $tokens, int $from): ?int
{
    for ($i = $from, $n = count($tokens); $i < $n; $i++) {
        if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        return $i;
    }

    return null;
}

/**
 * Every violation in one file: `->not-><matcher>(…)` supplying an argument at the `$message` position.
 *
 * PARSED, NOT MATCHED WITH A REGEX. Counting a call's arguments needs balanced brackets and needs to
 * know which brackets are code, and a regex for that wants more lookaround than one can read. Three
 * things this had to learn, each of which produced a wrong census before it was fixed:
 *
 *  1. `->not->` is matched over SIGNIFICANT tokens. `expect($x)\n    ->not\n    ->toBeNull(…)` is the
 *     same call as the one-line form, and an adjacency match cannot see it.
 *  2. The opening parenthesis is excluded from the argument count (`$i > $open`). It sits at depth 1
 *     the moment it is counted, so without this every no-argument call reads as carrying one.
 *  3. Brackets are structural only when the token is a PLAIN CHARACTER (plus T_CURLY_OPEN and
 *     T_DOLLAR_OPEN_CURLY_BRACES, whose closer is a plain `}`). A T_ENCAPSED_AND_WHITESPACE chunk
 *     whose whole value is `[` — which `"[{$name}] …"` produces — is string CONTENT, and counting it
 *     leaves the depth permanently open so the scan runs past the closing parenthesis.
 *
 * @param  array<string, int>  $messageIndex
 * @return list<array{line: int, matcher: string, arguments: int}>
 */
function pnem_violations(string $path, array $messageIndex): array
{
    $tokens = token_get_all((string) file_get_contents($path));
    $count = count($tokens);
    $violations = [];

    for ($i = 0; $i < $count; $i++) {
        if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_OBJECT_OPERATOR) {
            continue;
        }

        $not = pnem_next($tokens, $i + 1);
        if ($not === null || ! is_array($tokens[$not]) || $tokens[$not][0] !== T_STRING || $tokens[$not][1] !== 'not') {
            continue;
        }

        $arrow = pnem_next($tokens, $not + 1);
        if ($arrow === null || ! is_array($tokens[$arrow]) || $tokens[$arrow][0] !== T_OBJECT_OPERATOR) {
            continue;
        }

        $name = pnem_next($tokens, $arrow + 1);
        if ($name === null || ! is_array($tokens[$name]) || $tokens[$name][0] !== T_STRING) {
            continue;
        }

        $open = pnem_next($tokens, $name + 1);
        if ($open === null || $tokens[$open] !== '(') {
            continue;
        }

        $matcher = $tokens[$name][1];
        if (! isset($messageIndex[$matcher])) {
            continue;   // no `$message` parameter — nothing for `->not->` to discard
        }

        $depth = 0;
        $commas = 0;
        $hasArgument = false;

        for ($j = $open; $j < $count; $j++) {
            $token = $tokens[$j];
            $text = is_array($token) ? $token[1] : $token;

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $isCharacter = ! is_array($token);
            $opensInterpolation = is_array($token)
                && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true);

            if ($opensInterpolation || ($isCharacter && in_array($text, ['(', '[', '{'], true))) {
                $depth++;
            }

            if ($isCharacter && in_array($text, [')', ']', '}'], true)) {
                $depth--;

                if ($depth === 0) {
                    break;
                }
            }

            if ($depth === 1 && $j > $open && $text !== ',') {
                $hasArgument = true;
            }

            if ($text === ',' && $depth === 1) {
                $commas++;
            }
        }

        $supplied = $hasArgument ? $commas + 1 : 0;

        if ($supplied > $messageIndex[$matcher]) {
            $violations[] = ['line' => $tokens[$name][2], 'matcher' => $matcher, 'arguments' => $supplied];
        }
    }

    return $violations;
}

it('no test passes a custom failure message to a negated Pest expectation', function () {
    $messageIndex = pnem_message_parameter_index();
    $root = dirname(__DIR__, 2);

    // Not vacuous: a reflection that found no message-bearing matcher would make every file clean.
    expect(count($messageIndex))->toBeGreaterThan(
        0,
        'reflection found no Pest matcher declaring a $message parameter — the rule has no subject '
        .'and this test would pass over any tree at all.',
    );

    $offenders = [];
    $scanned = 0;

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
        if ($file->isDir() || $file->getExtension() !== 'php') {
            continue;
        }

        $scanned++;
        $relative = 'tests'.substr($file->getPathname(), strlen($root));

        foreach (pnem_violations($file->getPathname(), $messageIndex) as $violation) {
            $offenders[] = "{$relative}:{$violation['line']}  ->not->{$violation['matcher']}"
                .' (message is argument #'.($messageIndex[$violation['matcher']] + 1)
                .", {$violation['arguments']} supplied)";
        }
    }

    // The same precaution one level up: a walk that reached no file finds no offender.
    expect($scanned)->toBeGreaterThan(
        0,
        'the walk scanned no PHP file — this test is pointed at the wrong directory and a green here '
        .'would mean it looked at nothing.',
    );

    sort($offenders);

    expect($offenders)->toBe([],
        'A custom failure message is passed to a NEGATED expectation. Pest discards it: `->not->` runs '
        .'the positive assertion and, when that succeeds, throws its own sentence with every argument '
        .'shortened-exported into it — so the message is never the failure description and is '
        .'truncated in the middle. A reader who hits this assertion gets the exported value instead of '
        ."the sentence you wrote.\n\n"
        .'Rewrite as a POSITIVE expectation that carries the message. `expect($x)->not->toBeNull($m)` '
        .'becomes `expect($x)->toBeInstanceOf(Foo::class, $m)` where the type is known, or '
        .'`expect($x !== null)->toBeTrue($m)` where it is not; '
        .'`expect($c)->not->toBeEmpty($m)` becomes `expect(count($c))->toBeGreaterThan(0, $m)`, '
        .'which also puts the 0 in the output. Prefer the rewrite that keeps the most information, not '
        ."the one that is easiest to apply.\n\n"
        .implode("\n", $offenders));
});
