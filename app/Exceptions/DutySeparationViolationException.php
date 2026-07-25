<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a grant would leave one user holding BOTH sides of a maker-checker pair within a
 * school — the user-level segregation-of-duties enforcement (Finance pairs only; Decision 0). The
 * message is ACTIONABLE by design (Decision 2): it names the user, the school, the pair (both
 * abilities), and the roles carrying each side, so whoever hit it knows which of the two grants to
 * give someone else — not just that they "violated duty separation".
 */
class DutySeparationViolationException extends RuntimeException
{
    /**
     * @param  array{checker: string, maker: string}  $pair
     * @param  list<string>  $checkerRoles  roles (existing or being granted) that carry the checker side
     * @param  list<string>  $makerRoles  roles that carry the maker side
     */
    public function __construct(
        public readonly string $userLabel,
        public readonly int $schoolId,
        public readonly array $pair,
        public readonly array $checkerRoles,
        public readonly array $makerRoles,
    ) {
        parent::__construct(sprintf(
            'Segregation of duties: [%s] would hold BOTH the checker [%s] (via role%s %s) and the maker [%s] (via role%s %s) in school #%d. '
                .'One person may not hold both sides of a pair — give one of the two grants to a different user. (Finance enforcement; ADR 0040/0044.)',
            $userLabel,
            $pair['checker'], count($checkerRoles) === 1 ? '' : 's', implode(', ', $checkerRoles) ?: '—',
            $pair['maker'], count($makerRoles) === 1 ? '' : 's', implode(', ', $makerRoles) ?: '—',
            $schoolId,
        ));
    }
}
