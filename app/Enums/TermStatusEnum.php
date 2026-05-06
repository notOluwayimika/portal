<?php

namespace App\Enums;

enum TermStatusEnum: string
{
    case ACTIVE = 'active';
    case UPCOMING = 'upcoming';
    case COMPLETED = 'completed';

    public static function asArray(): array
    {
        return [
            self::ACTIVE->value,
            self::UPCOMING->value,
            self::COMPLETED->value,
        ];
    }
}
