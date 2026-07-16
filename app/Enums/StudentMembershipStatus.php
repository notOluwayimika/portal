<?php

namespace App\Enums;

/**
 * A Student's membership at a School (§12.6) — "is this person a Student at this
 * School?". Distinct from StudentStatusEnum, which is *term enrollment* status
 * ("are they in JS1 this term? promoted? repeating?") and lives on
 * StudentCurriculum. Billing eligibility keys off this membership status.
 */
enum StudentMembershipStatus: string
{
    case ACTIVE = 'active';
    case WITHDRAWN = 'withdrawn';
    case GRADUATED = 'graduated';
    case TRANSFERRED = 'transferred';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
