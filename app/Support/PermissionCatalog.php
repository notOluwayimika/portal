<?php

namespace App\Support;

use App\Enums\Permission;
use App\Enums\PermissionGroup;

/**
 * The grouped permission catalogue, shared by both RBAC consoles.
 *
 * Extracted so the super-admin console ({@see RbacOverview}) and the school-admin one
 * ({@see SchoolRbacOverview}) render the same taxonomy from the same code. Two copies would drift
 * the first time a group changed, and the two pages would then disagree about what a permission
 * IS — which is worse than either being wrong on its own.
 *
 * The catalogue itself is school-independent on purpose: what a permission means, and which roles
 * grant it, are global facts. Only the HOLDER counts differ per school, and those live with each
 * console's own payload.
 */
final class PermissionCatalog
{
    /**
     * @param  array<string, list<string>>  $permissionRoles  permission name => role names holding it
     * @return list<array<string, mixed>>
     */
    public static function grouped(array $permissionRoles): array
    {
        return array_map(function (PermissionGroup $group) use ($permissionRoles) {
            $permissions = array_map(function (Permission $permission) use ($permissionRoles) {
                $holders = $permissionRoles[$permission->value] ?? [];

                return [
                    'name' => $permission->value,
                    'label' => $permission->label(),
                    'roles' => $holders,
                    'roleCount' => count($holders),
                    // Granted to nothing. Either the feature it gated was removed and the
                    // permission outlived it, or a grant was revoked and never replaced.
                    'unused' => $holders === [],
                    'isChecker' => ApprovalAbility::isExcludedFromSuperAdminBypass($permission->value),
                    'matchingMaker' => ApprovalAbility::matchingMakerFor($permission->value),
                ];
            }, $group->permissions());

            return [
                'key' => $group->value,
                'label' => $group->label(),
                'description' => $group->description(),
                'icon' => $group->icon(),
                'permissionCount' => count($permissions),
                'grantedCount' => count(array_filter($permissions, fn (array $p) => ! $p['unused'])),
                'permissions' => $permissions,
            ];
        }, PermissionGroup::cases());
    }
}
