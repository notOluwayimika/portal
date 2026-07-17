<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * students.school_id -> NOT NULL.
 *
 * students is the only nullable BelongsToSchool table (§5.2), and Student is
 * Finance's central upstream (§12.6 indexes (school_id, status) for billing
 * eligibility) — a Student belonging to no School is a billable person with no
 * legal entity. The BelongsToSchool auto-fill now works (halting-event fix,
 * 1.3b.1), so this closes the last silent-null path.
 *
 * A plain MODIFY of nullability preserves the existing fk_students_school_id
 * foreign key (type is unchanged). This migration does NOT backfill: if any NULL
 * rows exist it aborts, so they are resolved deliberately, not silently.
 */
return new class extends Migration
{
    public function up(): void
    {
        $nulls = DB::table('students')->whereNull('school_id')->count();

        if ($nulls > 0) {
            throw new RuntimeException(
                "Refusing to set students.school_id NOT NULL: {$nulls} row(s) have a NULL school_id. "
                .'Resolve them first — this migration does not backfill.'
            );
        }

        DB::statement('ALTER TABLE students MODIFY school_id BIGINT UNSIGNED NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE students MODIFY school_id BIGINT UNSIGNED NULL');
    }
};
