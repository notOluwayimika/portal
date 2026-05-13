<?php

namespace App\Enums;

enum GuardianIdTypeEnum: string
{
    case NATIONAL_ID     = 'national_id';
    case PASSPORT        = 'passport';
    case DRIVERS_LICENSE = 'drivers_license';

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
