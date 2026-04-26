<?php
// database/migrations/2024_01_01_000014_create_subject_result_statuses_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('subject_result_statuses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('curriculum_subject_id')->constrained()->cascadeOnDelete()->unique();
            $table->string('status')->default('draft'); // draft | submitted | approved | rejected
            $table->text('rejection_reason')->nullable();
            $table->foreignUuid('updated_by')->constrained('users');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subject_result_statuses');
    }
};
