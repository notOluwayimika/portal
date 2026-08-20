<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Promotion config, part 3 of 3 — the three answers that are set-valued, so cannot be columns.
 *
 * ALL THREE ARE KEYED ON TERM ORDER / STRUCTURE, NEVER ON A CONCRETE TERM ROW. A `term_id` would tie
 * the config to one academic session and force it to be re-entered every year — the config would rot
 * the moment the session rolled, which is precisely when the migration jobs need it. `terms` already
 * carries `UNIQUE (academic_session_id, order)`, so "term slot 3" is a stable, session-independent
 * coordinate. This is the single most important shape decision in Part 1.
 *
 * 1. class_level_term_participation — WHICH term slots a class level runs, and whether each is CCM.
 *    Presence of the row IS participation; there is no `participates` boolean, because a row that says
 *    "does not participate" and an absent row would be two encodings of one fact. A class doing only
 *    terms 1-2 simply has no row for slot 3, and the end-of-term job no-ops for it.
 *
 *    `is_ccm` lives HERE rather than on class_levels because the brief's requirement is participation
 *    at (class level, term slot) granularity — a class may enter the CCM variant of term 1 and not of
 *    term 2. It composes with MoveFromCcmJob, which separately moves CCM -> non-CCM WITHIN a term:
 *    this column decides which variant the pupil ARRIVES in, that job decides when they leave it.
 *
 * 2. class_level_exam_types — the SET of exam types a class level runs. Set-valued because the data is:
 *    Year 10 and Year 11 in school 1 each run BSS Grading and WAEC Grading. Read together with
 *    class_levels.default_exam_type_id (part 2): carry the pupil's current exam type if it is in this
 *    set, else fall back to the default. Split this way because "is X allowed here" and "which one if
 *    not" are different questions, and each is then exactly enforceable — a UNIQUE for membership, a
 *    single column for the default. Encoding the default as an `is_default` flag on this table would
 *    need a partial unique index (MySQL has none) or a functional one, to express "at most one".
 *
 * 3. class_level_arm_progressions — the EXPLICIT source-arm -> target-arm map, consulted before any
 *    label matching. UNIQUE on the source, so one source arm has at most one mapped target and the
 *    resolution is deterministic.
 *
 *    LABEL MATCHING (the fallback this map overrides) MUST RESOLVE stream_id, not just the arm label.
 *    `class_level_arms` is UNIQUE on (class_level_id, arm_id, stream_id), so arm "B" can legitimately
 *    exist more than once in one class level, differing only by stream. Every stream_id is NULL in dev
 *    today, so a label-only match happens to work and would keep working right up until the first
 *    school configures streams — latent, not live. Recorded here because the schema is where the
 *    ambiguity is visible; the resolution itself belongs to the Part 3 job.
 *
 * Composite `(x, school_id)` FKs throughout, for the reason the part-1 migration sets out. ON DELETE
 * CASCADE here, NOT the RESTRICT used in part 2, and the difference is deliberate: these rows are
 * config ABOUT a class level or arm, meaningless once it is gone, so they should follow it. Part 2's
 * columns are a reference TO another class level, where a cascade would delete the pointer's owner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_level_term_participation', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('class_level_id');

            // The term's `order`, not its id — see the docblock. tinyint: a session has a handful of
            // terms, and `terms.order` is small-integer data in every school here.
            $table->unsignedTinyInteger('term_order');

            $table->boolean('is_ccm')->default(false);
            $table->timestamps();

            $table->unique(['class_level_id', 'term_order'], 'class_level_term_participation_unique');
        });

        DB::statement(
            'ALTER TABLE class_level_term_participation
                ADD CONSTRAINT class_level_term_participation_school_foreign
                FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE CASCADE'
        );
        DB::statement(
            'ALTER TABLE class_level_term_participation
                ADD CONSTRAINT class_level_term_participation_level_school_foreign
                FOREIGN KEY (class_level_id, school_id)
                REFERENCES class_levels (id, school_id) ON DELETE CASCADE'
        );

        Schema::create('class_level_exam_types', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('class_level_id');
            $table->unsignedBigInteger('exam_type_id');
            $table->timestamps();

            $table->unique(['class_level_id', 'exam_type_id'], 'class_level_exam_types_unique');
        });

        DB::statement(
            'ALTER TABLE class_level_exam_types
                ADD CONSTRAINT class_level_exam_types_school_foreign
                FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE CASCADE'
        );
        DB::statement(
            'ALTER TABLE class_level_exam_types
                ADD CONSTRAINT class_level_exam_types_level_school_foreign
                FOREIGN KEY (class_level_id, school_id)
                REFERENCES class_levels (id, school_id) ON DELETE CASCADE'
        );
        DB::statement(
            'ALTER TABLE class_level_exam_types
                ADD CONSTRAINT class_level_exam_types_exam_type_school_foreign
                FOREIGN KEY (exam_type_id, school_id)
                REFERENCES exam_types (id, school_id) ON DELETE CASCADE'
        );

        Schema::create('class_level_arm_progressions', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('source_class_level_arm_id');
            $table->unsignedBigInteger('target_class_level_arm_id');
            $table->timestamps();

            // One mapped target per source arm — the map must not be able to answer twice.
            $table->unique('source_class_level_arm_id', 'class_level_arm_progressions_source_unique');
        });

        DB::statement(
            'ALTER TABLE class_level_arm_progressions
                ADD CONSTRAINT class_level_arm_progressions_school_foreign
                FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE CASCADE'
        );
        DB::statement(
            'ALTER TABLE class_level_arm_progressions
                ADD CONSTRAINT class_level_arm_progressions_source_school_foreign
                FOREIGN KEY (source_class_level_arm_id, school_id)
                REFERENCES class_level_arms (id, school_id) ON DELETE CASCADE'
        );
        DB::statement(
            'ALTER TABLE class_level_arm_progressions
                ADD CONSTRAINT class_level_arm_progressions_target_school_foreign
                FOREIGN KEY (target_class_level_arm_id, school_id)
                REFERENCES class_level_arms (id, school_id) ON DELETE CASCADE'
        );
    }

    public function down(): void
    {
        // Reverse creation order. Dropping the table drops its FKs and their backing indexes with it,
        // so unlike part 2 (which ALTERs an existing table) there is no index residue to clean up.
        Schema::dropIfExists('class_level_arm_progressions');
        Schema::dropIfExists('class_level_exam_types');
        Schema::dropIfExists('class_level_term_participation');
    }
};
