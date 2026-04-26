<?php
// database/migrations/2024_01_01_000013_create_grade_boundaries_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('grade_boundaries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('exam_type_id')->nullable()->constrained()->nullOnDelete(); // NULL = school default
            $table->decimal('min_score', 5, 2);
            $table->decimal('max_score', 5, 2);
            $table->string('grade', 2); // A, B, C, D, E, F
            $table->string('label', 50); // Distinction, Pass, Fail
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_boundaries');
    }
};
