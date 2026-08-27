<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A CCM fold refused because folding it would destroy marks.
 *
 * ── WHY THIS CLASS EXISTS AT ALL, AND WHY IT OVERRIDES __toString ────────────────────────────────
 * The refusal has to reach an OPERATOR, and the only channel it travels is
 * `failed_jobs.exception` — which Laravel writes as `(string) $throwable`
 * (`DatabaseFailedJobProvider::log`). PHP's built-in stringification is
 *
 *     ClassName: <message> in /absolute/path/File.php:265
 *     Stack trace:
 *     #0 ...
 *
 * so the FIRST LINE — the only part any consumer can safely take — carries an absolute server path
 * and a line number along with the sentence. A drive against the real queue rendered exactly that
 * onto the panel: the guard's sentence followed by
 * `in /Users/…/app/Jobs/MoveFromCcmJob.php:265`.
 *
 * The consumer-side repair is a path-stripping regex, and that is the wrong fix: the guard's own
 * message names components and files-worth of nouns, a message may legitimately contain " in ", and
 * a strip-by-pattern is one more thing that works against a fixture and breaks against reality —
 * which is the precise failure this whole surface was built to stop repeating.
 *
 * So the PRODUCER emits the right string instead. `__toString()` returns the message, so the value
 * Laravel persists IS the sentence, and no consumer parses anything.
 *
 * ── THIS DOES NOT COST THE TRACE ─────────────────────────────────────────────────────────────────
 * Logging does not go through `__toString()`. `Handler::report()` logs `$e->getMessage()` with the
 * throwable itself in `['exception' => $e]`, and Monolog's formatter reads class, file, line and
 * trace off the OBJECT. The stack trace is intact everywhere it belongs; it is withheld only from
 * the one channel that feeds an operator's screen. `getFile()`, `getLine()` and
 * `getTraceAsString()` are all untouched and still work.
 */
class CcmFoldRefused extends RuntimeException
{
    /**
     * The message alone — see the class docblock. This is load-bearing, not cosmetic: it is what
     * keeps a server path off the operator's panel, and `CcmFoldSurfaceTest` pins it against a
     * REAL stringified throwable rather than a hand-written lookalike.
     */
    public function __toString(): string
    {
        return $this->getMessage();
    }
}
