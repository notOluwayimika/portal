<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Step 1: Add new auto-increment ID and UUID columns to all tables.
     * This migration creates the new structure while keeping old data intact.
     */
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        // Tables to convert - in REVERSE dependency order for dropping FKs
        // Process leaf tables first (those with outgoing FKs), then parent tables
        $tables = [
            // Leaf tables (many FKs pointing out)
            'audit_logs',
            'scores',
            'student_results',
            'subject_result_statuses',
            'teacher_curriculum_subjects',
            'marking_components',
            'student_subjects',
            'student_curricula',
            'students',
            'curriculum_subjects',
            'teacher_curriculum_subjects',
            'teachers',
            'curricula',
            'class_level_arms',
            'grade_boundaries',
            'exam_types',
            'academic_sessions',
            'class_levels',
            'arms',
            'subjects',
            // Core tables (few or no FKs pointing in from already-converted tables)
            'users',
            'schools',
            // Permission/Role tables
            'permissions',
            'roles',
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                $this->convertTableToHybridStructure($table);
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

    private function convertTableToHybridStructure(string $table): void
    {
        // Check if already migrated
        if (Schema::hasColumn($table, 'uuid') && Schema::hasColumn($table, 'id') && 
            $this->getColumnType($table, 'id') === 'bigint') {
            return;
        }

        $idColumn = null;
        if (Schema::hasColumn($table, 'id')) {
            $idColumn = 'id';
        } elseif (Schema::hasColumn($table, 'uuid')) {
            $idColumn = 'uuid';
        }

        if (!$idColumn) {
            return;
        }

        $idColumnInfo = DB::selectOne(
            "SELECT COLUMN_TYPE, COLUMN_KEY FROM INFORMATION_SCHEMA.COLUMNS 
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [DB::connection()->getDatabaseName(), $table, $idColumn]
        );

        // Only proceed if the column is currently a UUID (char or varchar)
        if (!$idColumnInfo || strpos($idColumnInfo->COLUMN_TYPE, 'char') === false) {
            return;
        }

        // Step 1: Drop all foreign key constraints that reference this table
        $this->dropIncomingForeignKeys($table);

        // Step 2: Drop primary key
        DB::statement("ALTER TABLE {$table} DROP PRIMARY KEY");

        // Step 3: Ensure uuid column exists and has the correct name
        if ($idColumn === 'id') {
            DB::statement("ALTER TABLE {$table} CHANGE COLUMN id uuid CHAR(36) NOT NULL");
        }

        // Step 4: Add new auto-increment id as primary key
        DB::statement("ALTER TABLE {$table} ADD COLUMN id BIGINT UNSIGNED AUTO_INCREMENT UNIQUE KEY FIRST");

        // Step 5: Set id as primary key
        DB::statement("ALTER TABLE {$table} DROP INDEX id, ADD PRIMARY KEY (id)");

        // Step 6: Make uuid unique
        try {
            DB::statement("ALTER TABLE {$table} ADD UNIQUE KEY unique_uuid (uuid)");
        } catch (\Exception $e) {
            // Might already be unique
        }
    }

    private function dropIncomingForeignKeys(string $table): void
    {
        // Get all foreign keys that point TO this table using KEY_COLUMN_USAGE
        try {
            $foreignKeys = DB::select(
                "SELECT TABLE_NAME, CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
                 WHERE REFERENCED_TABLE_NAME = ? AND TABLE_SCHEMA = ?",
                [$table, DB::connection()->getDatabaseName()]
            );

            foreach ($foreignKeys as $fk) {
                try {
                    DB::statement("ALTER TABLE {$fk->TABLE_NAME} DROP FOREIGN KEY {$fk->CONSTRAINT_NAME}");
                } catch (\Exception $e) {
                    // Might fail if already dropped
                }
            }
        } catch (\Exception $e) {
            // Ignore errors in dropping foreign keys - they might not exist
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
