<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * §12.7: a Guardian must link only to Students in the same School. The pivot is
 * written through several paths (Eloquent attach + raw DB::table inserts in
 * GuardianService), so the rule is enforced with BEFORE INSERT/UPDATE triggers —
 * a single choke point every write passes through — rather than app-layer guards
 * scattered across each call site.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['bi' => 'INSERT', 'bu' => 'UPDATE'] as $suffix => $event) {
            DB::unprepared("
                CREATE TRIGGER guardian_student_same_school_{$suffix}
                BEFORE {$event} ON guardian_student
                FOR EACH ROW
                BEGIN
                    IF (SELECT school_id FROM guardians WHERE id = NEW.guardian_id) <>
                       (SELECT school_id FROM students WHERE id = NEW.student_id) THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'guardian and student must belong to the same school';
                    END IF;
                END
            ");
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS guardian_student_same_school_bi');
        DB::unprepared('DROP TRIGGER IF EXISTS guardian_student_same_school_bu');
    }
};
