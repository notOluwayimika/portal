<?php

namespace App\Enums;

enum MaritalStatusEnum: string
{
    case SINGLE    = 'single';
    case MARRIED   = 'married';
    case DIVORCED  = 'divorced';
    case WIDOWED   = 'widowed';
    case SEPARATED = 'separated';

    public static function options(): array
    {
        return array_map(
            fn($case) => [
                'name'  => ucfirst(strtolower($case->name)),
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
