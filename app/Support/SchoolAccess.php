<?php

namespace App\Support;

use App\Models\User;

/**
 * Single funnel for the subquery "which user_ids have access to School X",
 * flag-gated on rbac.single_source_access exactly like User::accessibleSchoolIds.
 *
 * S7 brings the three direct `school_user` readers (GuardianService, Teacher and
 * Guardian scopes) UNDER the flag through this helper, so the parity soak — which
 * flips the flag — actually covers them. Before S7 they read `school_user`
 * unconditionally, outside the flag's control: a green parity soak on the
 * (flag-controlled) accessibleSchoolIds path would have said "safe" while these
 * uncontrolled readers still depended on the pivot (the near-miss recorded in
 * docs/roadmap.md). Centralising the pivot reference here also means the
 * column-drop deletes ONE branch, not three call sites.
 */
class SchoolAccess
{
    /**
     * A subquery closure selecting the user_id column of every user with access
     * to $schoolId, from the active source. Use inside whereIn(...):
     *
     *   ->whereIn('x.user_id', SchoolAccess::userIdsWithAccessTo($schoolId))
     */
    public static function userIdsWithAccessTo(int $schoolId): \Closure
    {
        return function ($query) use ($schoolId): void {
            if (config('rbac.single_source_access')) {
                // Single source of truth (§7.1): access = a role in the School's team.
                $query->select(config('permission.column_names.model_morph_key'))
                    ->from(config('permission.table_names.model_has_roles'))
                    ->where('model_type', (new User)->getMorphClass())
                    ->where(config('permission.column_names.team_foreign_key'), $schoolId)
                    ->whereNotNull(config('permission.column_names.team_foreign_key'));
            } else {
                // Legacy pivot (removed at the S7 column drop).
                $query->select('user_id')
                    ->from('school_user')
                    ->where('school_id', $schoolId);
            }
        };
    }
}
