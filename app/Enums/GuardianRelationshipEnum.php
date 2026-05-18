<?php

namespace App\Enums;

enum GuardianRelationshipEnum: string
{
    case FATHER       = 'father';
    case MOTHER       = 'mother';
    case GUARDIAN     = 'guardian';
    case UNCLE        = 'uncle';
    case AUNT         = 'aunt';
    case GRANDPARENT  = 'grandparent';
    case STEP_PARENT  = 'step_parent';
    case SIBLING      = 'sibling';
    case OTHER        = 'other';

    public static function options(): array
    {
        return array_map(
            fn($case) => [
                'name'  => ucwords(strtolower(str_replace('_', ' ', $case->name))),
                'value' => $case->value,
            ],
            self::cases()
        );
    }

    public static function values(): array
    {
        return array_map(fn($case) => $case->value, self::cases());
    }
}
