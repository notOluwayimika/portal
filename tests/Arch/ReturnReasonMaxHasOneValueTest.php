<?php

/*
 * THE RETURN REASON'S WIDTH IS ONE NUMBER, WRITTEN TWICE — and this is the gate that keeps the two
 * copies equal.
 *
 * `finance_invoices.return_reason` is `VARCHAR(255)`. Three places must agree about that:
 *
 *     App\Finance\Actions\ReturnInvoice::REASON_MAX       the domain refusal
 *     ReturnInvoiceRequest                                cites the constant — cannot diverge
 *     resources/js/lib/internal-audit-queue.ts            RETURN_REASON_MAX — a SECOND COPY
 *
 * THE FIRST TWO CANNOT DIVERGE because the request writes `'max:'.ReturnInvoice::REASON_MAX`. THE
 * THIRD CAN, and nothing else in this repository would notice: there is no PHP→TypeScript constant
 * bridge — `wayfinder` generates route helpers and nothing generates VALUES — so the frontend
 * cannot import the number and must restate it.
 *
 * ── WHAT DIVERGENCE ACTUALLY COSTS, WHICH IS WHY A COMMENT WOULD NOT BE ENOUGH ─────────────────
 *
 * The failure is not a crash and not a 500. If the column and the PHP constant widen and the
 * TypeScript one does not, the textarea's `maxLength` SILENTLY STOPS ACCEPTING KEYSTROKES at the
 * old limit — in the one field whose entire job is to tell Finance what to fix. The auditor types a
 * sentence, the end of it never arrives, and nothing anywhere reports it. In the other direction a
 * reason the form accepted is refused by the server after a round trip, with the action's sentence
 * where a field error belongs.
 *
 * ── THE INSTRUMENT, AND ITS THIRD NUMBER ───────────────────────────────────────────────────────
 *
 * A regex over one declaration line, not a parse of the module — the target is a single
 * `export const NAME = <int>;` and a tokeniser buys nothing over it. But the SHAPE must be pinned,
 * or the gate degrades into "the file contains a number somewhere":
 *
 *   EXAMINED     the declaration, located by name.
 *   EXCLUDED     nothing. There is no legitimate second spelling of this constant to exclude, and
 *                saying so is the point — an empty exclusion bucket is a claim, not an oversight.
 *   UNRECOGNISED the declaration missing, or present in a form this matcher cannot read (computed,
 *                imported, re-exported). ASSERTED ABSENT: the arm FAILS rather than skipping, so a
 *                refactor that moves the constant reds here instead of quietly disarming the gate.
 *
 * R5, THE ONE THAT MAKES THE REST MEAN ANYTHING: a scan that finds nothing is a RED, never a pass.
 * `bin/ci-tsc-ratchet.php` reads absent input as zero errors and prints "type errors DECREASED
 * (good!)"; an instrument that goes green on input it never received is worse than no instrument,
 * because a green from it stops anyone looking.
 */

use App\Finance\Actions\ReturnInvoice;

uses()->group('arch');

/** The one file allowed to restate the number, and the declaration this gate reads. */
const RRM_TS_FILE = 'resources/js/lib/internal-audit-queue.ts';

const RRM_PATTERN = '/^export const RETURN_REASON_MAX = (\d+);$/m';

it('the TypeScript copy of RETURN_REASON_MAX equals ReturnInvoice::REASON_MAX', function () {
    $path = dirname(__DIR__, 2).'/'.RRM_TS_FILE;

    // R5. The file itself must be there before anything it contains can be asserted about.
    expect(is_file($path))->toBeTrue(
        RRM_TS_FILE.' does not exist. This gate asserts a constant inside it; a missing file is a '
        .'failure of the gate, never a pass.'
    );

    $source = file_get_contents($path);

    expect($source)->not->toBe('');

    $matched = preg_match(RRM_PATTERN, $source, $found);

    // THE UNRECOGNISED BUCKET, ASSERTED EMPTY. A declaration this matcher cannot read — computed,
    // imported, re-exported, renamed — must RED here rather than vanish into a skipped count.
    expect($matched)->toBe(
        1,
        'No `export const RETURN_REASON_MAX = <integer>;` line in '.RRM_TS_FILE.'. It may have been '
        .'renamed, computed or re-exported — any of which disarms this gate silently, so the gate '
        .'refuses instead. Restore the plain declaration, or move this assertion with it.'
    );

    expect((int) $found[1])->toBe(
        ReturnInvoice::REASON_MAX,
        'The return-reason cap disagrees across the boundary: '.RRM_TS_FILE.' says '.$found[1]
        .', ReturnInvoice::REASON_MAX says '.ReturnInvoice::REASON_MAX.'. There is no PHP→TS '
        .'constant bridge, so these are two copies of one number. The symptom of divergence is a '
        .'textarea that silently stops accepting keystrokes in the one field that tells Finance '
        .'what to fix.'
    );
});

it('the matcher reds on a declaration it cannot read, rather than skipping it', function () {
    // THE BUCKET PROVED BY INJECTION, so the arm above cannot be green because the matcher never
    // fires. Each of these is a real refactor somebody could make, and each must fail to match.
    $unreadable = [
        'computed' => 'export const RETURN_REASON_MAX = 200 + 55;',
        'imported' => 'export { RETURN_REASON_MAX } from "./elsewhere";',
        'renamed' => 'export const REASON_MAX = 255;',
        'not exported' => 'const RETURN_REASON_MAX = 255;',
    ];

    foreach ($unreadable as $why => $line) {
        expect(preg_match(RRM_PATTERN, $line))->toBe(0, "the matcher accepted a [{$why}] declaration");
    }

    // And the positive control, or the four above would pass against a matcher that matches nothing.
    expect(preg_match(RRM_PATTERN, 'export const RETURN_REASON_MAX = 255;', $found))->toBe(1)
        ->and((int) $found[1])->toBe(255);
});
