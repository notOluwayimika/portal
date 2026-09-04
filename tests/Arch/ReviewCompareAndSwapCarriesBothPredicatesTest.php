<?php

/*
 * THE REVIEW ACTIONS' COMPARE-AND-SWAPS CARRY BOTH PREDICATES — a gate on PRESENCE, because
 * presence is the only property that can be gated.
 *
 * `ApproveInvoice` and `ReturnInvoice` each write one axis of an invoice's review state under a
 * conditional UPDATE, and each carries BOTH `whereNull` predicates in that statement:
 *
 *     Invoice::RELEASE_STAMP_COLUMN   the release axis
 *     'returned_at'                   the return axis
 *
 * Together they are what makes `reviewed_at` and `returned_at` both-set unreachable — the state
 * `2026_09_04_100000`'s docblock records as one the system does not produce, and whose enforcement
 * that migration defers to the actions rather than to a third trigger arm.
 *
 * ── WHY THIS FILE EXISTS, AND WHAT IT DOES NOT PROVE ───────────────────────────────────────────
 *
 * EIGHT MUTATIONS WERE RUN AGAINST THOSE TWO ACTIONS. Every one was confirmed APPLIED by counting
 * the site before the run — the corrupt-perl standard, after a mutation that silently did not match
 * once read as a pass from the tail of the output:
 *
 *     M1a  ApproveInvoice: predicate removed, pre-check kept          7/7 GREEN   (masked)
 *     M1b  ApproveInvoice: one pre-check call site removed            7/7 GREEN   (masked by the
 *                                                                     fallback path's second site)
 *     M1d  ApproveInvoice: BOTH pre-check sites removed, predicate    arm g RED on the SENTENCE —
 *          kept                                                       the write is refused, the
 *                                                                     message is generic
 *     M1c  ApproveInvoice: both removed                               arm g RED — the approve
 *                                                                     SUCCEEDS
 *     M2a  ReturnInvoice: release predicate removed                   11/11 GREEN (masked)
 *     M2d  ReturnInvoice: both pre-check sites removed                arm g RED on the sentence
 *     M2c  ReturnInvoice: both removed                                arm g RED — the return
 *                                                                     SUCCEEDS
 *     M3   trim() removed, the `=== ''` check kept                    arm h RED
 *     M4   both installTrigger() calls removed FROM THE MIGRATION     arm j RED (null, not 1644)
 *
 * M1a AND M2a ARE THE REASON THIS FILE IS HERE. Removing a predicate on its own left the suite
 * FULLY GREEN. That is not a gap in the arms — it is the truth about the code: the predicate is
 * UNREACHABLE behaviourally while `lockForUpdate()` holds the row. The pre-check runs inside the
 * lock and refuses first, and a second connection would not isolate it either, because the loser's
 * pre-check reads post-commit state and speaks first as well.
 *
 * SO THERE IS NO BEHAVIOURAL GATE TO BE HAD, and inventing an arm for one would be a green for the
 * wrong reason — `Invoice`'s own docblock refuses exactly that move by name, on a state that
 * "cannot be constructed". What IS available is a gate on PRESENCE, and that is all this file
 * claims: it makes DELETION red. It does not prove the predicate does anything at runtime today.
 *
 * **M1c/M1d IS THE PROOF THAT IT BITES; THIS FILE IS THE PROOF THAT IT IS STILL THERE. Neither
 * replaces the other.** With both pre-checks gone the predicate still refuses the write (M1d); with
 * the predicate gone too, the write succeeds (M1c). It is defense-in-depth for a caller that does
 * not hold the row lock, no such caller exists today, and it is the invariant that survives a
 * refactor dropping the lock — which is the day it stops being redundant, and the day nobody will
 * be reading this docblock.
 *
 * ── WHY TOKENS AND NOT A GREP, MEASURED ON THIS TARGET RATHER THAN INHERITED ────────────────────
 *
 * `ReturnInvoice.php` writes `whereNull` FOUR times. Two are the mechanism — `:179` and `:180`,
 * the compare-and-swap — and two are the docblock explaining what each predicate is for, at `:69`
 * and `:73`. (`ApproveInvoice.php` writes it twice, `:170` and `:173`, both code.)
 *
 * A SUBSTRING INSTRUMENT WOULD COUNT THE EXPLANATION AS THE MECHANISM. It would go green on a file
 * whose compare-and-swap had been gutted entirely, so long as the prose survived — and the prose is
 * the part most likely to survive, because it reads as documentation nobody needs to touch. That is
 * the specific fact that buys the tokeniser here; it is not inherited from
 * `ReleasedToPayersHasOneDefinitionTest`, which pays the same cost for a different reason.
 *
 * The comment-blindness arm below is that claim as a test: it deletes `:179-:180` and leaves
 * `:69`/`:73` standing, and reds.
 *
 * ── THREE NUMBERS, NOT TWO (CLAUDE.md § gates) ─────────────────────────────────────────────────
 *
 * EXAMINED — `whereNull` CALL SITES found, per file.
 * EXCLUDED WITH A REASON — comment TOKENS carrying the text. Prose cannot constrain a query, and
 * this target has two such mentions on purpose. **The number counts TOKENS, not mentions**, and the
 * distinction is measurable rather than pedantic: `ReturnInvoice`'s two prose mentions at `:69` and
 * `:73` sit inside ONE class docblock, which the tokeniser returns as a single `T_DOC_COMMENT`, so
 * the reported figure is 1. Measured, not assumed — the first run printed it.
 * UNRECOGNISED — a `whereNull` occurrence this walker cannot resolve to a single literal or
 * class-constant argument. ASSERTED ZERO. A predicate written in a shape the scanner cannot read
 * must RED, not vanish into a skip.
 *
 * The numbers this gate prints today:
 *
 *     app/Finance/Actions/ApproveInvoice.php   2 examined, 0 excluded (comment), 0 UNRECOGNISED
 *     app/Finance/Actions/ReturnInvoice.php    2 examined, 1 excluded (comment), 0 UNRECOGNISED
 *
 * ── AND THE R5 ARM, WHICH IS WHAT MAKES THE REST MEAN ANYTHING ─────────────────────────────────
 *
 * THE SCAN FAILS IF IT FINDS NOTHING. Zero call sites in either file is a RED, not a pass.
 * `bin/ci-activity-catalogue-lint.php` carries this rule explicitly and names why:
 * `bin/ci-tsc-ratchet.php` reads absent input as zero errors and prints "type errors DECREASED
 * (good!)". An instrument that goes green on input it never received is worse than no instrument,
 * because a green from it stops anyone looking.
 */

