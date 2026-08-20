<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Promotion config, part 2 of 3 — the three per-class-level answers that are single-valued.
 *
 * Everything here is a scalar property OF a class level, which is why it is a column rather than a
 * row in a side table: `class_levels.grading_scheme_id` already sets that precedent, and
 * `default_exam_type_id` is deliberately symmetric with it.
 *
 * 1. next_class_level_id — WHERE a pupil goes at end of year. NULL means terminal: the graduating
 *    year, out of which nobody is promoted. Explicit, not `order + 1` arithmetic, because `order` is a
 *    display/sort field with no uniqueness or contiguity guarantee — two class levels may share an
 *    order, and a school that deletes a level leaves a hole that arithmetic would silently jump.
 *
 * 2. default_exam_type_id — WHICH exam type the target curriculum carries when the pupil's current one
 *    does not exist in the target class level. NOT simply "the class level's exam type": the data says
 *    a class level can run SEVERAL. In school 1 today, Year 10 and Year 11 each run both BSS Grading
 *    and WAEC Grading, while Year 12 runs WAEC alone. So the set lives in `class_level_exam_types`
 *    (part 3) and resolution is: carry the source curriculum's exam type if the target class level
 *    runs it, otherwise fall back to THIS column. That resolves the real case the set alone cannot —
 *    a Year 11 BSS pupil moving into a Year 12 that has no BSS.
 *
 *    Nullable, and nullable is meaningful: a class level with exactly one exam type needs no default,
 *    and a NULL here with no set membership match is a hard stop for the job to report rather than a
 *    silent guess about which certificate a pupil sits.
 *
 * 3. arm_distribution_strategy — HOW to place a pupil when neither the explicit arm map nor a label
 *    match resolves an arm. Two values only, and the omissions are deliberate:
 *      • round_robin  — deterministic placement, spread across the target level's arms.
 *      • explicit_only — do not auto-place; the job leaves the pupil for a human. For a school that
 *        streams by ability and would rather have a gap than a wrong arm.
 *    'random' is NOT offered: a re-run would re-roll and produce a second live episode. 'FIFO' is not
 *    offered either — filling arm 1 before arm 2 requires an arm capacity, and no such column exists.
 *    Constrained by CHECK rather than by an enum column, so adding a value later is an additive
 *    migration instead of a table rebuild, and so the constraint is visible in SHOW CREATE TABLE.
 *
 * ON DELETE RESTRICT on both FKs, and the consequence is intended. `class_levels.school_id` is ON
 * DELETE CASCADE, so a school hard-delete cascades into its class levels — and a RESTRICT child
 * blocks that cascade while any progression chain still exists. That is the correct outcome and the
 * established house answer (finance_invoices, student_subjects and the promoted_to_id FK all RESTRICT
 * for exactly this reason: refuse a destructive cascade rather than silently shred the graph). No
 * hard-delete path for a School or a ClassLevel exists in the controllers today, so this blocks
 * nothing that currently runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_levels', function (Blueprint $table) {
            $table->unsignedBigInteger('next_class_level_id')->nullable()->after('order');
            $table->unsignedBigInteger('default_exam_type_id')->nullable()->after('next_class_level_id');
            $table->string('arm_distribution_strategy', 32)
                ->default('round_robin')
                ->after('default_exam_type_id');
        });

        // Composite, for the isolation reason the part-1 migration states at length. Self-referencing
        // on the first one — the same shape student_curricula.promoted_to_id uses.
        DB::statement(
            'ALTER TABLE class_levels
                ADD CONSTRAINT class_levels_next_level_school_foreign
                FOREIGN KEY (next_class_level_id, school_id)
                REFERENCES class_levels (id, school_id)
                ON DELETE RESTRICT'
        );

        DB::statement(
            'ALTER TABLE class_levels
                ADD CONSTRAINT class_levels_default_exam_type_school_foreign
                FOREIGN KEY (default_exam_type_id, school_id)
                REFERENCES exam_types (id, school_id)
                ON DELETE RESTRICT'
        );

        DB::statement(
            "ALTER TABLE class_levels
                ADD CONSTRAINT class_levels_arm_distribution_strategy_check
                CHECK (arm_distribution_strategy IN ('round_robin', 'explicit_only'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE class_levels DROP CONSTRAINT class_levels_arm_distribution_strategy_check');
        DB::statement('ALTER TABLE class_levels DROP FOREIGN KEY class_levels_default_exam_type_school_foreign');
        DB::statement('ALTER TABLE class_levels DROP FOREIGN KEY class_levels_next_level_school_foreign');

        // MySQL leaves a FK's backing index behind on DROP FOREIGN KEY, and these were auto-named by
        // the engine from the constraint name. Dropping them explicitly is what lets a re-up() recreate
        // the FKs cleanly — the same hazard 2026_07_29_130000 documents.
        foreach (['class_levels_default_exam_type_school_foreign', 'class_levels_next_level_school_foreign'] as $index) {
            if ($this->indexExists('class_levels', $index)) {
                DB::statement("ALTER TABLE class_levels DROP INDEX {$index}");
            }
        }

        Schema::table('class_levels', function (Blueprint $table) {
            $table->dropColumn(['arm_distribution_strategy', 'default_exam_type_id', 'next_class_level_id']);
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
            [$table, $index]
        ) !== [];
    }
};
