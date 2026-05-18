<?php

namespace App\Enums;

enum CurriculaStatusEnum: string
{
    case ACTIVE = 'active';
    case DRAFT = 'draft';
    case CLOSED = 'closed';

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
