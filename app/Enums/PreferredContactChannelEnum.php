<?php

namespace App\Enums;

enum PreferredContactChannelEnum: string
{
    case EMAIL    = 'email';
    case SMS      = 'sms';
    case WHATSAPP = 'whatsapp';

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
