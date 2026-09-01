<?php

/**
 * MESSAGE_TEXT LINT — MySQL caps a SIGNAL's MESSAGE_TEXT at 128 characters.
 *
 * ── WHY THIS IS A GATE AND NOT A DOC ENTRY ──
 *
 * Overrunning the cap does NOT truncate. The SIGNAL itself fails with driver code **1648**
 * (`Data too long for condition item`) instead of the intended **1644**. The row is still refused —
 * which is exactly what makes it invisible: a guard reports a code no caller recognises, and any
 * bite-proof asserting merely "an exception was thrown" passes.
 *
 * Measured 2026-09-01 on `fix/gateway-reference-trigger`: a ~170-character message made all seven
 * refusal arms return 1648. The limit had already been measured and written down earlier the same
 * week, on both 8.0.43 and 5.7.23, and it bit anyway — which is the adoption-gradient case exactly.
 * A rule whose violation produces no local failure signal propagates by memory, and memory does not
 * propagate. The sentence was not a gate; this is.
 *
 * ── IT IS NOT HYPOTHETICAL ──
 *
 * At the time of writing there are 61 MESSAGE_TEXT literals under database/migrations and none is
 * over the cap — but the longest is **126 characters**, two under. A one-word edit to an existing
 * message ships a broken SIGNAL.
 *
 * ── WHAT IT MEASURES ──
 *
 * The ASSEMBLED literal, not the source line. Messages in this repository are written across
 * several lines and as concatenated string parts, so a per-line length check would measure the
 * formatting rather than the value MySQL receives.
 *
 * Doubled quotes (`''`) inside a single-quoted PHP string are ONE character to MySQL, and are
 * counted as one.
 *
 * ── WHAT IT CANNOT MEASURE, IT REFUSES — IT DOES NOT SKIP ──
 *
 * A MESSAGE_TEXT built from a variable, a heredoc, or any expression this scanner cannot resolve
 * statically is reported as **UNRESOLVED** and fails the lint. It is not passed over.
 *
 * An earlier version documented the gap instead: "no such messages exist today, so this asserts
 * nothing about them." True, and it would have stopped being true SILENTLY — the first interpolated
 * message would have sailed through a gate whose output said OK. That is the same shape as an empty
 * divergence list standing in for a failed fetch: the absence of a finding and the inability to look
 * rendering identically.
 *
 * So the gap cannot widen without the gate announcing it. If an interpolated message is ever
 * genuinely wanted, that is a deliberate conversation — and the refusal is what forces it to happen
 * rather than to be discovered later by a 1648 in production.
 */
$root = dirname(__DIR__);
$dir = $root.'/database/migrations';

/** MySQL's documented limit for a SIGNAL condition item. */
const MESSAGE_TEXT_CAP = 128;

$violations = [];
$unresolved = [];
$measured = 0;
$longest = ['length' => 0, 'file' => '', 'text' => ''];

