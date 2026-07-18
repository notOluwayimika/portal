<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * students.admission_number and teachers.staff_number -> NOT NULL (1.4b).
 *
 * Since generation now happens BEFORE the insert (in `creating`, via Shared
 * Sequences), the identifier is always set on every normal write, so the column
 * can be NOT NULL. This makes a null identifier an UNREPRESENTABLE state at the
 * database — the deliberate, event-independent guard that catches EVERY
 * null-producing path (saveQuietly / createQuietly suppressing `creating`, a raw
 * builder insert, any future bypass), rather than relying on the incidental
 * uuid-NOT-NULL constraint to fail such writes for an unrelated reason.
 *
 * MODIFY preserves the composite unique(school_id, {number}) index (type
 * unchanged). Does NOT backfill: if any NULL rows exist it aborts so they are
 * resolved deliberately (a pre-existing generation failure), not papered over.
 */
return new class extends Migration
{
    public function up(): void
    {
        $nullAdmissions = DB::table('students')->whereNull('admission_number')->count();
        $nullStaff = DB::table('teachers')->whereNull('staff_number')->count();

        if ($nullAdmissions > 0 || $nullStaff > 0) {
            throw new RuntimeException(
                "Refusing to set identifier columns NOT NULL: {$nullAdmissions} student(s) have a NULL "
                ."admission_number and {$nullStaff} teacher(s) have a NULL staff_number. Backfill them "
                .'first (assign identifiers) — this migration does not backfill.'
            );
        }

        DB::statement('ALTER TABLE students MODIFY admission_number VARCHAR(255) NOT NULL');
        DB::statement('ALTER TABLE teachers MODIFY staff_number VARCHAR(255) NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE students MODIFY admission_number VARCHAR(255) NULL');
        DB::statement('ALTER TABLE teachers MODIFY staff_number VARCHAR(255) NULL');
    }
};
