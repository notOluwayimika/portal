<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widen `class_level_arm_teacher.role` to admit `key_stage_coordinator`.
 *
 * The column is a MySQL ENUM, so TeacherAssignmentRoleEnum gaining a case is not
 * enough — the database rejects the value with "Data truncated for column 'role'",
 * which is how the new role's feature test found this.
 *
 * Raw ALTER rather than a Doctrine column change: Laravel's `change()` on an enum
 * needs doctrine/dbal and rewrites the column definition from scratch, which risks
 * silently dropping the NOT NULL or the position. Spelling the enum out keeps the
 * definition explicit and reviewable, and the down() below is its exact inverse.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE class_level_arm_teacher MODIFY COLUMN role
             ENUM('boarding_parent','form_teacher','head_of_school','key_stage_coordinator') NOT NULL"
        );
    }

    public function down(): void
    {
        // Refuse rather than silently truncate: rolling back with coordinators
        // assigned would turn their rows into invalid values (MySQL would reject
        // the ALTER outright in strict mode, or blank them in a lax one). Unassign
        // them first — the data is the operator's to resolve, not this migration's.
        $assigned = DB::table('class_level_arm_teacher')
            ->where('role', 'key_stage_coordinator')
            ->count();

        if ($assigned > 0) {
            throw new RuntimeException(
                "Refusing to narrow class_level_arm_teacher.role: {$assigned} key_stage_coordinator "
                .'assignment(s) exist. Remove them via /setup/teacher-assignments, then roll back.'
            );
        }

        DB::statement(
            "ALTER TABLE class_level_arm_teacher MODIFY COLUMN role
             ENUM('boarding_parent','form_teacher','head_of_school') NOT NULL"
        );
    }
};
