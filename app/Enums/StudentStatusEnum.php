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

    public static function options(): array
    {
        return array_map(
            fn ($case) => [
                'name' => ucwords(strtolower(str_replace('_', ' ', $case->name))),
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
