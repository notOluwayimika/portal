<?php

namespace Database\Seeders;

use App\Enums\Permission as Perm;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ActivityLogPermissionSeeder extends Seeder
{
    /**
     * Permissions gating the activity log module. Mirrors the idempotent,
     * team-agnostic approach used by GuardianPermissionSeeder (permission
     * rows are global; only role assignments use Spatie teams).
     */
    private const PERMISSIONS = [
        Perm::ACTIVITY_LOG_VIEW->value,
        Perm::ACTIVITY_LOG_VIEW_ALL->value,
        Perm::ACTIVITY_LOG_VIEW_OWN->value,
        Perm::ACTIVITY_LOG_VIEW_SYSTEM->value,
        Perm::ACTIVITY_LOG_VIEW_CROSS_SCHOOL->value,
        Perm::ACTIVITY_LOG_EXPORT->value,
        Perm::ACTIVITY_LOG_VIEW_SENSITIVE->value,
    ];

    // Day-to-day staff: see only their own activity.
    private const STAFF_PERMISSIONS = [
        Perm::ACTIVITY_LOG_VIEW->value,
        Perm::ACTIVITY_LOG_VIEW_OWN->value,
    ];

    // School administrators: full school-scoped visibility + export.
    private const ADMIN_PERMISSIONS = [
        Perm::ACTIVITY_LOG_VIEW->value,
        Perm::ACTIVITY_LOG_VIEW_ALL->value,
        Perm::ACTIVITY_LOG_VIEW_OWN->value,
        Perm::ACTIVITY_LOG_EXPORT->value,
        Perm::ACTIVITY_LOG_VIEW_SENSITIVE->value,
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $admin = Role::where('name', 'admin')->first();
        $head = Role::where('name', 'head_of_school')->first();
        $teacher = Role::where('name', 'teacher')->first();
        $superAdmin = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        if ($admin) {
            $this->syncPermissions($admin, self::ADMIN_PERMISSIONS);
        }
        if ($head) {
            $this->syncPermissions($head, self::ADMIN_PERMISSIONS);
        }
        if ($teacher) {
            $this->syncPermissions($teacher, self::STAFF_PERMISSIONS);
        }

        // Super-admin gets everything, including cross-school + system events.
        $this->syncPermissions($superAdmin, self::PERMISSIONS);
    }

    private function syncPermissions(Role $role, array $permissionNames): void
    {
        $permissionIds = Permission::whereIn('name', $permissionNames)->pluck('id');

        foreach ($permissionIds as $permissionId) {
            DB::table('role_has_permissions')->updateOrInsert(
                ['permission_id' => $permissionId, 'role_id' => $role->id],
                ['permission_id' => $permissionId, 'role_id' => $role->id]
            );
        }
    }
}