use Illuminate\Support\Str;

uses()->group('arch');

/** The two spellings the compare-and-swap must carry, normalised as the walker records them. */
const CAS_REQUIRED_PREDICATES = ['Invoice::RELEASE_STAMP_COLUMN', "'returned_at'"];

/** The two actions under this gate, relative to the repository root. */
function casGatedFiles(): array
{
    return [
        'app/Finance/Actions/ApproveInvoice.php',
        'app/Finance/Actions/ReturnInvoice.php',
    ];
}

/**
 * Walk $source and record every `whereNull(...)` CALL SITE with its normalised argument.
 *
 * RECOGNISE BROADLY, JUDGE NARROWLY. The walk classifies every token whose text carries `whereNull`
 * at all, so a mention in a shape nobody anticipated lands in `unrecognised` rather than in
 * silence. Only two argument shapes are ACCEPTED as resolvable:
 *
 *     a single T_CONSTANT_ENCAPSED_STRING      'returned_at'
 *     Name :: CONST                            Invoice::RELEASE_STAMP_COLUMN
 *
 * Anything else — a variable, a concatenation, a call — is UNRECOGNISED and reds. That is
 * deliberate and it is the honest setting: a predicate this scanner cannot read is a predicate it
 * cannot gate, and a gate that skips what it cannot read is the blind spot it was written to close.
 *
 * @return array{examined: int, comment: int, arguments: list<string>, unrecognised: list<array{line: int, why: string}>}
 */
