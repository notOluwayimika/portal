<?php
// database/migrations/2024_01_01_000015_create_student_results_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('student_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('student_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('curriculum_subject_id')->constrained()->cascadeOnDelete();
            $table->decimal('total_score', 4, 1)->nullable();
            $table->string('grade', 2)->nullable();
            $table->string('status')->default('draft'); // draft | submitted | approved
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('computed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['student_id', 'curriculum_subject_id']);
            $table->index('student_id');
            $table->index('curriculum_subject_id');
        });


    }

    public function down(): void
    {
        Schema::dropIfExists('student_results');
    }
};
