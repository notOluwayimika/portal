<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('curricula', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('academic_session_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('class_level_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('exam_type_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('term'); // 1, 2, or 3
            $table->unsignedSmallInteger('min_subjects')->default(1);
            $table->timestampTz('registration_deadline');
            $table->timestampTz('result_visible_at')->nullable();
            $table->enum('status', ['draft', 'active', 'closed'])->default('draft');
            $table->timestampsTz();

            $table->index(['school_id', 'academic_session_id', 'status']);
            $table->unique(['school_id', 'academic_session_id', 'class_level_id', 'term', 'exam_type_id'], 'curricula_unique_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('curricula');
    }
};
