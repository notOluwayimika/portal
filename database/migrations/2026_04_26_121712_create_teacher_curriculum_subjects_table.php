<?php
// database/migrations/2024_01_01_000011_create_teacher_curriculum_subjects_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('teacher_curriculum_subjects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('teacher_id')->constrained('teachers')->cascadeOnDelete();
            $table->foreignUuid('curriculum_subject_id')->constrained()->cascadeOnDelete();
            $table->timestampsTz();

            $table->unique(
                ['teacher_id', 'curriculum_subject_id'],
                'teacher_curr_sub_unique'
            );
            $table->index('teacher_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_curriculum_subjects');
    }
};
