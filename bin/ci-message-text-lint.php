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
 * ── WHAT IT CANNOT SEE ──
 *
 * A message built from a variable or a heredoc interpolation. Those are not used for MESSAGE_TEXT
 * anywhere in this repository today, and this lint asserts nothing about them: it reports the
 * literals it can measure. Stated because a lint that quietly skips a form is worse than one that
 * says which form it covers.
 */
$root = dirname(__DIR__);
$dir = $root.'/database/migrations';

/** MySQL's documented limit for a SIGNAL condition item. */
const MESSAGE_TEXT_CAP = 128;

$violations = [];
$measured = 0;
$longest = ['length' => 0, 'file' => '', 'text' => ''];

foreach (glob($dir.'/*.php') as $path) {
    $source = file_get_contents($path);

    // MESSAGE_TEXT = 'a' . 'b' — one or more single-quoted parts, possibly across lines.
    preg_match_all("/MESSAGE_TEXT\s*=\s*((?:\s*'(?:[^']|'')*'\s*\.?)+)/s", $source, $matches);

    foreach ($matches[1] as $expression) {
        preg_match_all("/'((?:[^']|'')*)'/", $expression, $parts);

        $literal = str_replace("''", "'", implode('', $parts[1]));
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