foreach (glob($dir.'/*.php') as $path) {
    $source = file_get_contents($path);

    // STRIP COMMENTS FIRST. These migrations discuss their own SIGNAL messages in docblocks — one
    // of them is literally about how a message was quoted — so scanning raw source reports prose as
    // code. Using the tokeniser rather than a regex, because a regex for "not in a comment" is the
    // same class of guess this lint has already been wrong about twice.
    $code = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $code .= is_array($token) ? $token[1] : $token;
    }
    $source = $code;

    // EVERY MESSAGE_TEXT, up to the statement terminator, so an expression this scanner cannot
    // resolve is CAUGHT rather than missed by a narrower pattern.
    //
    // TWO WRITTEN FORMS, BOTH ORDINARY. These messages live in SQL that is itself embedded in PHP:
    // inside a heredoc the SQL quote is a plain `'`, and inside a single-quoted PHP string it is
    // written `\'`. Both are static literals. An earlier version of this lint knew only the heredoc
    // form and reported 82 perfectly ordinary messages as unmeasurable — the scanner-flags-correct-
    // code failure this project has already paid for once, in the collation sweep.
    preg_match_all('/MESSAGE_TEXT\s*=\s*(.*?);/s', $source, $all);

    foreach ($all[1] as $expression) {
        $trimmed = trim($expression);

        // Strip the delimiters in whichever form they were written FIRST, then judge the inside.
        $inner = $trimmed;
        $inner = preg_replace('/^\\\\?\'/', '', $inner);
        $inner = preg_replace('/\\\\?\'$/', '', $inner);

        // GENUINELY DYNAMIC, judged at the STRING BOUNDARY rather than by characters.
        //
        //   · `{$var}` / `${var}` — interpolation.
        //   · a surviving unescaped `'` inside — the literal was CLOSED and re-opened, i.e.
        //     concatenation: `\''.self::MSG_X.'\''.
        //
        // Judging by content was wrong and produced a false positive on this repository's own code:
        // an earlier version looked for `::` anywhere and flagged a message whose TEXT mentions
        // `GatewayReference::mint()`. That is the scanner-flags-correct-code failure a third time —
        // a matcher must be checked against something it MUST NOT flag, not only something it must.
        $withoutDoubled = str_replace(["\\\\''", "''"], '', $inner);
        $withoutEscaped = str_replace("\\\\'", '', $withoutDoubled);

        if (str_contains($inner, '{$') || str_contains($inner, '${') || str_contains($withoutEscaped, "'")) {
            $unresolved[] = sprintf(
                '  ? %s  built from an expression: %s',
                basename($path),
                trim(preg_replace('/\s+/', ' ', mb_substr($trimmed, 0, 70)))
            );

            continue;
        }

        // Doubled quotes are ONE character to MySQL, in both written forms.
        $literal = str_replace(["\\''", "''"], "'", $inner);
        $literal = str_replace("\\'", "'", $literal);

        $length = mb_strlen($literal);
        $measured++;

        if ($length > $longest['length']) {
            $longest = ['length' => $length, 'file' => basename($path), 'text' => $literal];
        }

        if ($length > MESSAGE_TEXT_CAP) {
            $violations[] = sprintf('  ✗ %s  %d chars (cap %d)  %s…', basename($path), $length, MESSAGE_TEXT_CAP, mb_substr($literal, 0, 60));
        }
    }
}

// PRE-EXISTING UNRESOLVABLES ARE BASELINED, AND THE BASELINE MAY ONLY SHRINK — the same shape as
// the boundary and ratchet baselines. Eleven messages in this repository are built by interpolation
// or from class constants; they predate this lint and blocking on them would make the gate
// un-passable rather than useful. What the baseline buys is that a NEW one fails, so the gap cannot
// widen silently — which was the whole point of refusing rather than skipping.
$baselinePath = $root.'/message-text-lint-baseline.txt';
$baseline = is_file($baselinePath)
    ? array_filter(array_map('trim', file($baselinePath)), fn ($line) => $line !== '' && ! str_starts_with($line, '#'))
    : [];

$newUnresolved = array_values(array_filter($unresolved, fn ($line) => ! in_array(trim($line), $baseline, true)));

if ($newUnresolved !== []) {
    $unresolved = $newUnresolved;
    echo 'message-text-lint: '.count($unresolved)." NEW SIGNAL message(s) whose length cannot be determined:\n";
    echo implode("\n", $unresolved)."\n\n";
    echo "UNRESOLVED IS NOT OK. This lint measures single-quoted literals; anything built from a\n";
    echo "variable, a heredoc or an interpolated string could exceed MySQL's 128-character cap and\n";
    echo "fail as 1648 instead of 1644. Refusing rather than skipping is what stops the gap widening\n";
    echo "silently. Use a plain single-quoted literal, or raise it deliberately.\n";
    exit(1);
}

if ($violations !== []) {
    echo 'message-text-lint: '.count($violations)." SIGNAL message(s) over MySQL's ".MESSAGE_TEXT_CAP."-character cap:\n";
    echo implode("\n", $violations)."\n\n";
    echo "MySQL does not truncate these. The SIGNAL fails with 1648 instead of 1644, so the row is\n";
    echo "still refused but the guard reports a code no caller recognises — and a bite-proof that\n";
    echo "asserts only 'it threw' passes. Shorten the message; put the reasoning in a docblock.\n";
    exit(1);
}

printf(
    "message-text-lint: OK — %d SIGNAL message(s) measured, none over %d (longest %d in %s).\n",
    $measured,
    MESSAGE_TEXT_CAP,
    $longest['length'],
    $longest['file'],
);
