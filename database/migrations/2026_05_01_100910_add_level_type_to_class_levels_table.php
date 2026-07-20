<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('class_levels', function (Blueprint $table) {
            $table->enum('level_type', ['JSS', 'SSS'])->nullable()->after('name');
        });

        Schema::table('class_level_arms', function (Blueprint $table) {
            // stream_id is nullable — JSS arms have no stream
            $table->foreignId('stream_id')
                ->nullable()
                ->after('arm_id')
                ->constrained('streams')
                ->nullOnDelete();
 
            // Prevent duplicate arm+stream combos within the same class level
            $table->unique(['class_level_id', 'arm_id', 'stream_id'], 'unique_class_level_arm_stream');
        });

        // Symmetry with down(), and it must come AFTER the unique above is recreated.
        // down() adds idx_cla_class_level_id_fk so the class_level_id FK keeps a
        // backing index once the composite unique is gone. On the way back up that
        // helper is, for a moment, the FK's ONLY index — dropping it first fails with
        // 1553. Recreate the composite unique, then the helper is redundant and can go.
        // Guarded on existence because a FRESH database never had it.
        //
        // The first version of this fix dropped it too early and the four-path audit
        // caught it on the RE-UPGRADE leg — the exact leg migrate:fresh cannot see.
        if (DB::selectOne("SELECT 1 AS x FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'class_level_arms' AND INDEX_NAME = 'idx_cla_class_level_id_fk' LIMIT 1")) {
            DB::statement('ALTER TABLE `class_level_arms` DROP INDEX `idx_cla_class_level_id_fk`');
        }

        Schema::table('student_curricula', function (Blueprint $table) {
            // Track where the student currently stands in this curriculum
            $table->enum('status', ['active', 'promoted', 'repeated', 'withdrawn'])
                ->default('active')
                ->after('curriculum_id');
 
            // Self-referencing FK — points to the next student_curricula row after promotion.
            // NULL means this is the student's current (active) row.
            $table->foreignId('promoted_to_id')
                ->nullable()
                ->after('status')
                ->constrained('student_curricula')
                ->nullOnDelete();
 
            // Fast lookup: "what is this student's current class?"
            $table->index(['student_id', 'status'], 'idx_student_curricula_student_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('class_levels', function (Blueprint $table) {
            // No dropIndex here: up() adds the `level_type` COLUMN and never indexes
            // it, so `class_levels_level_type_index` has never existed and dropping it
            // aborted this whole down() with MySQL 1091. Found by the Phase-1
            // four-path migration audit — migrate:fresh never calls down().
            $table->dropColumn('level_type');
        });

        // Four separate statements, in this exact order. Found by the Phase-1
        // four-path migration audit; the original single-closure version
        // (dropUnique → dropForeign → dropColumn) aborted the whole down().
        //
        // The subtlety is not the stream_id FK — it is that
        // `fk_class_level_arms_class_level_id` has NO dedicated index. Its backing
        // index is the LEFTMOST PREFIX of unique_class_level_arm_stream
        // (class_level_id, arm_id, stream_id), so MySQL refuses to drop that unique
        // while the class_level_id FK exists (1553), and that FK belongs to an earlier
        // migration this down() must not touch. So we hand class_level_id its own
        // index first, then the unique is free to go.
        Schema::table('class_level_arms', function (Blueprint $table) {
            $table->dropForeign(['stream_id']);
        });
        Schema::table('class_level_arms', function (Blueprint $table) {
            $table->index('class_level_id', 'idx_cla_class_level_id_fk');
        });
        Schema::table('class_level_arms', function (Blueprint $table) {
            $table->dropUnique('unique_class_level_arm_stream');
            $table->dropColumn('stream_id');
        });

        Schema::table('student_curricula', function (Blueprint $table) {
            $table->dropIndex('idx_student_curricula_student_status');
            $table->dropForeign(['promoted_to_id']);
            $table->dropColumn(['status', 'promoted_to_id']);
        });
    }
};
