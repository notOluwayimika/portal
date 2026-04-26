<?php
// database/migrations/2024_01_01_000009_create_marking_components_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('marking_components', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('curriculum_subject_id')->constrained()->cascadeOnDelete();
            $table->string('name'); // e.g. "CA Test", "Exam"
            $table->decimal('weight', 4, 3); // e.g. 0.300, 0.700
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marking_components');
    }
};
