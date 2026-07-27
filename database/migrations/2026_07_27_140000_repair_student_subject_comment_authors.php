<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repair `student_subjects.commented_by`, which holds users.id where it should hold teachers.id.
 *
 * `commented_by` is a foreign key to `teachers.id` — `StudentSubject::commentedBy()` is
 * `belongsTo(Teacher::class, 'commented_by')` and StudentSubjectResource renders
 * `commentedBy?->full_name`. But StudentSubjectService::storeComment wrote `$performedBy->id`,
 * which is a users.id. Fixed in the same change; this repairs what that already wrote.
 *
 * The damage was invisible wherever the two id spaces happened to overlap: a users.id that also
 * exists as a teachers.id passes the foreign key and the comment is then displayed against a
 * teacher who never wrote it. (Where they did NOT overlap, the write failed outright with an
 * opaque "Database error", which is how the bug was finally noticed.)
 *
 * THE REMAP IS DETERMINISTIC, NOT A GUESS. `storeComment` is the only writer of this column, so
 * every non-null value is a users.id; and `teachers.user_id` is unique, so each one maps to at
 * most one teacher. The mapping is therefore exact rather than heuristic — and reversible, which
 * is why down() can invert it.
 *
 * Values with no matching teacher (a commenter who has no teacher record — an admin, or a teacher
 * since deleted) become NULL. They cannot be represented in a teachers foreign key at all, and
 * leaving them would keep pointing at an unrelated teacher. down() cannot restore those few
 * values; that is the one lossy part and it is lossy in the safe direction.
 *
 * NOT IDEMPOTENT — DO NOT RUN up() TWICE. It translates one id space into another, so a second
 * pass would read already-correct teachers.id values as though they were users.id: each one
 * either maps to whichever teacher happens to have that number as their user_id, or matches
 * nothing and is nulled. Laravel's migration ledger prevents a re-run in normal operation; this
 * note is for anyone tempted to invoke it by hand. down() then up() IS safe — they are exact
 * inverses (verified round-trip on real data: 7 → 9 → 7).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ONE pass, LEFT JOIN: matched rows take the teacher's id, unmatched rows become NULL.
        //
        // It has to be one statement. A remap followed by a separate "null the leftovers" cannot
        // tell the two apart afterwards — an unmapped row still holds a users.id that the foreign
        // key guarantees is SOME valid teachers.id, so nothing distinguishes it from a row that
        // was correctly remapped. MySQL updates each row of a multi-table UPDATE at most once, so
        // there is no double-mapping to worry about either.
        DB::statement('
            UPDATE student_subjects AS ss
            LEFT JOIN teachers AS t ON t.user_id = ss.commented_by
            SET ss.commented_by = t.id
            WHERE ss.commented_by IS NOT NULL
        ');
    }

    public function down(): void
    {
        // The inverse mapping: teachers.id back to the users.id the buggy code would have stored.
        // Rows this migration nulled stay null — the original value is not recoverable, and it was
        // not a usable reference in the first place.
        DB::statement('
            UPDATE student_subjects AS ss
            JOIN teachers AS t ON t.id = ss.commented_by
            SET ss.commented_by = t.user_id
            WHERE ss.commented_by IS NOT NULL AND t.user_id IS NOT NULL
        ');
    }
};
