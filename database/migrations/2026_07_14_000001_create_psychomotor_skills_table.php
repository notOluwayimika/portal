<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Psychomotor skills assessments — mirrors behavioral_assessments but
     * only applies to enrollments whose curriculum uses categorical grading.
     */
    public function up(): void
    {
        Schema::create('psychomotor_skills', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('student_curriculum_id')->constrained('student_curricula')->cascadeOnDelete();
            $table->foreignId('assessed_by')->constrained('users')->cascadeOnDelete();

            foreach ([
                'drawing_colouring', 'cutting_pasting', 'puzzles_building', 'climbing_sliding',
            ] as $category) {
                $table->enum($category, ['A', 'B', 'C', 'D', 'E']);
            }

            $table->text('comment')->nullable();
            $table->foreignId('assessment_term_id')->constrained('terms')->cascadeOnDelete();
            $table->timestampsTz();

            $table->unique(['student_curriculum_id', 'assessment_term_id'], 'psychomotor_skills_curriculum_term_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('psychomotor_skills');
    }
};
