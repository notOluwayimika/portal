<?php

namespace App\Enums;

enum StudentStatusEnum: string
{
    case ACTIVE = 'active';
    case PROMOTED = 'promoted';
    case REPEATED = 'repeated';
    case WITHDRAWN = 'withdrawn';

    public static function options(): array
    {
        return array_map(
            fn ($case) => [
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
