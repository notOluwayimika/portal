<?php
// database/migrations/2024_01_01_000012_create_scores_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('scores', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('student_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('curriculum_subject_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('marking_component_id')->constrained()->cascadeOnDelete();
            $table->decimal('score', 4, 1);
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestampsTz();

            // Core constraint: one score per student per component
            $table->unique(['student_id', 'marking_component_id']);
            $table->index(['student_id', 'curriculum_subject_id']);
        });

        // Score range CHECK (SQLite doesn't support ALTER TABLE ADD CONSTRAINT)
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE scores ADD CONSTRAINT scores_range CHECK (score >= 0 AND score <= 100)');
        }


    }

    public function down(): void
    {
        Schema::dropIfExists('scores');
    }
};
