<?php

namespace Database\Seeders;

use App\Enums\Permission as Perm;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class GuardianPermissionSeeder extends Seeder
{
    /**
     * Permissions used to gate guardian management actions.
     * Registrars get the everyday set; admins get everything (including credential changes).
     */
    private const PERMISSIONS = [
        Perm::GUARDIAN_VIEW->value,
        Perm::GUARDIAN_UPDATE->value,
        Perm::GUARDIAN_UPDATE_CREDENTIALS->value,
        Perm::GUARDIAN_DETACH->value,
        Perm::GUARDIAN_ENABLE_LOGIN->value,
        Perm::GUARDIAN_CREATE->value,
        Perm::GUARDIAN_EXPORT->value,
        Perm::GUARDIAN_MESSAGE->value,
        Perm::GUARDIAN_VIEW_AUDIT->value,
        Perm::GUARDIAN_IMPORT->value,
    ];

    private const REGISTRAR_PERMISSIONS = [
        Perm::GUARDIAN_VIEW->value,
        Perm::GUARDIAN_UPDATE->value,
        Perm::GUARDIAN_DETACH->value,
        Perm::GUARDIAN_CREATE->value,
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $adminRole = Role::where('name', 'admin')->first();
        $registrarRole = Role::firstOrCreate(['name' => 'registrar', 'guard_name' => 'web']);
        $headRole = Role::where('name', 'head_of_school')->first();

        // Make role-permission assignment idempotent without team scoping
        // (permission rows themselves are global; only role assignments use teams).
        if ($adminRole) {
            $this->syncPermissions($adminRole, self::PERMISSIONS);
        }
        if ($headRole) {
            $this->syncPermissions($headRole, self::PERMISSIONS);
        }
        $this->syncPermissions($registrarRole, self::REGISTRAR_PERMISSIONS);
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
