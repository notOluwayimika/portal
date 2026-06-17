<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('behavioral_assessments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('student_curriculum_id')->constrained('student_curricula')->cascadeOnDelete();
            $table->foreignId('assessed_by')->constrained('users')->cascadeOnDelete();

            foreach ([
                'punctuality',
                'mental_alertness',
                'respect',
                'neatness',
                'politeness',
                'honesty',
                'relationship_with_peers',
                'teamwork',
                'perseverance',
            ] as $pillar) {
                $table->enum($pillar, ['A', 'B', 'C', 'D', 'E']);
            }

            $table->text('comment')->nullable();
            $table->foreignId('assessment_term_id')->constrained('terms')->cascadeOnDelete();
            $table->timestampsTz();

            $table->unique(['student_curriculum_id', 'assessment_term_id'], 'behavioral_assessments_curriculum_term_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('behavioral_assessments');
    }
};
