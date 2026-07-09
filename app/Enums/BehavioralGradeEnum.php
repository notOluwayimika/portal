<?php

namespace App\Enums;

enum BehavioralGradeEnum: string
{
    case A = 'A';
    case B = 'B';
    case C = 'C';
    case D = 'D';
    case E = 'E';

    public static function options(): array
    {
        return array_map(
            fn($case) => [
                'name' => $case->value,
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
