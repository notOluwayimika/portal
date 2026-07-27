<?php

namespace App\Support;

/**
 * The starter comment set a school can import with one click.
 *
 * These are the seven bands that were hardcoded in `resources/js/components/score-entry-page.tsx`
 * until this feature replaced them, carried across VERBATIM — same minima, same text, same order.
 * Because comment bands own their own ranges rather than borrowing the grade scale, the port needs
 * no mapping heuristic and loses nothing: what a teacher saw before importing is what they see
 * after.
 *
 * OFFERED, NOT SEEDED. No migration writes these into anyone's school. They are one editorial
 * voice, not a system default, and a school that wants different wording should not have to delete
 * ours first. Importing is an explicit act in the setup UI, and the imported rows are ordinary
 * editable rows from the moment they land.
 *
 * Every entry is at or under CommentBandEntry::MAX_LENGTH — including the 52-character
 * "This result is below expectation…", which could not be saved at all while the column was 50.
 *
 * Imported by CommentBandController::loadDefaults — named in prose rather than an `@see` tag so
 * this Support primitive does not import a controller just to document its caller.
 */
final class CommentBandDefaults
{
    /**
     * Bands highest-first. `min` is authoritative; the range end is derived on save.
     *
     * @return list<array{min: int, label: string, comments: list<string>}>
     */
    public static function bands(): array
    {
        return [
            [
                'min' => 91,
                'label' => 'Outstanding',
                'comments' => [
                    'Outstanding performance. Keep it up',
                    'Outstanding performance. Keep soaring',
                ],
            ],
            [
                'min' => 80,
                'label' => 'Excellent',
                'comments' => [
                    'Excellent result. Keep it up',
                    'Excellent result. Do not relent',
                    'Excellent performance. Keep soaring',
                ],
            ],
            [
                'min' => 70,
                'label' => 'Very good',
                'comments' => [
                    'Very good result. Do not relent',
                    'Very good result. Keep working hard',
                    'Very good result. Aim higher',
                ],
            ],
            [
                'min' => 60,
                'label' => 'Good',
                'comments' => [
                    'Good result; you can do better',
                    'Good result. Do not relent in your effort',
                    'Good result. Aim higher',
                    'Good result. You can make it better',
                    'Good result. Work harder',
                ],
            ],
            [
                'min' => 50,
                'label' => 'Fair',
                'comments' => [
                    'You are encouraged to work harder',
                    'You have the potential to improve on this grade',
                    'There is room for improvement if you work hard',
                    'You are capable of better academic performance',
                    'There is potential for growth if you do not relent',
                ],
            ],
            [
                'min' => 40,
                'label' => 'Needs improvement',
                'comments' => [
                    'You are encouraged to work harder next term',
                    'You are encouraged to improve on this performance',
                    'There is room for improvement if you work hard',
                    'You need to put more effort in your academics',
                    'With determination, you can improve on this result',
                    'You can improve on this result; please work harder',
                ],
            ],
            [
                // The lowest band must start at 0 — that is what makes coverage structural.
                'min' => 0,
                'label' => 'Poor',
                'comments' => [
                    'This result is below expectation. Put in more effort',
                    'You need to put more effort in your academics',
                    'With determination, you can improve on this result',
                    'You are encouraged to put in more effort',
                    'You are encouraged to study more',
                    'Work harder for a better result next term',
                    'You are encouraged to focus more',
                ],
            ],
        ];
    }
}
