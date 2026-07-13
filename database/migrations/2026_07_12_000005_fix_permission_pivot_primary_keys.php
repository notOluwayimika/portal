<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * The hybrid-id conversion re-added PRIMARY KEY (role_id, model_id,
     * model_type) on the permission pivots WITHOUT the team key, which
     * blocks assigning the same role to the same user in two different
     * schools. Per-team uniqueness is already enforced by the functional
     * unique indexes ((IFNULL(school_id,0)), ..., model_id, model_type)
     * added by the teams-support migration, so the PK can be dropped.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $this->dropPrimaryKeepingFkIndex('model_has_roles', 'role_id');
        $this->dropPrimaryKeepingFkIndex('model_has_permissions', 'permission_id');
    }

    public function down(): void
    {
        // Restoring the old (broken for teams) primary key is intentionally
        // not supported.
    }

    private function dropPrimaryKeepingFkIndex(string $table, string $fkColumn): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }

        // The FK on $fkColumn may rely on the PK index; give it its own
        // index first so the PK can be dropped.
        try {
            DB::statement("ALTER TABLE {$table} ADD INDEX {$table}_{$fkColumn}_index ({$fkColumn})");
        } catch (\Throwable) {
            // index already exists
        }

        try {
            DB::statement("ALTER TABLE {$table} DROP PRIMARY KEY");
        } catch (\Throwable) {
            // no primary key present
        }
    }
};
