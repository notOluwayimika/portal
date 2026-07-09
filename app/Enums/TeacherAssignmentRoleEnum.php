<?php

namespace App\Enums;

enum TeacherAssignmentRoleEnum: string
{
    case BOARDING_PARENT = 'boarding_parent';
    case FORM_TEACHER = 'form_teacher';
    case HEAD_OF_SCHOOL = 'head_of_school';

    public static function options(): array
    {
        return array_map(
            fn($case) => [
                'name' => ucwords(str_replace('_', ' ', $case->value)),
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
