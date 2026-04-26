<?php
// database/migrations/2024_01_01_000010_create_student_tables.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('admission_number')->nullable();
            $table->text('photo')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['school_id', 'admission_number']);
        });

        Schema::create('student_curricula', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('student_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('curriculum_id')->constrained('curricula')->cascadeOnDelete();
            $table->timestampsTz();

            $table->unique(['student_id', 'curriculum_id']);
        });

        Schema::create('student_subjects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('student_curriculum_id')->constrained('student_curricula')->cascadeOnDelete();
            $table->foreignUuid('curriculum_subject_id')->constrained('curriculum_subjects')->cascadeOnDelete();
            $table->timestampsTz();

            $table->unique(
                ['student_curriculum_id', 'curriculum_subject_id'],
                'student_subject_unique'
            );
            $table->index('student_curriculum_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_subjects');
        Schema::dropIfExists('student_curricula');
        Schema::dropIfExists('students');
    }
};
