<?php

namespace App\Enums;

enum StudentStatusEnum: string
{
    case ACTIVE = 'active';
    case PROMOTED = 'promoted';
    case REPEATED = 'repeated';
    case WITHDRAWN = 'withdrawn';

    /**
     * The episode a pupil was MOVED OUT OF by a reassignment — a wrong arm corrected, a rebalance, or
     * an over-promoted pupil sent back a level. The pupil is still in the school, which is exactly why
     * `withdrawn` could not be reused. Added by 2026_08_21_100000; the enum column is the value guard
     * and is enforced on production, unlike a CHECK.
     */
    case TRANSFERRED = 'transferred';

    /**
     * The word an operator reads for this status — the ONLY place the episode vocabulary is decided.
     *
     * ── WHY THIS EXISTS AT ALL: `transferred` IS SPOKEN TWICE IN THIS SYSTEM ──────────────────────
     * `student_curricula.status = 'transferred'` means an episode a pupil was REASSIGNED out of.
     * `students.status` (StudentMembershipStatus) has its own TRANSFERRED, meaning a pupil who left
     * for another school. Same word, unrelated facts, and the second is the one users already know —
     * so showing "Transferred" against an arm correction reads as "this child has left".
     *
     * The collision is resolved HERE, at the display layer, and nowhere else: no new enum value, no
     * renamed column, no migration. The stored value stays `transferred` (the pupil did not leave,
     * which is why `withdrawn` could not be reused) and the rendered word is "Reassigned".
     *
     * Every other case derives its label mechanically, exactly as before.
     */
    public function displayLabel(): string
    {
        return match ($this) {
            self::TRANSFERRED => 'Reassigned',
            default => ucwords(strtolower(str_replace('_', ' ', $this->name))),
        };
    }

    public static function options(): array
    {
        return array_map(
            // Through displayLabel() rather than deriving the word here a second time. This method
            // has no callers today, which is precisely why it would have been the quiet leak: the
            // first dropdown wired to it would have printed "Transferred" against an episode with
            // nothing in the diff to notice.
            fn (self $case) => [
                'name' => $case->displayLabel(),
                'value' => $case->value,
            ],
            self::cases()
        );
    }

    public static function values(): array
    {
        return array_map(fn ($case) => $case->value, self::cases());
    }
}