function casScanSource(string $source): array
{
    $tokens = token_get_all($source);
    $out = ['examined' => 0, 'comment' => 0, 'arguments' => [], 'unrecognised' => []];

    foreach ($tokens as $index => $token) {
        // A single-character token ('(', ';') is returned as a bare string; normalised rather than
        // skipped so that if one ever carried an identifier it would still be classified.
        [$id, $text, $line] = is_array($token) ? $token : [null, $token, 0];

        if (! str_contains($text, 'whereNull')) {
            continue;
        }

        if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
            $out['comment']++;

            continue;
        }

        // A CALL SITE is `whereNull` as an identifier followed — past whitespace — by `(`. Anything
        // else carrying the text (a string literal, an interpolated fragment) is not a call and is
        // not silently dropped.
        if ($id !== T_STRING || $text !== 'whereNull') {
            $out['unrecognised'][] = ['line' => $line, 'why' => 'whereNull in '.($id === null ? 'literal-character' : token_name($id))];

            continue;
        }

        $cursor = $index + 1;

        while (isset($tokens[$cursor]) && is_array($tokens[$cursor]) && $tokens[$cursor][0] === T_WHITESPACE) {
            $cursor++;
        }

        if (($tokens[$cursor] ?? null) !== '(') {
            $out['unrecognised'][] = ['line' => $line, 'why' => 'whereNull identifier with no call parenthesis'];

            continue;
        }

        $out['examined']++;

        // Collect the argument tokens up to the MATCHING close paren, so a nested call is captured
        // whole and then judged rather than truncated into something that looks resolvable.
        $depth = 0;
        $argument = [];

        for ($cursor = $cursor; isset($tokens[$cursor]); $cursor++) {
            $inner = $tokens[$cursor];
            $innerText = is_array($inner) ? $inner[1] : $inner;
            $innerId = is_array($inner) ? $inner[0] : null;

            if ($innerText === '(') {
                $depth++;

                if ($depth === 1) {
                    continue;
                }
            }

            if ($innerText === ')') {
                $depth--;

                if ($depth === 0) {
                    break;
                }
            }

            if ($innerId === T_WHITESPACE || $innerId === T_COMMENT || $innerId === T_DOC_COMMENT) {
                continue;
            }

            $argument[] = [$innerId, $innerText];
        }

        $joined = implode('', array_column($argument, 1));

        $isLiteral = count($argument) === 1 && $argument[0][0] === T_CONSTANT_ENCAPSED_STRING;
        $isClassConstant = count($argument) === 3
            && in_array($argument[0][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STATIC], true)
            && $argument[1][0] === T_DOUBLE_COLON
            && $argument[2][0] === T_STRING;

        if (! $isLiteral && ! $isClassConstant) {
            $out['unrecognised'][] = ['line' => $line, 'why' => 'unresolvable argument ['.$joined.']'];

            continue;
        }

        $out['arguments'][] = $joined;
    }

    return $out;
}

/** Scan one repository-relative path. */
function casScanFile(string $relative): array
{
    return casScanSource(file_get_contents(dirname(__DIR__, 2).'/'.$relative));
}

/** Write $body to a throwaway .php file, scan it, delete it. */
function casScanBody(string $body): array
{
    $path = sys_get_temp_dir().'/cas_predicates_'.Str::random(12).'.php';
    file_put_contents($path, $body);

    try {
        return casScanSource(file_get_contents($path));
    } finally {
        @unlink($path);
    }
}

it('both review actions carry BOTH whereNull predicates in their compare-and-swap', function () {
    $report = [];

    foreach (casGatedFiles() as $relative) {
        $scan = casScanFile($relative);
        $report[] = sprintf(
            '    %-40s %d examined, %d excluded (comment), %d UNRECOGNISED',
            $relative, $scan['examined'], $scan['comment'], count($scan['unrecognised'])
        );

        // ── THE THIRD NUMBER, ASSERTED FIRST because it invalidates everything below it. A
        // predicate in a shape this walker cannot read must not be absorbed into a skipped count.
        //
        // EVERY ASSERTION HERE IS WRITTEN POSITIVELY, `expect(<bool>)->toBeTrue($message)`, and that
        // is not style. `toContain()` is VARIADIC in Pest, so a message passed as a second argument
        // becomes a second EXPECTED VALUE and the arm fails asserting the array contains its own
        // failure message — measured here, first run. And Pest discards a message passed to a
        // NEGATED expectation, which
        // tests/Feature/Quality/PestNegatedExpectationMessagesTest.php pins. The positive boolean
        // form is the one shape that carries a message reliably.
        expect($scan['unrecognised'] === [])->toBeTrue(
            $relative.' carries a whereNull the scanner could not resolve: '.json_encode($scan['unrecognised'])
        );

        // ── R5. Zero call sites is a RED, not a pass. An instrument that goes green on input it
        // never received is worse than no instrument.
        expect($scan['examined'] > 0)->toBeTrue(
            $relative.' has NO whereNull call sites at all — the scan found nothing, which is a '
            .'failure of this instrument or the removal of the compare-and-swap, never a pass.'
        );

        foreach (CAS_REQUIRED_PREDICATES as $required) {
            expect(in_array($required, $scan['arguments'], true))->toBeTrue(
                $relative."'s compare-and-swap no longer carries whereNull({$required}). It is "
                .'defense-in-depth for a caller that does not hold the row lock — read this file\'s '
                .'docblock before deleting it as redundant; mutations M1a/M2a show its removal reds '
                .'no behavioural arm, which is why presence is gated here.'
            );
        }
    }

    fwrite(STDERR, "\n  review CAS predicate coverage:\n".implode("\n", $report)."\n");
});

