<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration {
    /**
     * Create the global super_admin role and the first super admin user.
     * Credentials come from SUPER_ADMIN_EMAIL / SUPER_ADMIN_PASSWORD env
     * vars (change the default password immediately in production).
     */
    public function up(): void
    {
        // Global (team-less) roles require a NULL team key on the permission
        // tables; on MySQL these columns may have been created NOT NULL.
        if (DB::connection()->getDriverName() === 'mysql') {
            foreach (['roles', 'model_has_roles', 'model_has_permissions'] as $table) {
                if (Schema::hasTable($table) && Schema::hasColumn($table, 'school_id')) {
                    DB::statement("ALTER TABLE {$table} MODIFY school_id BIGINT UNSIGNED NULL");
                }
            }
        }

        // Global roles (no team/school context).
        setPermissionsTeamId(null);

        foreach (['web', 'api'] as $guard) {
            Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => $guard]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $email = env('SUPER_ADMIN_EMAIL', 'superadmin@portal.test');

        $user = User::withoutGlobalScopes()->firstOrCreate(
            ['email' => $email],
            [
                'first_name' => 'Super',
                'last_name' => 'Admin',
                'password' => Hash::make(env('SUPER_ADMIN_PASSWORD', 'password')),
            ]
        );

        if (!$user->hasRole('super_admin')) {
            $user->assignRole('super_admin');
        }
    }

    public function down(): void
    {
        // Intentionally left blank: removing the super admin is a manual operation.
    }
};
