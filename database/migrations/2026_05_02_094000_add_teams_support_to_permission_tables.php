<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $tableNames = config('permission.table_names');
        $columnNames = config('permission.column_names');
        $teamKey = $columnNames['team_foreign_key'] ?? 'school_id';
        $pivotRole = $columnNames['role_pivot_key'] ?? 'role_id';
        $pivotPermission = $columnNames['permission_pivot_key'] ?? 'permission_id';
        $modelKey = $columnNames['model_morph_key'] ?? 'model_id';

        // 0. Roles Table
        if (Schema::hasTable($tableNames['roles'])) {
            if (!Schema::hasColumn($tableNames['roles'], $teamKey)) {
                Schema::table($tableNames['roles'], function (Blueprint $table) use ($teamKey) {
                    $table->unsignedBigInteger($teamKey)->nullable()->after('id');
                    $table->index($teamKey, 'roles_team_foreign_key_index');
                });

                try {
                    Schema::table($tableNames['roles'], function (Blueprint $table) {
                        $table->dropUnique('roles_name_guard_name_unique');
                    });
                } catch (\Exception $e) {}

                try {
                    DB::statement("ALTER TABLE `{$tableNames['roles']}` ADD UNIQUE INDEX `roles_team_name_guard_unique` ((IFNULL(`{$teamKey}`, 0)), `name`, `guard_name`)");
                } catch (\Exception $e) {}
            }
        }

        // 1. Roles Pivot Table
        if (Schema::hasTable($tableNames['model_has_roles'])) {
            try {
                Schema::table($tableNames['model_has_roles'], function (Blueprint $table) {
                    $table->dropPrimary();
                });
            } catch (\Exception $e) {}

            try {
                Schema::table($tableNames['model_has_roles'], function (Blueprint $table) {
                    $table->dropUnique('model_has_roles_team_unique');
                });
            } catch (\Exception $e) {}

            if (!Schema::hasColumn($tableNames['model_has_roles'], $teamKey)) {
                Schema::table($tableNames['model_has_roles'], function (Blueprint $table) use ($teamKey, $pivotRole) {
                    $table->unsignedBigInteger($teamKey)->nullable()->after($pivotRole);
                });
            }

            try {
                DB::statement("ALTER TABLE `{$tableNames['model_has_roles']}` ADD UNIQUE INDEX `model_has_roles_team_unique` ((IFNULL(`{$teamKey}`, 0)), `{$pivotRole}`, `{$modelKey}`, `model_type`)");
            } catch (\Exception $e) {}
        }

        // 2. Permissions Pivot Table
        if (Schema::hasTable($tableNames['model_has_permissions'])) {
            try {
                Schema::table($tableNames['model_has_permissions'], function (Blueprint $table) {
                    $table->dropPrimary();
                });
            } catch (\Exception $e) {}

            try {
                Schema::table($tableNames['model_has_permissions'], function (Blueprint $table) {
                    $table->dropUnique('model_has_permissions_team_unique');
                });
            } catch (\Exception $e) {}

            if (!Schema::hasColumn($tableNames['model_has_permissions'], $teamKey)) {
                Schema::table($tableNames['model_has_permissions'], function (Blueprint $table) use ($teamKey, $pivotPermission) {
                    $table->unsignedBigInteger($teamKey)->nullable()->after($pivotPermission);
                });
            }

            try {
                DB::statement("ALTER TABLE `{$tableNames['model_has_permissions']}` ADD UNIQUE INDEX `model_has_permissions_team_unique` ((IFNULL(`{$teamKey}`, 0)), `{$pivotPermission}`, `{$modelKey}`, `model_type`)");
            } catch (\Exception $e) {}
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tableNames = config('permission.table_names');
        $columnNames = config('permission.column_names');
        $teamKey = $columnNames['team_foreign_key'] ?? 'school_id';

        if (Schema::hasTable($tableNames['roles'])) {
            Schema::table($tableNames['roles'], function (Blueprint $table) use ($teamKey) {
                try { $table->dropUnique('roles_team_name_guard_unique'); } catch (\Exception $e) {}
                $table->dropColumn($teamKey);
                $table->unique(['name', 'guard_name'], 'roles_name_guard_name_unique');
            });
        }

        if (Schema::hasTable($tableNames['model_has_roles'])) {
            Schema::table($tableNames['model_has_roles'], function (Blueprint $table) {
                try { $table->dropUnique('model_has_roles_team_unique'); } catch (\Exception $e) {}
                $table->primary(['role_id', 'model_id', 'model_type'], 'model_has_roles_role_model_type_primary');
            });
        }

        if (Schema::hasTable($tableNames['model_has_permissions'])) {
            Schema::table($tableNames['model_has_permissions'], function (Blueprint $table) {
                try { $table->dropUnique('model_has_permissions_team_unique'); } catch (\Exception $e) {}
                $table->primary(['permission_id', 'model_id', 'model_type'], 'model_has_permissions_permission_model_type_primary');
            });
        }
    }
};
