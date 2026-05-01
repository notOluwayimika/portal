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
            $table->dropIndex(['level_type']);
            $table->dropColumn('level_type');
        });

        Schema::table('class_level_arms', function (Blueprint $table) {
            $table->dropUnique('unique_class_level_arm_stream');
            $table->dropForeign(['stream_id']);
            $table->dropColumn('stream_id');
        });

        Schema::table('student_curricula', function (Blueprint $table) {
            $table->dropIndex('idx_student_curricula_student_status');
            $table->dropForeign(['promoted_to_id']);
            $table->dropColumn(['status', 'promoted_to_id']);
        });
    }
};
