<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a School-scoped role is assigned with no active permissions-team
 * (School) context. A role row with a null team is an access grant that belongs
 * to no School — invisible to model_has_roles-based access resolution while a
 * legacy source may still grant it, i.e. exactly the divergence S7 eliminates.
 *
 * Permanent architectural invariant (not a migration safeguard): school-scoped
 * role assignment MUST run inside a team context — on request via
 * SetSchoolContext, off-request via ActiveSchool::runFor() / setPermissionsTeamId.
 * The sole exception is `super_admin`, the deliberately team-less global role.
 */
class NullTeamRoleAssignmentException extends RuntimeException
{
    /** @param  array<int, string>  $roles */
    public function __construct(array $roles)
    {
        parent::__construct(sprintf(
            'Cannot assign school-scoped role(s) [%s] with no active School/team context. '
            .'A null-team role grants access to no School. Establish context via '
            .'SetSchoolContext (request), ActiveSchool::runFor() or setPermissionsTeamId() '
            .'(off-request) before assigning. Only super_admin may be team-less.',
            implode(', ', $roles),
        ));
    }
}