it('is COMMENT-BLIND — prose naming whereNull cannot stand in for the call', function () {
    // THE ARM THE TOKENISER IS BOUGHT FOR. This is `ReturnInvoice`'s real shape in miniature: the
    // docblock explaining both predicates survives, the compare-and-swap does not. A substring
    // instrument reports two predicates present and goes green.
    $scan = casScanBody(<<<'PHP'
    <?php

    /**
     * `whereNull('returned_at')` is the sibling's argument verbatim.
     * `whereNull(Invoice::RELEASE_STAMP_COLUMN)` is the one this action adds.
     */
    class Gutted
    {
        public function handle($invoice): int
        {
            return $invoice->newQuery()->update(['returned_at' => now()]);
        }
    }
    PHP);

    expect($scan['comment'])->toBe(1)
        ->and($scan['examined'])->toBe(0)
        ->and($scan['arguments'])->toBe([])
        ->and($scan['unrecognised'])->toBe([]);

    // And the arm that follows from it: a file in this state fails the gate above.
    foreach (CAS_REQUIRED_PREDICATES as $required) {
        expect($scan['arguments'])->not->toContain($required);
    }
});

it('reads both accepted argument shapes — a quoted column and a class constant', function () {
    $scan = casScanBody(<<<'PHP'
    <?php

    class Both
    {
        public function handle($query): int
        {
            return $query->whereNull('returned_at')->whereNull(Invoice::RELEASE_STAMP_COLUMN)->update([]);
        }
    }
    PHP);

    expect($scan['examined'])->toBe(2)
        ->and($scan['arguments'])->toBe(["'returned_at'", 'Invoice::RELEASE_STAMP_COLUMN'])
        ->and($scan['unrecognised'])->toBe([])
        ->and($scan['comment'])->toBe(0);
});

it('routes a predicate it cannot resolve into unrecognised rather than into silence', function () {
    // THE THIRD BUCKET, PROVED. Without this, the main arm's `expect($scan['unrecognised'])->toBe([])`
    // could be green because the bucket never fills. A variable argument is a real shape someone
    // could write and a real thing this gate cannot judge, so it must RED rather than skip.
    $scan = casScanBody(<<<'PHP'
    <?php

    class Dynamic
    {
        public function handle($query, string $column): int
        {
            return $query->whereNull($column)->update([]);
        }
    }
    PHP);

    expect($scan['examined'])->toBe(1)
        ->and($scan['arguments'])->toBe([])
        ->and($scan['unrecognised'])->toHaveCount(1)
        ->and($scan['unrecognised'][0]['why'])->toBe('unresolvable argument [$column]');
});

it('R5 — a file with no whereNull at all is a RED, not a pass', function () {
    // The scanner's own no-input case, proved rather than assumed. The main arm's
    // `toBeGreaterThan(0)` is what turns this into a failure there; here it is shown that the
    // scanner really does report nothing, so that assertion is load-bearing and not decorative.
    $scan = casScanBody(<<<'PHP'
    <?php

    class Empty_
    {
        public function handle(): int
        {
            return 0;
        }
    }
    PHP);

    expect($scan['examined'])->toBe(0)
        ->and($scan['arguments'])->toBe([])
        ->and($scan['comment'])->toBe(0)
        ->and($scan['unrecognised'])->toBe([]);
});
