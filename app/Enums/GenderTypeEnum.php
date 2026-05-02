<?php

namespace App\Enums;

use InvalidArgumentException;

enum GenderTypeEnum: string
{
    case MALE = 'male';
    case FEMALE = 'female';
    case OTHER = 'other';

    public static function getGenderByOption(?string $option): ?string
    {
        if (empty($option)) return $option;

        try {
            $option = strtoupper($option);
            $enumCase = constant("self::$option") ?? self::tryFrom($option);
            return $enumCase->value;
        } catch (\ValueError $e) {
            throw new InvalidArgumentException(__('Invalid gender type'));
        }
    }

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
