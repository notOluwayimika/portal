<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Change student_subjects FKs from CASCADE to RESTRICT to prevent accidental academic record deletion.
return new class extends Migration {
    public function up(): void
    {
        $db = DB::connection()->getDatabaseName();

        foreach (['student_curriculum_id', 'curriculum_subject_id'] as $column) {
            $referencedTable = $column === 'student_curriculum_id' ? 'student_curricula' : 'curriculum_subjects';

            $constraint = DB::selectOne(
                "SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'student_subjects'
                   AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL
                 LIMIT 1",
                [$db, $column]
            );

            if ($constraint) {
                DB::statement("ALTER TABLE student_subjects DROP FOREIGN KEY `{$constraint->CONSTRAINT_NAME}`");
            }

            DB::statement(
                "ALTER TABLE student_subjects ADD CONSTRAINT `fk_ss_{$column}`
                 FOREIGN KEY (`{$column}`) REFERENCES `{$referencedTable}`(`id`) ON DELETE RESTRICT"
            );
        }
    }

    public function down(): void
    {
        $db = DB::connection()->getDatabaseName();

        foreach (['student_curriculum_id' => 'student_curricula', 'curriculum_subject_id' => 'curriculum_subjects'] as $column => $referencedTable) {
            $constraint = DB::selectOne(
                "SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'student_subjects'
                   AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL
                 LIMIT 1",
                [$db, $column]
            );

            if ($constraint) {
                DB::statement("ALTER TABLE student_subjects DROP FOREIGN KEY `{$constraint->CONSTRAINT_NAME}`");
            }

            DB::statement(
                "ALTER TABLE student_subjects ADD CONSTRAINT `fk_ss_{$column}_cascade`
                 FOREIGN KEY (`{$column}`) REFERENCES `{$referencedTable}`(`id`) ON DELETE CASCADE"
            );
        }
    }
};
