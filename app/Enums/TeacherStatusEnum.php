<?php

namespace App\Enums;

enum TeacherStatusEnum: string
{
    case ACTIVE   = 'active';
    case INACTIVE = 'inactive';
    case RESIGNED = 'resigned';

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
