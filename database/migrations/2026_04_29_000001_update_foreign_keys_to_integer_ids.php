<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Step 2: Update foreign key columns from UUID to integer references.
     * This migration maps old UUID foreign keys to new integer IDs.
     */
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        // Map of tables and their foreign key columns that need updating
        $foreignKeyMappings = [
            'sessions' => [['column' => 'user_id', 'references' => 'users']],
            'users' => [['column' => 'school_id', 'references' => 'schools']],
            'class_levels' => [['column' => 'school_id', 'references' => 'schools']],
            'arms' => [['column' => 'school_id', 'references' => 'schools']],
            'class_level_arms' => [
                ['column' => 'class_level_id', 'references' => 'class_levels'],
                ['column' => 'arm_id', 'references' => 'arms'],
            ],
            'academic_sessions' => [['column' => 'school_id', 'references' => 'schools']],
            'exam_types' => [['column' => 'school_id', 'references' => 'schools']],
            'curricula' => [
                ['column' => 'school_id', 'references' => 'schools'],
                ['column' => 'academic_session_id', 'references' => 'academic_sessions'],
                ['column' => 'class_level_id', 'references' => 'class_levels'],
                ['column' => 'exam_type_id', 'references' => 'exam_types'],
            ],
            'subjects' => [['column' => 'school_id', 'references' => 'schools']],
            'curriculum_subjects' => [
                ['column' => 'curriculum_id', 'references' => 'curricula'],
                ['column' => 'subject_id', 'references' => 'subjects'],
            ],
            'students' => [
                ['column' => 'school_id', 'references' => 'schools'],
                ['column' => 'user_id', 'references' => 'users'],
            ],
            'student_curricula' => [
                ['column' => 'student_id', 'references' => 'students'],
                ['column' => 'curriculum_id', 'references' => 'curricula'],
            ],
            'student_subjects' => [
                ['column' => 'student_curriculum_id', 'references' => 'student_curricula'],
                ['column' => 'curriculum_subject_id', 'references' => 'curriculum_subjects'],
            ],
            'marking_components' => [['column' => 'curriculum_subject_id', 'references' => 'curriculum_subjects']],
            'teachers' => [['column' => 'user_id', 'references' => 'users']],
            'teacher_curriculum_subjects' => [
                ['column' => 'teacher_id', 'references' => 'teachers'],
                ['column' => 'curriculum_subject_id', 'references' => 'curriculum_subjects'],
            ],
            'scores' => [
                ['column' => 'student_id', 'references' => 'students'],
                ['column' => 'curriculum_subject_id', 'references' => 'curriculum_subjects'],
                ['column' => 'marking_component_id', 'references' => 'marking_components'],
                ['column' => 'created_by', 'references' => 'users'],
            ],
            'grade_boundaries' => [
                ['column' => 'school_id', 'references' => 'schools'],
                ['column' => 'exam_type_id', 'references' => 'exam_types'],
            ],
            'subject_result_statuses' => [
                ['column' => 'curriculum_subject_id', 'references' => 'curriculum_subjects'],
                ['column' => 'updated_by', 'references' => 'users'],
            ],
            'student_results' => [
                ['column' => 'student_id', 'references' => 'students'],
                ['column' => 'curriculum_subject_id', 'references' => 'curriculum_subjects'],
                ['column' => 'approved_by', 'references' => 'users'],
            ],
            'audit_logs' => [['column' => 'user_id', 'references' => 'users']],
            'model_has_roles' => [
                ['column' => 'role_id', 'references' => 'roles'],
                ['column' => 'model_id', 'references' => 'users'],
            ],
            'model_has_permissions' => [
                ['column' => 'permission_id', 'references' => 'permissions'],
                ['column' => 'model_id', 'references' => 'users'],
            ],
            'role_has_permissions' => [
                ['column' => 'permission_id', 'references' => 'permissions'],
                ['column' => 'role_id', 'references' => 'roles'],
            ],
        ];

        foreach ($foreignKeyMappings as $table => $foreignKeys) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            foreach ($foreignKeys as $fk) {
                $this->updateForeignKeyColumn($table, $fk['column'], $fk['references']);
            }
        }

        Schema::enableForeignKeyConstraints();
    }

    /**
     * Reverse is not recommended - restore from backup instead.
     */
    public function down(): void
    {
        throw new \Exception('This migration cannot be safely reversed. Please restore from a database backup.');
    }

    private function updateForeignKeyColumn(string $table, string $column, string $referencedTable): void
    {
        // Special case: audit_logs.entity_id is not a real foreign key
        if ($table === 'audit_logs' && $column === 'entity_id') {
            return;
        }

        if (!Schema::hasColumn($table, $column)) {
            return;
        }

        // Check if already converted
        $columnType = $this->getColumnType($table, $column);
        if ($columnType && strpos($columnType, 'bigint') !== false) {
            return;
        }

        // Drop foreign key if it exists
        $this->dropForeignKeyConstraint($table, $column);

        // Special handling for pivot tables: drop primary key if it exists
        $isPivotTable = in_array($table, ['model_has_roles', 'model_has_permissions', 'role_has_permissions']);
        if ($isPivotTable) {
            try {
                DB::statement("ALTER TABLE {$table} DROP PRIMARY KEY");
            } catch (\Exception $e) {
                // PK might already be dropped by previous column update
            }
        }

        // Get UUID to ID mapping
        if (!Schema::hasColumn($referencedTable, 'uuid')) {
            return;
        }

        $mapping = DB::table($referencedTable)->select('uuid', 'id')->get();

        // Update all foreign key values from UUID to ID
        foreach ($mapping as $row) {
            DB::update(
                "UPDATE {$table} SET {$column} = ? WHERE {$column} = ?",
                [$row->id, $row->uuid]
            );
        }

        // Change column type to BIGINT UNSIGNED
        DB::statement("ALTER TABLE {$table} CHANGE COLUMN {$column} {$column} BIGINT UNSIGNED");

        // Re-add foreign key constraint
        $this->addForeignKeyConstraint($table, $column, $referencedTable);

        // Re-add primary key for pivot tables after the LAST column is updated
        if ($isPivotTable) {
            $this->reAddPivotPrimaryKey($table);
        }
    }

    private function dropForeignKeyConstraint(string $table, string $column): void
    {
        $constraints = DB::select(
            "SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
             WHERE TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL 
             AND TABLE_SCHEMA = ?",
            [$table, $column, DB::connection()->getDatabaseName()]
        );

        foreach ($constraints as $constraint) {
            try {
                DB::statement("ALTER TABLE {$table} DROP FOREIGN KEY {$constraint->CONSTRAINT_NAME}");
            } catch (\Exception $e) {
                // Constraint might not exist
            }
        }
    }

    private function addForeignKeyConstraint(string $table, string $column, string $referencedTable): void
    {
        try {
            // Generate a constraint name
            $constraintName = "fk_{$table}_{$column}";
            
            DB::statement(
                "ALTER TABLE {$table} ADD CONSTRAINT {$constraintName} 
                 FOREIGN KEY ({$column}) REFERENCES {$referencedTable}(id) ON DELETE CASCADE"
            );
        } catch (\Exception $e) {
            // Constraint might fail due to data integrity issues - that's okay for now
        }
    }

    private function reAddPivotPrimaryKey(string $table): void
    {
        try {
            if ($table === 'model_has_roles') {
                DB::statement("ALTER TABLE model_has_roles ADD PRIMARY KEY (role_id, model_id, model_type)");
            } elseif ($table === 'model_has_permissions') {
                DB::statement("ALTER TABLE model_has_permissions ADD PRIMARY KEY (permission_id, model_id, model_type)");
            } elseif ($table === 'role_has_permissions') {
                DB::statement("ALTER TABLE role_has_permissions ADD PRIMARY KEY (permission_id, role_id)");
            }
        } catch (\Exception $e) {
            // Might fail if already added or if columns types not matching yet
        }
    }

    private function getColumnType(string $table, string $column): ?string
    {
        try {
            $result = DB::selectOne(
                "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS 
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                [DB::connection()->getDatabaseName(), $table, $column]
            );
            return $result ? strtolower($result->COLUMN_TYPE) : null;
        } catch (\Exception $e) {
            return null;
        }
    }
};
