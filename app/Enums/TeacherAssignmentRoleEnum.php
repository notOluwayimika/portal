<?php

namespace App\Enums;

enum TeacherAssignmentRoleEnum: string
{
    case BOARDING_PARENT = 'boarding_parent';
    case FORM_TEACHER = 'form_teacher';
    case HEAD_OF_SCHOOL = 'head_of_school';
    // Primary's equivalent of a Head of School: same job — supervise several class
    // levels and write the senior comment — under the name that school uses. A
    // separate case rather than a rename because both exist in the same portal,
    // and a result must be able to say which of the two signed it.
    case KEY_STAGE_COORDINATOR = 'key_stage_coordinator';

    public static function options(): array
    {
        return array_map(
            fn ($case) => [
                'name' => ucwords(str_replace('_', ' ', $case->value)),
                'value' => $case->value,
            ],
            self::cases()
        );
    }

    public static function values(): array
    {
        return array_map(fn ($case) => $case->value, self::cases());
    }
}
